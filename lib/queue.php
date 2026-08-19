<?php
/* The processing queue: claiming, per-image processing, retry bookkeeping.
 *
 * Two independent workers race here — the browser's drain loop after an
 * upload, and the per-minute cron sweep. Every claim is a single conditional
 * UPDATE whose rowCount() decides the winner, so the same row can never be
 * processed twice no matter how the two interleave.
 */

declare(strict_types=1);

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/imageproc.php';
require_once __DIR__ . '/color.php';
require_once __DIR__ . '/vision.php';

function queue_max_attempts(): int
{
    return max(1, (int) cfg('processing.max_attempts', 3));
}

function queue_lock_minutes(): int
{
    return max(1, (int) cfg('processing.lock_minutes', 5));
}

/* ---------------------------------------------------------------- counting */

/**
 * How many images still need work: anything pending, plus anything whose
 * 'processing' lock has gone stale (a worker that died mid-image).
 */
function queue_pending_count(): int
{
    return (int) q(
        "SELECT COUNT(*) FROM images
          WHERE status = 'pending'
             OR (status = 'processing'
                 AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL ? MINUTE))",
        array(queue_lock_minutes())
    )->fetchColumn();
}

/**
 * Ids worth attempting, oldest first. Deliberately a short list rather than a
 * single row: if another worker beats us to the first id we try the next one
 * instead of reporting an empty queue.
 *
 * @return list<int>
 */
function queue_candidates(int $limit = 10): array
{
    $rows = q(
        "SELECT id FROM images
          WHERE status = 'pending'
             OR (status = 'processing'
                 AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL ? MINUTE))
          ORDER BY date_added ASC, id ASC
          LIMIT ?",
        array(queue_lock_minutes(), max(1, min(100, $limit)))
    )->fetchAll();

    return array_map(static fn(array $r): int => (int) $r['id'], $rows);
}

/* ---------------------------------------------------------------- claiming */

/**
 * Try to take ownership of one row. Returns the freshly-claimed row, or null
 * if somebody else got there first.
 *
 * The UPDATE is the lock: it matches only a row that is still claimable, and
 * InnoDB serializes the two racers on the row itself, so exactly one of them
 * sees rowCount() === 1. Never split this into a SELECT-then-UPDATE.
 */
function queue_claim(int $id): ?array
{
    $stmt = q(
        "UPDATE images
            SET status = 'processing', locked_at = NOW(), attempts = attempts + 1
          WHERE id = ?
            AND status IN ('pending', 'processing', 'failed')
            AND attempts < ?
            AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL ? MINUTE)",
        array($id, queue_max_attempts(), queue_lock_minutes())
    );

    if ($stmt->rowCount() !== 1) {
        return null;                       // lost the race, or no longer eligible
    }

    $row = q('SELECT * FROM images WHERE id = ?', array($id))->fetch();
    return $row === false ? null : $row;
}

/** Claim the next available image, skipping rows other workers grabbed. */
function queue_claim_next(): ?array
{
    foreach (queue_candidates() as $id) {
        $row = queue_claim($id);
        if ($row !== null) {
            return $row;
        }
    }
    return null;
}

/* -------------------------------------------------------------- completion */

/**
 * Mark an image ready, keeping the original for the annotation session.
 *
 * The original used to be deleted here. It is now retained so that a crop taken
 * in the annotation panel can work from full resolution instead of from the
 * 1600px detail copy — cropping the detail copy compounds quality loss, and
 * once the original is gone that is unrecoverable.
 *
 * Ownership of the file passes to the annotation session: queue_release_original
 * drops it when the user finishes or skips, and queue_sweep_originals reaps
 * anything from a session that was simply abandoned.
 *
 * The DB write lands first: if the original were deleted first and the UPDATE
 * then failed, the row would point at a file that no longer exists and could
 * never be retried.
 */
function queue_complete(int $id, array $result, ?string $originalPath): void
{
    $stmt = q(
        "UPDATE images
            SET thumb_path        = ?,
                detail_path       = ?,
                width             = ?,
                height            = ?,
                dominant_colors   = ?,
                style_descriptors = ?,
                status            = 'ready',
                locked_at         = NULL,
                last_error        = NULL,
                original_kept_at  = NOW()
          WHERE id = ?",
        array(
            $result['thumb_path'],
            $result['detail_path'],
            $result['width'],
            $result['height'],
            json_encode(array_values($result['dominant_colors'])),
            json_encode(array_values($result['style_descriptors'])),
            $id,
        )
    );

    /* The row can be deleted while we were working — discarding a photo from
     * the annotation panel does exactly that, and the cron sweep can be mid-
     * image when it happens. The UPDATE then matches nothing and the two WebP
     * files we just wrote have no owner, so nothing will ever clean them up.
     * Delete them here rather than leaving them on disk forever. */
    if ($stmt->rowCount() === 0) {
        error_log(sprintf('queue: image %d vanished mid-process; discarding derivatives', $id));
        repo_unlink_upload($result['thumb_path']);
        repo_unlink_upload($result['detail_path']);
        repo_unlink_upload($originalPath);   // nothing owns it now
    }
}

