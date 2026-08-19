<?php
/* Scheduled maintenance: the tasks, their completions, and the schedule.
 *
 * ---------------------------------------------------------------------------
 * NOTHING OUTSIDE THIS FILE WRITES SQL AGAINST maintenance_tasks OR
 * task_completions.
 * ---------------------------------------------------------------------------
 *
 * The dashboard and the reminder cron both read through tasks_due() and
 * tasks_project(), so "what is due" has one definition and the email cannot
 * disagree with the screen.
 *
 * ---------------------------------------------------------------------------
 * next_due_on MOVES IN EXACTLY ONE PLACE: task_complete().
 * ---------------------------------------------------------------------------
 *
 * Not when a reminder is sent. task_reminder_sends is what stops the duplicate
 * email (schema.sql); the due date stays put until the job is actually done.
 *
 * So an overdue task emails once and then sits on the dashboard getting
 * louder. Advancing it on send would mean the app quietly forgives you for not
 * flushing the water heater, which is the one thing it exists not to do.
 *
 * The consequence that looks like a bug and is not: skip March's gutters
 * entirely and the task stays at March saying "5 months overdue" rather than
 * rolling silently to October. That is the true statement about the world, and
 * two overdue gutter rows would not be.
 *
 * ---------------------------------------------------------------------------
 * ALL DATE ARITHMETIC IS recur_next_after()'S.
 * ---------------------------------------------------------------------------
 *
 * The completion path, the editor, the starter-task installer and the
 * dashboard's forward projection all call the same function (lib/dates.php).
 * A second implementation for any of them would eventually draw a date the app
 * would never actually produce, and you would find out on the day it did not
 * arrive.
 */

declare(strict_types=1);

require_once __DIR__ . '/tags.php';

/** The columns recur_next_after() reads, pulled out of a task row. */
function task_rule(array $task): array
{
    return array(
        'recur_kind'     => $task['recur_kind'],
        'interval_count' => $task['interval_count'],
        'interval_unit'  => $task['interval_unit'],
        'recur_months'   => $task['recur_months'],
        'recur_day'      => $task['recur_day'],
    );
}

/** Normalize a database row into the shape every caller expects. */
function task_row(array $row): array
{
    return array(
        'id'                => (int) $row['id'],
        'title'             => (string) $row['title'],
        'instructions'      => $row['instructions'] !== null ? (string) $row['instructions'] : '',
        'recur_kind'        => (string) $row['recur_kind'],
        'interval_count'    => $row['interval_count'] !== null ? (int) $row['interval_count'] : null,
        'interval_unit'     => $row['interval_unit'] !== null ? (string) $row['interval_unit'] : null,
        'interval_from'     => (string) $row['interval_from'],
        'recur_months'      => $row['recur_months'] !== null ? (string) $row['recur_months'] : null,
        'recur_day'         => $row['recur_day'] !== null ? (int) $row['recur_day'] : null,
        'next_due_on'       => (string) $row['next_due_on'],
        'last_completed_on' => $row['last_completed_on'] ?: null,
        'is_active'         => (int) $row['is_active'] === 1,
        'created_at'        => (string) $row['created_at'],
    );
}

/* ------------------------------------------------------------------ reads */

function task_get(int $id): ?array
{
    $row = q('SELECT * FROM maintenance_tasks WHERE id = ?', array($id))->fetch();
    return $row === false ? null : task_row($row);
}

/**
 * The list, filtered.
 *
 * @param array $filters { active?: bool (default true), tag_ids?: list<int>,
 *                         search?: string }
 * @return list<array>  each decorated with 'tags'
 */
function tasks_list(array $filters = array()): array
{
    $where  = array();
    $params = array();

    /* Default to active only. A paused task keeps its completion history and
     * is meant to be out of the way, so showing it beside live work would
     * defeat the point of pausing it. */
    if (($filters['active'] ?? true) !== null) {
        $where[]  = 't.is_active = ?';
        $params[] = ($filters['active'] ?? true) ? 1 : 0;
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[]  = '(t.title LIKE ? OR t.instructions LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ($where === array()) {
        $where[] = '1 = 1';
    }

    /* AND-ed, like the issue list — see lib/issues.php for the argument. */
    $tagIds = array_values(array_filter(array_map('intval', $filters['tag_ids'] ?? array())));
    $join   = '';
    $group  = '';
    if ($tagIds !== array()) {
        $holes  = implode(', ', array_fill(0, count($tagIds), '?'));
        $join   = " JOIN task_tags tt ON tt.task_id = t.id AND tt.tag_id IN ($holes)";
        $group  = ' GROUP BY t.id HAVING COUNT(DISTINCT tt.tag_id) = ' . count($tagIds);
        $params = array_merge($tagIds, $params);
    }

    /* Soonest first, which puts everything overdue at the top in the order it
     * went overdue. That ordering IS the screen's argument: the thing you have
     * been ignoring longest is the thing you see first. */
    $sql = 'SELECT t.* FROM maintenance_tasks t' . $join
        . ' WHERE ' . implode(' AND ', $where) . $group
        . ' ORDER BY t.next_due_on, t.title';

    $rows = q($sql, $params)->fetchAll() ?: array();
    return tasks_decorate(array_map('task_row', $rows));
}

