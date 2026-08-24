<?php
/* POST /api/entry-update-delete.php   {id}  ->  {ok: true, entry: {...}}
 *
 * Removes one timeline entry and every photo on it.
 *
 * The whole entry or nothing — see api/entry-update.php for why there is no
 * edit. This is for the update you logged against the wrong entry, not for
 * fixing a typo in one.
 *
 * The entry comes back because deleting an update rewrites its derived columns:
 * removing the newest check-in moves last_checked_on and next_check_on back,
 * and may restore an older severity.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/log.php';

require_login_api();
require_same_origin();
require_method('POST');

$id = (int) (json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 400);
}

$row = q('SELECT entry_id FROM entry_updates WHERE id = ?', array($id))->fetch();
if ($row === false) {
    json_error('not_found', 404);
}
$entryId = (int) $row['entry_id'];

entry_delete_update($id);

json_out(array('ok' => true, 'entry' => entry_get($entryId)));
