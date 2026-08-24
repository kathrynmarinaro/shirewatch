<?php
/* POST /api/entry-update.php
 *   {entry_id, kind?, noted_on?, note?, severity?,
 *    vendor_id?, vendor_name?, cost?, rating?}
 * → {id, entry: {...}}
 *
 * kind is 'note' (the default) or 'service'. The four money fields are only
 * read when kind is 'service' — sent alongside a note they are ignored, so a
 * client that leaves stale inputs in the DOM cannot file a plumber's invoice
 * against an observation.
 *
 * Adds one update to the timeline. The returned `id` is what
 * api/upload.php attaches photos to, with owner_type 'update'.
 *
 * ---------------------------------------------------------------------------
 * THIS NEVER EDITS A PREVIOUS UPDATE.
 * ---------------------------------------------------------------------------
 *
 * There is no entry-update-edit.php and there should not be one. The premise
 * of the app is that nothing in a timeline is overwritten, and an edit path
 * would eventually be used to "correct" a measurement — but the correction IS
 * the observation, and losing the first reading loses the progression that the
 * second reading is only meaningful against.
 *
 * A mistaken update is deleted whole, by api/entry-update-delete.php.
 *
 * The entry comes back in the response because adding an update rewrites the
 * entry's severity, last_checked_on and next_check_on — so the screen would
 * otherwise be showing three stale values the moment it succeeded.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/log.php';

require_login_api();
require_same_origin();
require_method('POST');

$body    = json_body();
$entryId = (int) ($body['entry_id'] ?? 0);

if ($entryId <= 0) {
    json_error('bad_id', 400);
}

try {
    $updateId = entry_add_update($entryId, $body);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 404);
}

json_out(array('id' => $updateId, 'entry' => entry_get($entryId)));
