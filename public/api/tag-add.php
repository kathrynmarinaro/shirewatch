<?php
/* POST /api/tag-add.php   {kind, name}  ->  {id, name, kind, sort_order}
 *
 * Idempotent on the name: adding "Kitchen" twice returns the existing row
 * rather than erroring, which is what makes a double-tapped Add harmless on a
 * connection where the first response is still in flight.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tags.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$kind = (string) ($body['kind'] ?? '');
$name = (string) ($body['name'] ?? '');

if (!in_array($kind, tag_kinds(), true)) {
    json_error('bad_kind', 400);
}
if (trim($name) === '') {
    json_error('empty_name', 400);
}

$id = tag_add($kind, $name);
if ($id === null) {
    json_error('add_failed', 400);
}

json_out(tag_by_id($id));
