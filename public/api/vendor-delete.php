<?php
/* POST /api/vendor-delete.php   {id}  ->  {ok: true, records: 4}
 *
 * The vendor goes; THEIR WORK HISTORY DOES NOT. vendor_id is SET NULL and the
 * snapshotted vendor_name stays on every record, so the 2023 invoice still
 * says who sent it.
 *
 * The record count comes back so the screen can say that out loud — "deleted,
 * their 4 jobs are still in the history" is the difference between a confident
 * delete and one you undo by re-typing everything.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/vendors.php';

require_login_api();
require_same_origin();
require_method('POST');

$id = (int) (json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 400);
}

$rating = vendor_rating($id);

if (!vendor_delete($id)) {
    json_error('not_found', 404);
}

json_out(array('ok' => true, 'records' => $rating['jobs']));