/** Attach tags to a list of tasks in one query, never one per row. */
function tasks_decorate(array $tasks): array
{
    if ($tasks === array()) {
        return array();
    }
    $tags = tags_for_many('task', array_column($tasks, 'id'));
    foreach ($tasks as $index => $task) {
        $tasks[$index]['tags'] = $tags[$task['id']] ?? array();
    }
    return $tasks;
}

/**
 * The completion history, newest first.
 *
 * NEWEST FIRST, unlike an issue's timeline. An issue's entries are a
 * progression that has to be read in order; a task's are a log, and the
 * question you ask of it is "when did I last do this", which is the top row.
 *
 * @return list<array{id:int, completed_on:string, note:string, service_record_id:?int}>
 */
function task_completions(int $taskId, int $limit = 50): array
{
    $rows = q(
        'SELECT * FROM task_completions WHERE task_id = ? ORDER BY completed_on DESC, id DESC LIMIT ' . max(1, $limit),
        array($taskId)
    )->fetchAll() ?: array();

    $out = array();
    foreach ($rows as $row) {
        $out[] = array(
            'id'                => (int) $row['id'],
            'completed_on'      => (string) $row['completed_on'],
            'note'              => $row['note'] !== null ? (string) $row['note'] : '',
            'service_record_id' => $row['service_record_id'] !== null ? (int) $row['service_record_id'] : null,
        );
    }
    return $out;
}

/* ----------------------------------------------------------------- writes */

/** Create a task. Returns its id. */
function task_create(array $data): int
{
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('empty_title');
    }

    $rule = task_clean_rule($data);
    $due  = task_clean_date($data['next_due_on'] ?? null)
        ?? recur_next_after($rule, sw_today());

    /* A rule that produces no date is a rule that would silently never come
     * due — the worst possible failure for a reminder app, because it is
     * indistinguishable from nothing being due. Refuse it at the door. */
    if ($due === null) {
        throw new InvalidArgumentException('bad_recurrence');
    }

    q(
        'INSERT INTO maintenance_tasks
           (property_id, title, instructions, recur_kind, interval_count, interval_unit,
            interval_from, recur_months, recur_day, next_due_on, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
        array(
            1,
            mb_substr($title, 0, 200, 'UTF-8'),
            task_clean_text($data['instructions'] ?? null),
            $rule['recur_kind'],
            $rule['interval_count'],
            $rule['interval_unit'],
            task_clean_from($data['interval_from'] ?? null),
            $rule['recur_months'],
            $rule['recur_day'],
            $due,
        )
    );

    $id = (int) db()->lastInsertId();
    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        tags_set('task', $id, $data['tag_ids']);
    }
    return $id;
}

/**
 * Edit a task. Returns false if it is gone.
 *
 * EDITING THE RULE DOES NOT SILENTLY MOVE THE DUE DATE. Changing "every 3
 * months" to "every 6" leaves the next one where it was and applies the new
 * interval from the next completion — because the alternative is that
 * correcting a typo in the schedule quietly cancels something that was due
 * tomorrow. The caller can pass next_due_on explicitly to move it.
 */
