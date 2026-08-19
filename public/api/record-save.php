<?php
/* POST /api/record-save.php
 *
 *   {title, vendor_id?, vendor_name?, description?, performed_on?, cost?,
 *    rating?, issue_id?, task_id?, tag_ids?}  plus {id} to edit.
 *
 * → the saved record.
 *
 * The batch uploader posts photos and documents afterwards against the
 * returned id, so this has to succeed first — api/upload.php refuses an owner
 * row that does not exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/records.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$id   = (int) ($body['id'] ?? 0);

try {
    $id = record_save($id > 0 ? $id : null, $body);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
}

json_out(record_get($id));
