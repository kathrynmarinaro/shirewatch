<?php
/* Photos and documents: storing them, queueing them, reading them back.
 *
 * ---------------------------------------------------------------------------
 * NOTHING OUTSIDE THIS FILE WRITES SQL AGAINST media, AND NOTHING OUTSIDE IT
 * WRITES A FILE INTO public/uploads/.
 * ---------------------------------------------------------------------------
 *
 * Two owners need this — an issue's timeline photo and a service record's
 * batch of invoices — and they need it to behave identically. Foundation owns
 * it so there is one upload path, one validation rule and one place where a
 * file gets deleted.
 *
 * ---------------------------------------------------------------------------
 * ONE UPLOAD PATH: THE QUEUE (DELEGATION-PLAN.md §2.8).
 * ---------------------------------------------------------------------------
 *
 * media_store() saves the original, writes a 'pending' row and returns. It
 * does NO image work. The browser then drains the queue via api/worker.php,
 * and cron/process-queue.php sweeps whatever a closed tab left behind.
 *
 * Resizing inline would be simpler and is the wrong trade: a ten-file service
 * record batch is 10-20 seconds of Imagick, which is a real risk of hitting
 * max_execution_time on shared hosting — and the failure mode there is a
 * half-uploaded batch with no error anybody can act on. It is also better for
 * the single-photo capture flow, not merely tolerable: the phone gets its
 * response back before the first resize starts.
 *
 * ---------------------------------------------------------------------------
 * ORIGINALS ARE KEPT. THIS IS THE DELIBERATE DIVERGENCE FROM THE GALLERY.
 * ---------------------------------------------------------------------------
 *
 * The Inspiration Gallery reaps originals once it has derived from them, and
 * has a cron sweep to catch the ones a closed tab stranded. There is no such
 * sweep here and there must not be one.
 *
 * Its images are visual references; a 1600px WebP is as good as the thing it
 * came from. These are EVIDENCE. The reason a photo of a crack exists is to be
 * put beside a photo of the same crack taken eight months later, possibly in
 * front of an insurance adjuster or a contractor who disagrees. A re-encoded
 * 1600px copy is not what you want to be holding at that point.
 *
 * ---------------------------------------------------------------------------
 * PHOTOS ARE DERIVED. DOCUMENTS ARE NOT (§2.9).
 * ---------------------------------------------------------------------------
 *
 * A PDF has nothing to resize, so it is stored under uploads/docs/ and goes
 * straight to 'ready' — it never enters the queue, and the worker never sees
 * it. Everything else about it is the same: sniffed by bytes, given a
 * generated filename, size-capped.
 *
 * ---------------------------------------------------------------------------
 * THE CLIENT'S FILENAME IS NEVER A PATH.
 * ---------------------------------------------------------------------------
 *
 * Every stored file is named <16 hex chars>.<extension we chose>. The uploaded
 * name is kept in media.original_filename for DISPLAY ONLY and never reaches
 * the filesystem, and the extension comes from sniffing the bytes, never from
 * the name or the browser-supplied MIME type. lib/imageproc.php is where that
 * is enforced. */

declare(strict_types=1);

require_once __DIR__ . '/imageproc.php';

/** PDFs, and nothing else. The one document type worth supporting. */
const MEDIA_DOC_TYPES = array('application/pdf' => 'pdf');

/**
 * The three columns exactly one of which is non-NULL on any media row.
 *
 * A WHITELIST, and the reason the functions below can interpolate an owner
 * column into SQL. An unknown owner type returns null rather than a column
 * name, so nothing a caller passes can become SQL.
 */
function media_owner_column(string $type): ?string
{
    $map = array(
        'issue'        => 'issue_id',
        'issue_update' => 'issue_update_id',
        'record'       => 'service_record_id',
    );
    return $map[$type] ?? null;
}

/* --------------------------------------------------------------- storing */

/**
 * Why this upload cannot be accepted, or null if it can.
 *
 * Takes one row from media_normalize_files(). Checks the transport-level
 * failures first, because PHP reports "the file was too big for
 * upload_max_filesize" as an error CODE rather than as a size, and a caller
 * that only compares sizes reports that case as an empty file.
 */