function task_save(int $id, array $data): bool
{
    $task = task_get($id);
    if ($task === null) {
        return false;
    }

    $title = trim((string) ($data['title'] ?? $task['title']));
    if ($title === '') {
        throw new InvalidArgumentException('empty_title');
    }

    $rule = task_clean_rule($data + task_rule($task));

    /* The new rule still has to be able to produce a date, or the task becomes
     * one that never comes due again. */
    if (recur_next_after($rule, sw_today()) === null) {
        throw new InvalidArgumentException('bad_recurrence');
    }

    q(
        'UPDATE maintenance_tasks
            SET title = ?, instructions = ?, recur_kind = ?, interval_count = ?,
                interval_unit = ?, interval_from = ?, recur_months = ?, recur_day = ?,
                next_due_on = ?
          WHERE id = ?',
        array(
            mb_substr($title, 0, 200, 'UTF-8'),
            array_key_exists('instructions', $data)
                ? task_clean_text($data['instructions'])
                : ($task['instructions'] === '' ? null : $task['instructions']),
            $rule['recur_kind'],
            $rule['interval_count'],
            $rule['interval_unit'],
            task_clean_from($data['interval_from'] ?? $task['interval_from']),
            $rule['recur_months'],
            $rule['recur_day'],
            task_clean_date($data['next_due_on'] ?? null) ?? $task['next_due_on'],
            $id,
        )
    );

    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        tags_set('task', $id, $data['tag_ids']);
    }
    return true;
}

/**
 * Mark a task done. THE ONLY THING THAT MOVES next_due_on.
 *
 * Writes a completion row and advances the schedule. Returns the new due date,
 * or null if the task is gone.
 *
 * WHICH DATE THE NEXT ONE IS COUNTED FROM depends on the rule:
 *
 *   interval + from 'completion'  the day you actually did it. Wear-based:
 *                                 the filter's clock started when you changed
 *                                 it, so doing it late moves the next one late.
 *   interval + from 'due'         the date it WAS due. Keeps a schedule on its
 *                                 original cadence rather than letting it
 *                                 drift later every time you are a week busy.
 *   months                        always the completion date — the next month
 *                                 in the list after it. interval_from has no
 *                                 meaning here: the calendar decides, not the
 *                                 arithmetic.
 */
function task_complete(int $id, array $data = array()): ?string
{
    $task = task_get($id);
    if ($task === null) {
        return null;
    }

    $completedOn = task_clean_date($data['completed_on'] ?? null) ?? sw_today();

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }

    try {
        q(
            'INSERT INTO task_completions (task_id, completed_on, note, service_record_id)
             VALUES (?, ?, ?, ?)',
            array(
                $id,
                $completedOn,
                task_clean_text($data['note'] ?? null),
                isset($data['service_record_id']) && (int) $data['service_record_id'] > 0
                    ? (int) $data['service_record_id']
                    : null,
            )
        );

        $anchor = ($task['recur_kind'] === 'months' || $task['interval_from'] === 'completion')
            ? $completedOn
            : $task['next_due_on'];

        $next = recur_next_after(task_rule($task), $anchor);

        /* A rule that cannot produce a date leaves the due date ALONE rather
         * than nulling it — the column is NOT NULL, and a task that quietly
         * stopped being due is worse than one stuck on an old date, because
         * only the second one is visible. */
        if ($next === null) {
            $next = $task['next_due_on'];
        }

        /* Counting from the due date can land in the past when the task is
         * badly overdue — "every month from a due date six months ago" is
         * still six months ago. Walk it forward so completing something does
         * not leave it instantly overdue again, which reads as the button
         * having done nothing. */
        if ($task['recur_kind'] === 'interval' && $task['interval_from'] === 'due') {
            $guard = 0;
            while ($next <= $completedOn && $guard < 200) {
                $step = recur_next_after(task_rule($task), $next);
                if ($step === null || $step === $next) {
                    break;
                }
                $next = $step;
                $guard++;
            }
        }

        q(
            'UPDATE maintenance_tasks SET next_due_on = ?, last_completed_on = ? WHERE id = ?',
            array($next, $completedOn, $id)
        );

        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $next;
}

/** Pause or resume. A paused task keeps its history and stops being due. */
function task_set_active(int $id, bool $active): bool
{
    if (task_get($id) === null) {
        return false;
    }
    q('UPDATE maintenance_tasks SET is_active = ? WHERE id = ?', array($active ? 1 : 0, $id));
    return true;
}

/** Delete a task and its completion history. */
function task_delete(int $id): bool
{
    if (task_get($id) === null) {
        return false;
    }
    q('DELETE FROM maintenance_tasks WHERE id = ?', array($id));
    return true;
}

/* ------------------------------------------------- dashboard and the cron */