/* ------------------------------------------------------- original retention */

/**
 * Hours an unclaimed original is kept before the sweep reaps it.
 *
 * This is the backstop for a session that never ended — a closed tab, a phone
 * that went to sleep mid-batch. Long enough to come back to an interrupted
 * annotation, short enough that a full-resolution copy of every upload is not
 * accumulating on the host indefinitely.
 */
function queue_original_ttl_hours(): int
{
    return max(1, (int) cfg('processing.original_ttl_hours', 24));
}

/**
 * Drop the retained originals for a finished annotation batch.
 *
 * Returns the number of files released. Ids the caller does not own are simply
 * not matched — the WHERE clause requires a retained original, so this cannot
 * touch an image still waiting to be processed.
 */
function queue_release_originals(array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
    if (!$ids) {
        return 0;
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = q(
        "SELECT id, original_path
           FROM images
          WHERE id IN ($in)
            AND status = 'ready'
            AND original_kept_at IS NOT NULL
            AND original_path IS NOT NULL",
        $ids
    )->fetchAll();

    return queue_drop_originals($rows);
}

/** Reap retained originals whose annotation session was never finished. */
function queue_sweep_originals(): int
{
    /* Interpolated rather than bound: a placeholder inside INTERVAL is accepted
     * inconsistently depending on the driver and whether prepares are emulated.
     * queue_original_ttl_hours() returns an int through max()/(int), so there is
     * nothing here for a value to inject. */
    $hours = queue_original_ttl_hours();

    $rows = q(
        "SELECT id, original_path
           FROM images
          WHERE status = 'ready'
            AND original_kept_at IS NOT NULL
            AND original_path IS NOT NULL
            AND original_kept_at < (NOW() - INTERVAL $hours HOUR)"
    )->fetchAll();

    return queue_drop_originals($rows);
}

/**
 * Clear the DB pointer before unlinking, so a failed delete leaves an orphan
 * file rather than a row pointing at a file that is no longer there — the
 * former is invisible, the latter breaks crop with a missing-source error.
 */
function queue_drop_originals(array $rows): int
{
    $released = 0;
    foreach ($rows as $row) {
        q(
            'UPDATE images SET original_path = NULL, original_kept_at = NULL WHERE id = ?',
            array((int) $row['id'])
        );
        repo_unlink_upload($row['original_path']);
        $released++;
    }
    return $released;
}

/**
 * Record a failed attempt. Returns the status the row ended up in.
 *
 * The original is deliberately left on disk — without it a retry has nothing
 * to work from.
 */
function queue_fail(int $id, string $error): string
{
    $attempts = (int) q('SELECT attempts FROM images WHERE id = ?', array($id))->fetchColumn();
    $status   = $attempts >= queue_max_attempts() ? 'failed' : 'pending';

    q(
        'UPDATE images SET status = ?, locked_at = NULL, last_error = ? WHERE id = ?',
        array($status, mb_substr($error, 0, 500, 'UTF-8'), $id)
    );

    return $status;
}

/* -------------------------------------------------------------- processing */

/**
 * Do the actual work for one already-claimed row.
 *
 * @return array{id:int,status:string,error:?string}
 */
function queue_process(array $row): array
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

        /* 1–2: resize + colour. These two are the real definition of success. */
        $derived = imageproc_derive($srcAbs, imageproc_new_slug(), $sniff);

        $detailAbs = imageproc_resolve_upload($derived['detail_path']);
        if ($detailAbs === null) {
            throw new RuntimeException('detail_write_failed');
        }

        $colors = color_dominant($detailAbs);
    } catch (Throwable $e) {
        /* Don't leave half a derivative pair behind for the retry to trip on. */
        if (isset($derived)) {
            repo_unlink_upload($derived['thumb_path']);
            repo_unlink_upload($derived['detail_path']);
        }
        $status = queue_fail($id, $e->getMessage());
        error_log(sprintf('queue: image %d failed (%s): %s', $id, $status, $e->getMessage()));
        return array('id' => $id, 'status' => $status, 'error' => $e->getMessage());
    }

    /* 3: vision is best-effort by contract — never let it fail the image. */
    $styles  = array();
    $thumbAbs = imageproc_resolve_upload($derived['thumb_path']);
    if ($thumbAbs !== null) {
        try {
            $styles = vision_describe($thumbAbs);
        } catch (Throwable $e) {
            error_log('queue: vision failed for image ' . $id . ': ' . $e->getMessage());
        }
    }

    queue_complete($id, array(
        'thumb_path'        => $derived['thumb_path'],
        'detail_path'       => $derived['detail_path'],
        'width'             => $derived['width'],
        'height'            => $derived['height'],
        'dominant_colors'   => $colors,
        'style_descriptors' => $styles,
    ), $row['original_path'] ?? null);

    return array('id' => $id, 'status' => 'ready', 'error' => null);
}

/**
 * Claim and process one image. Null when there is nothing to do.
 *
 * @return array{id:int,status:string,error:?string}|null
 */
function queue_process_next(): ?array
{
    $row = queue_claim_next();
    if ($row === null) {
        return null;
    }
    return queue_process($row);
}