function media_reject_reason(array $file): ?string
{
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'too_large';
        case UPLOAD_ERR_NO_FILE:
            return 'empty_file';
        case UPLOAD_ERR_PARTIAL:
            return 'incomplete_upload';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'server_cannot_write';
        default:
            return 'upload_failed';
    }

    /* The one check that cannot be skipped: without it, a crafted request can
     * name any readable path on the server and have it copied into uploads/. */
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'upload_failed';
    }
    if ($file['size'] <= 0) {
        return 'empty_file';
    }
    if ($file['size'] > imageproc_max_bytes()) {
        return 'too_large';
    }
    return null;
}

/**
 * Flatten PHP's transposed $_FILES['files'] into one row per file.
 * Also tolerates a single (non-array) upload under the same key, so the
 * single-photo capture flow and the batch uploader share this path.
 *
 * @return list<array{name:string,tmp_name:string,size:int,error:int}>
 */
function media_normalize_files(array $field): array
{
    if (!is_array($field['name'] ?? null)) {
        $field = array_map(static fn($v): array => array($v), $field);
    }

    $out   = array();
    $count = count($field['name']);
    for ($i = 0; $i < $count; $i++) {
        $out[] = array(
            'name'     => (string) ($field['name'][$i] ?? ''),
            'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''),
            'size'     => (int) ($field['size'][$i] ?? 0),
            'error'    => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        );
    }
    return $out;
}

/**
 * A client filename, made safe to show on a page.
 *
 * Basename first, so a name carrying a path shows as a name. Never used to
 * build a path — see this file's header.
 */
function media_display_name(string $raw): string
{
    $name = basename(str_replace('\\', '/', $raw));
    $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
    $name = trim($name);
    return $name === '' ? 'file' : mb_substr($name, 0, 255, 'UTF-8');
}

/**
 * Store one uploaded file and create its media row. Returns the new id.
 *
 * Photos land as 'pending' with only original_path set; the queue fills in the
 * derivatives. Documents land as 'ready' immediately — there is nothing to do
 * to a PDF.
 *
 * @param array{name:string,tmp_name:string,size:int,error:int} $file
 * @param string $ownerType 'issue' | 'issue_update' | 'record'
 * @throws RuntimeException with a machine-readable reason, for the caller's
 *         per-file rejection list. A batch must never fail as a whole because
 *         one file in it was a screenshot of a screenshot.
 */
function media_store(array $file, string $ownerType, int $ownerId): int
{
    $column = media_owner_column($ownerType);
    if ($column === null || $ownerId <= 0) {
        throw new RuntimeException('bad_owner');
    }

    $reason = media_reject_reason($file);
    if ($reason !== null) {
        throw new RuntimeException($reason);
    }

    /* Identified by BYTES. The client's name and MIME are echoed back to the
     * user and otherwise never trusted. */
    $sniff = imageproc_sniff($file['tmp_name']);
    $isDoc = false;

    if ($sniff === null) {
        $mime = media_sniff_document($file['tmp_name']);
        if ($mime === null) {
            throw new RuntimeException('unsupported_type');
        }
        $isDoc = true;
        $sniff = array('mime' => $mime, 'ext' => MEDIA_DOC_TYPES[$mime], 'family' => 'document');
    } elseif ($sniff['family'] === 'heif' && !imageproc_heif_supported()) {
        /* Named specifically rather than lumped in with unsupported_type: this
         * one is a server capability, not a bad file, and the difference is
         * what tells you to check Imagick rather than to re-take the photo. */
        throw new RuntimeException('heic_unsupported_on_server');
    }

    $slug = imageproc_new_slug();
    $dir  = $isDoc ? 'docs' : 'original';
    imageproc_ensure_dir($dir);

    $dest = imageproc_upload_path($dir, $slug, $sniff['ext']);
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('save_failed');
    }
    @chmod($dest, 0644);

    try {
        q(
            "INSERT INTO media
               (kind, $column, slug, ext, original_path, original_filename, mime, bytes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            array(
                $isDoc ? 'document' : 'photo',
                $ownerId,
                $slug,
                $sniff['ext'],
                imageproc_relative_path($dir, $slug, $sniff['ext']),
                media_display_name($file['name']),
                $sniff['mime'],
                $file['size'],
                $isDoc ? 'ready' : 'pending',
            )
        );
    } catch (Throwable $e) {
        /* The row is the record of the file. Without one, the file on disk is
         * unreachable and unreapable — so if the insert fails, take it back
         * out rather than leaving litter nothing will ever clean up. */
        @unlink($dest);
        throw $e;
    }

    return (int) db()->lastInsertId();
}

