<?php
/* POST /api/task-delete.php   {id}  ->  {ok: true}
 *
 * Deletes the task and its completion history.
 *
 * Pausing is what you usually want — see api/task-active.php. Deleting is for
 * a starter task that does not apply to this house at all, which is exactly
 * what the generic starter list expects you to do with half of it.
 *
 * tools/install-starter-tasks.php NEVER RESURRECTS a deleted task, so this is
 * permanent even if the installer is run again.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tasks.php';

require_login_api();
require_same_origin();
require_method('POST');

$id = (int) (json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 400);
}
if (!task_delete($id)) {
    json_error('not_found', 404);
}

json_out(array('ok' => true));
