<?php
/* POST /api/task-complete.php
 *   {id, completed_on?, note?, vendor_id?, vendor_name?, cost?, rating?}
 * → {ok: true, next_due_on: "2026-11-19", task: {...}}
 *
 * ---------------------------------------------------------------------------
 * THE ONLY ENDPOINT THAT MOVES A DUE DATE.
 * ---------------------------------------------------------------------------
 *
 * Not the reminder cron, which only writes to task_reminder_sends. Not the
 * editor, unless you hand it an explicit date. This one, because you did the
 * thing.
 *
 * The new due date comes back so the screen can say "next in November" rather
 * than just removing the row and leaving you to wonder whether it worked.
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

$next = task_complete($id, $body);
if ($next === null) {
    json_error('not_found', 404);
}

json_out(array('ok' => true, 'next_due_on' => $next, 'task' => task_get($id)));