/** A PDF by its bytes, or null. Never by name, never by the browser's MIME. */
function media_sniff_document(string $path): ?string
{
    if (!is_file($path) || filesize($path) === 0) {
        return null;
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $head = fread($fh, 5);
    fclose($fh);

    return $head === '%PDF-' ? 'application/pdf' : null;
}

/* ----------------------------------------------------------------- queue */

function media_max_attempts(): int
{
    return max(1, (int) cfg('media.max_attempts', 3));
}

/** How long a claim is honoured before another worker may steal the row. */
function media_lock_minutes(): int
{
    return max(1, (int) cfg('media.lock_minutes', 5));
}

function media_pending_count(): int
{
    return (int) q(
        "SELECT COUNT(*) FROM media WHERE kind = 'photo' AND status IN ('pending','processing')"
    )->fetchColumn();
}

/**
 * Claim one row for processing. Returns it, or null if somebody else got it.
 *
 * THE UPDATE IS THE LOCK, and it must never be split into a SELECT and then an
 * UPDATE. Two workers race here by design — the browser's drain loop right
 * after an upload, and the cron sweep — and the conditional UPDATE's rowCount()
 * is what decides the winner atomically. A SELECT-then-UPDATE has a window
 * between the two in which both workers believe they own the row, and the
 * symptom is two sets of derivatives, one of which is orphaned on disk.
 *
 * The locked_at clause is what lets a row be re-claimed after a worker died
 * mid-process: a claim older than media_lock_minutes() is assumed dead.
 */
function media_claim(int $id): ?array
{
    $cutoff = (new DateTimeImmutable('-' . media_lock_minutes() . ' minutes'))->format('Y-m-d H:i:s');

    $claimed = q(
        "UPDATE media
            SET status = 'processing', locked_at = ?, attempts = attempts + 1
          WHERE id = ?
            AND kind = 'photo'
            AND (status = 'pending' OR (status = 'processing' AND (locked_at IS NULL OR locked_at < ?)))",
        array(date('Y-m-d H:i:s'), $id, $cutoff)
    )->rowCount();

    if ($claimed !== 1) {
        return null;
    }

    $row = q('SELECT * FROM media WHERE id = ?', array($id))->fetch();
    return $row === false ? null : $row;
}

/** Claim the oldest processable row, or null when the queue is drained. */
function media_claim_next(): ?array
{
    $cutoff = (new DateTimeImmutable('-' . media_lock_minutes() . ' minutes'))->format('Y-m-d H:i:s');

    $ids = q(
        "SELECT id FROM media
          WHERE kind = 'photo'
            AND (status = 'pending' OR (status = 'processing' AND (locked_at IS NULL OR locked_at < ?)))
          ORDER BY id
          LIMIT 10",
        array($cutoff)
    )->fetchAll(PDO::FETCH_COLUMN);

    /* Walk the candidates rather than taking the first: under a race the first
     * is exactly the one another worker is most likely to have just taken. */
    foreach ($ids ?: array() as $id) {
        $row = media_claim((int) $id);
        if ($row !== null) {
            return $row;
        }
    }
    return null;
}

/**
 * Derive one claimed row's thumb and detail. Returns its resulting status.
 *
 * Never throws. A photo that cannot be processed degrades to a row with a
 * reason on it — the screen still renders, that one image is a placeholder.
 */
function media_process(array $row): string
{
    $id = (int) $row['id'];

    try {
        $srcAbs = imageproc_resolve_upload($row['original_path'] ?? null);
        if ($srcAbs === null) {
            throw new RuntimeException('original_missing');
        }

        $sniff = imageproc_sniff($srcAbs);
        if ($sniff === null) {
            throw new RuntimeException('unsupported_type');
        }
        if ($sniff['family'] === 'heif' && !imageproc_heif_supported()) {
            throw new RuntimeException('heif_decode_unavailable');
        }

        $derived = imageproc_derive($srcAbs, (string) $row['slug'], $sniff);

        /* The original is NOT deleted here. See this file's header — that is
         * the single deliberate difference from the Gallery's worker. */
        q(
            "UPDATE media
                SET status = 'ready', thumb_path = ?, detail_path = ?,
                    width = ?, height = ?, last_error = NULL, locked_at = NULL
              WHERE id = ?",
            array(
                $derived['thumb_path'],
                $derived['detail_path'],
                $derived['width'],
                $derived['height'],
                $id,
            )
        );
        return 'ready';
    } catch (Throwable $e) {
        return media_fail($id, $e->getMessage());
    }
}

/**
 * Record a processing failure. Returns 'pending' (will retry) or 'failed'.
 *
 * Back to 'pending' until the attempt count runs out, because most failures
 * here are transient — a memory ceiling hit while another request was also
 * resizing, a half-written upload. The row and its original are KEPT on a
 * final failure so the photo is not lost and a retry stays possible.
 */
function media_fail(int $id, string $error): string
{
    $attempts = (int) q('SELECT attempts FROM media WHERE id = ?', array($id))->fetchColumn();
    $status   = $attempts >= media_max_attempts() ? 'failed' : 'pending';

    q(
        'UPDATE media SET status = ?, locked_at = NULL, last_error = ? WHERE id = ?',
        array($status, mb_substr($error, 0, 500, 'UTF-8'), $id)
    );
    return $status;
}

/** Claim and process one row. Null when there was nothing to do. */
function media_process_next(): ?string
{
    $row = media_claim_next();
    return $row === null ? null : media_process($row);
}

/* ----------------------------------------------------------------- reads */

/** Normalize a media row into the shape templates and JSON both want. */
function media_row(array $row): array
{
    return array(
        'id'       => (int) $row['id'],
        'kind'     => (string) $row['kind'],
        'status'   => (string) $row['status'],
        'thumb'    => $row['thumb_path'] ?: null,
        'detail'   => $row['detail_path'] ?: null,
        'original' => $row['original_path'] ?: null,
        'filename' => $row['original_filename'] ?: null,
        'caption'  => $row['caption'] ?: null,
        'width'    => $row['width'] !== null ? (int) $row['width'] : null,
        'height'   => $row['height'] !== null ? (int) $row['height'] : null,
    );
}

/**
 * Media for MANY owners at once, keyed by owner id.
 *
 * The batched form is the one list screens must use — see tags_for_many() for
 * the same argument. Owners with nothing attached come back with an empty
 * array rather than being absent.
 *
 * @param list<int> $ownerIds
 * @return array<int, list<array>>
 */
function media_for_many(string $ownerType, array $ownerIds): array
{
    $column = media_owner_column($ownerType);
    if ($column === null) {
        return array();
    }

    $ids = array_values(array_unique(array_map('intval', $ownerIds)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

    $out = array();
    foreach ($ids as $id) {
        $out[$id] = array();
    }
    if ($ids === array()) {
        return $out;
    }

    $holes = implode(', ', array_fill(0, count($ids), '?'));
    $rows  = q(
        "SELECT * FROM media WHERE $column IN ($holes) ORDER BY sort_order, id",
        $ids
    )->fetchAll();

    foreach ($rows ?: array() as $row) {
        $out[(int) $row[$column]][] = media_row($row);
    }
    return $out;
}

/** Media for one owner. @return list<array> */
function media_for(string $ownerType, int $ownerId): array
{
    $found = media_for_many($ownerType, array($ownerId));
    return $found[$ownerId] ?? array();
}

/**
 * Delete a media row and every file behind it. True if a row went.
 *
 * THE FILES GO FIRST, THEN THE ROW. The other order leaves a file with nothing
 * pointing at it if the delete fails partway — and since nothing sweeps
 * originals in this app (by design), that file would sit there forever.
 * Deleting the row last means a failure leaves a row whose files are missing,
 * which every read path already tolerates.
 */
function media_delete(int $id): bool
{
    $row = q('SELECT * FROM media WHERE id = ?', array($id))->fetch();
    if ($row === false) {
        return false;
    }

    foreach (array('original_path', 'thumb_path', 'detail_path') as $column) {
        $abs = imageproc_resolve_upload($row[$column] ?? null);
        if ($abs !== null) {
            @unlink($abs);
        }
    }

    return q('DELETE FROM media WHERE id = ?', array($id))->rowCount() > 0;
}
