<?php
/* POST /api/tag-delete.php   {id}  ->  {ok: true, usage: {...}}
 *
 * The tag lifts off every item it was on, by cascade. The usage counts come
 * back in the response so the screen can say what just happened — "removed
 * from 6 log entries" — rather than the row simply vanishing.
 *
 * THERE IS NO UNDO, and deliberately no soft-delete column. A deleted tag with
 * a `deleted_at` on it is a tag that still collides with a new one of the same
 * name, and explaining that to someone who just wants their room back is worse
 * than the delete confirmation the screen already shows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tags.php';

require_login_api();
require_same_origin();
require_method('POST');

$id = (int) (json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 400);
}
if (tag_by_id($id) === null) {
    json_error('not_found', 404);
}

/* Counted BEFORE the delete — afterwards the join rows are gone and the answer
 * is always zero. */
$usage = tag_usage($id);

if (!tag_delete($id)) {
    json_error('delete_failed', 500);
}

json_out(array('ok' => true, 'usage' => $usage));
