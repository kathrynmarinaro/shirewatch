<?php
/* GET /api/tag-usage.php?id=3  ->  {entry: 6, task: 2, vendor: 0}
 *
 * Asked before showing the delete confirmation. "Kitchen is on 6 log entries and 2
 * tasks" is the difference between an informed delete and one that looks
 * undoable and quietly detaches six things.
 *
 * A GET, and the only read endpoint in this module — the screen itself is
 * server-rendered.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tags.php';

require_login_api();
require_method('GET');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 || tag_by_id($id) === null) {
    json_error('not_found', 404);
}

json_out(tag_usage($id));
