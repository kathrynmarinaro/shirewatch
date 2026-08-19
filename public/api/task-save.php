<?php
/* POST /api/task-save.php
 *
 *   {title, instructions?, recur_kind, interval_count?, interval_unit?,
 *    interval_from?, recur_months?, recur_day?, next_due_on?, tag_ids?}
 *   plus {id} to edit.
 *
 * → the saved task, with its tags and a plain-English description of the rule.
 *
 * `bad_recurrence` comes back when the rule could never produce a date. That
 * is refused at the door rather than stored, because a task that silently
 * never comes due is indistinguishable from nothing being due — the worst
 * possible failure for a reminder app.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tasks.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$id   = (int) ($body['id'] ?? 0);

try {
    if ($id > 0) {
        if (!task_save($id, $body)) {
            json_error('not_found', 404);
        }
    } else {
        $id = task_create($body);
    }
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
}

$task = task_get($id);
$task['tags']  = tags_for('task', $id);
$task['every'] = recur_describe($task);

json_out($task);