/**
 * Tasks due on or before $through. THE DASHBOARD AND THE CRON BOTH READ THIS.
 *
 * A range scan on the indexed column. There is no DATE_ADD and no NOW() here
 * or anywhere near it — the date is computed in PHP and compared as a string,
 * which is what keeps the statement sargable and what lets the SQLite harness
 * evaluate it at all.
 *
 * @return list<array>
 */
function tasks_due(string $through): array
{
    $rows = q(
        'SELECT * FROM maintenance_tasks
          WHERE is_active = 1 AND next_due_on <= ?
          ORDER BY next_due_on, title',
        array($through)
    )->fetchAll() ?: array();

    return tasks_decorate(array_map('task_row', $rows));
}

/**
 * Every future occurrence of every active task, out to a horizon.
 *
 * THE DASHBOARD'S FORWARD TIMELINE (docs/CONTRACTS.md §10). A task stores only
 * its NEXT occurrence, so a quarterly filter change would appear once and the
 * stream would run dry after a month. This projects the rest.
 *
 * PROJECTED ROWS ARE PREDICTIONS, NOT STORED ROWS. Nothing is written.
 * Completing a task early moves everything after it, and the app has not
 * promised you these dates — which is why the renderer dims them.
 *
 * Every date comes from recur_next_after(), the same function task_complete()
 * uses, so the projection cannot show a date the app would never produce.
 *
 * @return list<array{on_date:string, id:int, title:string, projected:bool}>
 */
function tasks_project(string $after, string $until, int $cap = 400): array
{
    $rows = q(
        'SELECT * FROM maintenance_tasks WHERE is_active = 1 AND next_due_on <= ? ORDER BY next_due_on',
        array($until)
    )->fetchAll() ?: array();

    $out = array();
    foreach ($rows as $raw) {
        $task = task_row($raw);
        $rule = task_rule($task);

        $date      = $task['next_due_on'];
        $projected = false;
        $guard     = 0;

        while ($date !== null && $date <= $until && $guard < 60) {
            if ($date > $after) {
                $out[] = array(
                    'on_date'   => $date,
                    'id'        => $task['id'],
                    'title'     => $task['title'],
                    'projected' => $projected,
                );
            }
            $next = recur_next_after($rule, $date);
            /* A rule that cannot advance would loop forever on one date. Stop
             * rather than filling the timeline with the same row. */
            if ($next === null || $next === $date) {
                break;
            }
            $date      = $next;
            $projected = true;
            $guard++;
        }

        if (count($out) > $cap) {
            break;
        }
    }

    usort($out, static function (array $a, array $b): int {
        return array($a['on_date'], $a['id']) <=> array($b['on_date'], $b['id']);
    });

    return array_slice($out, 0, $cap);
}

/* ---------------------------------------------------------------- cleaning */

/**
 * Pull a usable recurrence rule out of whatever the form posted.
 *
 * The two shapes are mutually exclusive, so the columns belonging to the OTHER
 * shape are nulled rather than left behind. A task switched from seasonal to
 * interval that kept its recur_months would be a row whose rule depends on
 * which branch of recur_next_after() reads it first.
 */
function task_clean_rule(array $data): array
{
    $kind = ((string) ($data['recur_kind'] ?? 'interval')) === 'months' ? 'months' : 'interval';

    if ($kind === 'months') {
        $months = recur_parse_months($data['recur_months'] ?? '');
        return array(
            'recur_kind'     => 'months',
            'interval_count' => null,
            'interval_unit'  => null,
            'recur_months'   => $months === array() ? null : implode(',', $months),
            'recur_day'      => max(1, min(31, (int) ($data['recur_day'] ?? 1))),
        );
    }

    $unit  = (string) ($data['interval_unit'] ?? 'month');
    $count = (int) ($data['interval_count'] ?? 0);

    return array(
        'recur_kind'     => 'interval',
        'interval_count' => $count > 0 ? min($count, 999) : null,
        'interval_unit'  => in_array($unit, array('day', 'week', 'month', 'year'), true) ? $unit : null,
        'recur_months'   => null,
        'recur_day'      => null,
    );
}

/** 'completion' or 'due'. Anything else is 'completion', the safer default. */
function task_clean_from($raw): string
{
    return ((string) $raw) === 'due' ? 'due' : 'completion';
}

function task_clean_text($raw): ?string
{
    $text = trim((string) ($raw ?? ''));
    return $text === '' ? null : $text;
}

function task_clean_date($raw): ?string
{
    $date = sw_parse_date(is_string($raw) ? $raw : null);
    return $date === null ? null : $date->format('Y-m-d');
}
