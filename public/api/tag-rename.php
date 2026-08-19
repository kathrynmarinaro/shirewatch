<?php
/* POST /api/tag-rename.php   {id, name}  ->  {id, name, kind, sort_order}
 *
 * ONE UPDATE, AND EVERY ITEM ALREADY TAGGED FOLLOWS IT. Nothing is copied and
 * nothing is migrated — see lib/tags.php.
 *
 * A name already used by a sibling of the same kind is refused with
 * `name_taken` rather than silently merging the two tags. Merging is a
 * different operation with a different consequence (every item on the loser
 * has to move), and doing it by accident on a rename would be silent data
 * loss.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tags.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$id   = (int) ($body['id'] ?? 0);
$name = (string) ($body['name'] ?? '');

if ($id <= 0) {
    json_error('bad_id', 400);
}
if (trim($name) === '') {
    json_error('empty_name', 400);
}
if (tag_by_id($id) === null) {
    json_error('not_found', 404);
}

if (!tag_rename($id, $name)) {
    json_error('name_taken', 409);
}

json_out(tag_by_id($id));
