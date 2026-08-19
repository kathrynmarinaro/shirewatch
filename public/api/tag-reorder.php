<?php
/* POST /api/tag-reorder.php   {ids: [3, 1, 9]}  ->  {ok: true}
 *
 * The ids are the new order, top to bottom, for ONE kind. Sort orders are
 * rewritten spaced by tens so a later single insertion has somewhere to land
 * without renumbering everything.
 *
 * Ids from another kind are harmless — they would simply be renumbered within
 * their own list, which reorder never mixes. The screen sends one list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tags.php';

require_login_api();
require_same_origin();
require_method('POST');

$ids = json_body()['ids'] ?? null;
if (!is_array($ids)) {
    json_error('bad_ids', 400);
}

tags_reorder(array_map('intval', $ids));
json_out(array('ok' => true));
