<?php
/* POST /api/task-active.php   {id, active}  ->  the task
 *
 * Pause or resume. A paused task keeps its completion history and stops being
 * due, which is what you want for the sprinkler blowout in a year you have no
 * sprinklers — deleting it would lose the record of the eight years you did.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tasks.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$id   = (int) ($body['id'] ?? 0);

if ($id <= 0) {
    json_error('bad_id', 400);
}
if (!task_set_active($id, (bool) ($body['active'] ?? true))) {
    json_error('not_found', 404);
}

json_out(task_get($id));
