<?php
/* POST /api/task-uncomplete.php   {completion_id}  ->  {ok, task}
 *
 * Undo for a completion tapped by accident — which on a phone, on a list of
 * twenty rows with a checkbox each, is a thing that happens.
 *
 * ---------------------------------------------------------------------------
 * IT RECOMPUTES THE SCHEDULE RATHER THAN REMEMBERING THE OLD DATE.
 * ---------------------------------------------------------------------------
 *
 * Storing "the due date before this completion" would be a fourth column that
 * exists for one button and is wrong the moment anyone edits the rule. Instead
 * the previous completion becomes the anchor again, and the schedule is
 * rebuilt from it by the same recur_next_after() everything else uses.
 *
 * With no completions left, the task falls back to its own last known due date
 * — a task that has never been done has nothing to count from except itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tasks.php';

require_login_api();
require_same_origin();
require_method('POST');

$completionId = (int) (json_body()['completion_id'] ?? 0);
if ($completionId <= 0) {
    json_error('bad_id', 400);
}

$row = q('SELECT task_id, completed_on FROM task_completions WHERE id = ?', array($completionId))->fetch();
if ($row === false) {
    json_error('not_found', 404);
}

$taskId = (int) $row['task_id'];
$task   = task_get($taskId);
if ($task === null) {
    json_error('not_found', 404);
}

q('DELETE FROM task_completions WHERE id = ?', array($completionId));

/* The completion before the one just removed, if there is one. */
$previous = q(
    'SELECT completed_on FROM task_completions WHERE task_id = ? ORDER BY completed_on DESC, id DESC LIMIT 1',
    array($taskId)
)->fetch();

$lastCompleted = $previous === false ? null : (string) $previous['completed_on'];

/* Recomputed forward from the earlier completion. With none left there is
 * nothing honest to count from, so the task keeps the due date it has —
 * a task that has never been done has only itself to go on. */
$next = $lastCompleted === null
    ? $task['next_due_on']
    : (recur_next_after(task_rule($task), $lastCompleted) ?? $task['next_due_on']);

q(
    'UPDATE maintenance_tasks SET next_due_on = ?, last_completed_on = ? WHERE id = ?',
    array($next, $lastCompleted, $taskId)
);

json_out(array('ok' => true, 'task' => task_get($taskId)));
