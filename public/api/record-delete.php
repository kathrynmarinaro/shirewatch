<?php
/* POST /api/record-delete.php   {id}  ->  {ok: true}
 *
 * Deletes the record, its photos and its uploaded documents.
 *
 * NO UNDO, and the invoices go with it — which is the part worth pausing over,
 * since a scanned warranty may be the only copy that exists.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/records.php';

require_login_api();
require_same_origin();
require_method('POST');

$id = (int) (json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 400);
}
if (!record_delete($id)) {
    json_error('not_found', 404);
}

json_out(array('ok' => true));
