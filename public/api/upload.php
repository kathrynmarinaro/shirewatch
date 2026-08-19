<?php
/* POST /api/upload.php   multipart/form-data
 *
 *   files[]     one or more photos/documents
 *   owner_type  issue | issue_update | record
 *   owner_id    the row they attach to
 *
 * → { "created":  [{ "id": 91, "name": "IMG_4021.HEIC" }],
 *     "rejected": [{ "name": "notes.txt", "reason": "unsupported_type" }] }
 *
 * ---------------------------------------------------------------------------
 * DOES NO IMAGE WORK, AND THAT IS THE WHOLE POINT.
 * ---------------------------------------------------------------------------
 *
 * Each file is sniffed, saved and given a 'pending' row; the response goes back
 * before the first resize has started. The browser then drains the queue via
 * worker.php, and cron/process-queue.php sweeps whatever a closed tab left.
 *
 * Resizing here would put a ten-file service-record batch 10-20 seconds into a
 * single request, which on shared hosting is a live risk of max_execution_time
 * — and the failure mode is a half-uploaded batch with no error anyone can act
 * on.
 *
 * ---------------------------------------------------------------------------
 * A BATCH NEVER FAILS AS A WHOLE.
 * ---------------------------------------------------------------------------
 *
 * One unreadable file comes back in `rejected` with a reason, beside the eight
 * that worked. Nine photos of a repair are not worth losing because the tenth
 * was a screenshot of a screenshot.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/media.php';

require_login_api();
require_same_origin();
require_method('POST');

/* PHP DISCARDS THE WHOLE BODY when it exceeds post_max_size, leaving $_FILES
 * and $_POST both empty with no error code to inspect. Detect it before
 * concluding the client sent nothing — otherwise the one failure that is
 * entirely the server's configuration reports as "no files", and the fix
 * (raise post_max_size, see DEPLOY.txt) is the one thing the message does not
 * hint at. */
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > 0 && empty($_FILES) && empty($_POST)) {
    json_error('payload_too_large', 413,
        'The upload exceeded the server post_max_size limit.');
}

$ownerType = (string) ($_POST['owner_type'] ?? '');
$ownerId   = (int) ($_POST['owner_id'] ?? 0);

if (media_owner_column($ownerType) === null) {
    json_error('bad_owner_type', 400);
}
if ($ownerId <= 0 || !upload_owner_exists($ownerType, $ownerId)) {
    json_error('owner_not_found', 404);
}
if (empty($_FILES['files'])) {
    json_error('no_files', 400);
}

$created  = array();
$rejected = array();

foreach (media_normalize_files($_FILES['files']) as $file) {
    $label = media_display_name($file['name']);

    try {
        $id = media_store($file, $ownerType, $ownerId);
        $created[] = array('id' => $id, 'name' => $label);
    } catch (Throwable $e) {
        /* media_store() throws a machine-readable reason for anything the user
         * could plausibly fix, and something else for a genuine server fault.
         * Log the latter and give it a stable code, so the client never has to
         * parse an exception message. */
        $reason = $e->getMessage();
        if (!in_array($reason, upload_known_reasons(), true)) {
            error_log('upload: ' . $reason);
            $reason = 'save_failed';
        }
        $rejected[] = array('name' => $label, 'reason' => $reason);
    }
}

json_out(array('created' => $created, 'rejected' => $rejected));

/* ------------------------------------------------------------------ helpers */

/** Reasons that are the file's fault and safe to show verbatim. */
function upload_known_reasons(): array
{
    return array(
        'too_large', 'empty_file', 'incomplete_upload', 'upload_failed',
        'unsupported_type', 'heic_unsupported_on_server', 'server_cannot_write',
        'save_failed', 'bad_owner',
    );
}

/**
 * Does the row we are attaching to exist?
 *
 * Checked BEFORE any file is written. Without it, a bad owner_id means files
 * on disk whose media rows fail their foreign key — litter nothing will ever
 * reap, since this app deliberately has no sweep.
 *
 * The table name comes from a whitelist keyed by the same owner types
 * lib/media.php accepts, so nothing a caller sends becomes SQL.
 */
function upload_owner_exists(string $ownerType, int $ownerId): bool
{
    $tables = array(
        'issue'        => 'issues',
        'issue_update' => 'issue_updates',
        'record'       => 'service_records',
    );
    if (!isset($tables[$ownerType])) {
        return false;
    }
    $table = $tables[$ownerType];
    return q("SELECT 1 FROM $table WHERE id = ?", array($ownerId))->fetch() !== false;
}
