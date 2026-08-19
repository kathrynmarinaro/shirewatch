<?php
/* POST /api/vendor-save.php
 *   {name, phone?, email?, notes?, rating_override?, tag_ids?} plus {id}.
 * → the saved vendor, with its computed rating and job counts.
 *
 * rating_override is NULL to go back to the average. It is never written as 0
 * — zero is not a legal rating (schema.sql), and "clear the override" and
 * "rate them zero" would otherwise be the same request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/vendors.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$id   = (int) ($body['id'] ?? 0);

try {
    $id = vendor_save($id > 0 ? $id : null, $body);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
}

json_out(vendor_get($id));
