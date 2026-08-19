<?php
/* The daily reminder job.
 *
 *   php tools/cron-reminders.php [--dry-run] [--today=YYYY-MM-DD]
 *
 * Once a day, early. public/cron.php is a thin wrapper around the same
 * function for plans that only offer a URL fetch.
 *
 * ---------------------------------------------------------------------------
 * ONE CLOCK PER RUN.
 * ---------------------------------------------------------------------------
 *
 * sw_today() is called ONCE, at the top, and passed down. A run that straddles
 * midnight must not decide a task is due against one date and record the send
 * against another — the send ledger is keyed on (task_id, due_on), so a
 * mismatch there means the same email again tomorrow.
 *
 * --today exists for testing and for the morning you need to re-run yesterday.
 *
 * ---------------------------------------------------------------------------
 * IT DOES NOT MOVE ANY DUE DATE. NOT ONE.
 * ---------------------------------------------------------------------------
 *
 * task_complete() is the only thing that advances next_due_on (lib/tasks.php).
 * This job writes to task_reminder_sends and to nothing else in the schedule.
 *
 * That is what makes an overdue task email ONCE and then sit on the dashboard
 * getting louder: tomorrow's run claims the same (task_id, due_on) pair, finds
 * it delivered, and stays quiet — while the task is still overdue and still
 * visible. Advancing it here would mean the app quietly forgives you for not
 * flushing the water heater.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/tasks.php';
require_once __DIR__ . '/../lib/mailer.php';

/**
 * Run the job. Returns a summary for the caller to print or log.
 *
 * Declared as a function and only run below when this file is the entry
 * point, so public/cron.php gets the IDENTICAL code path by requiring it.
 * Two copies of a job that runs unattended once a day is two copies of which
 * only one ever gets fixed.
 *
 * @return array{due:int, to_send:int, sent:int, skipped:int, failed:int, error:string}
 */
function cron_reminders_run(string $today, bool $dryRun = false): array
{
    $lead = max(0, (int) cfg('reminders.lead_days', 7));
    $cap  = max(1, (int) cfg('reminders.max_per_run', 10));

    /* Everything due within the lead window, INCLUDING everything already
     * overdue. The overdue ones are the reason this app exists; they are also
     * the ones already emailed about, and the ledger is what keeps them quiet
     * rather than a date filter. */
    $through = (new DateTimeImmutable($today))->modify('+' . $lead . ' days')->format('Y-m-d');
    $due     = tasks_due($through);

    $summary = array(
        'due'     => count($due),
        'to_send' => 0,
        'sent'    => 0,
        'skipped' => 0,
        'failed'  => 0,
        'error'   => '',
    );

    /* CLAIM FIRST, SEND SECOND. Every task that has not already been emailed
     * about for THIS due date is claimed up front, then one digest goes out
     * covering all of them.
     *
     * The claim is an INSERT ... ON DUPLICATE KEY UPDATE against a table whose
     * PRIMARY KEY is (task_id, due_on), so the database itself makes a second
     * row impossible. A cron that double-fires — which Hostinger's does — sees
     * the second run claim nothing. */
    $claimed = array();
    foreach ($due as $task) {
        if (count($claimed) >= $cap) {
            break;
        }
        if (!$dryRun && !reminder_claim($task['id'], $task['next_due_on'])) {
            $summary['skipped']++;
            continue;
        }
        if ($dryRun && reminder_already_sent($task['id'], $task['next_due_on'])) {
            $summary['skipped']++;
            continue;
        }
        $claimed[] = $task;
    }

    $summary['to_send'] = count($claimed);

    if ($claimed === array() || $dryRun) {
        return $summary;
    }

    $ok = send_task_reminder($claimed, $today);

    if ($ok) {
        $sentAt = date('Y-m-d H:i:s');
        foreach ($claimed as $task) {
            reminder_mark_sent($task['id'], $task['next_due_on'], $sentAt);
        }
        $summary['sent'] = count($claimed);
        return $summary;
    }

    /* A FAILED SEND LEAVES sent_at NULL, so tomorrow's run claims the same
     * pairs and tries again. A hung SMTP connection must not cost you the
     * reminder — which is why the claim is not itself the record of delivery. */
    $summary['failed'] = count($claimed);
    $summary['error']  = mailer_last_error();
    foreach ($claimed as $task) {
        reminder_mark_failed($task['id'], $task['next_due_on'], $summary['error']);
    }
    error_log('cron-reminders: send failed — ' . $summary['error']);

    return $summary;
}

/**
 * Claim one (task, due date) for sending. False if it is already delivered.
 *
 * THE EXACT STATEMENT MATTERS. INSERT ... ON DUPLICATE KEY UPDATE against the
 * composite primary key, written in MySQL and translated for SQLite on the way
 * through the connection (tools/test-harness.php).
 *
 * It answers false only for a row that has ALREADY BEEN DELIVERED. A previous
 * attempt that failed left sent_at NULL, and retrying it today is the intended
 * behaviour.
 */
function reminder_claim(int $taskId, string $dueOn): bool
{
    q(
        'INSERT INTO task_reminder_sends (task_id, due_on, attempts) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1',
        array($taskId, $dueOn)
    );

    return !reminder_already_sent($taskId, $dueOn);
}

function reminder_already_sent(int $taskId, string $dueOn): bool
{
    $row = q(
        'SELECT sent_at FROM task_reminder_sends WHERE task_id = ? AND due_on = ?',
        array($taskId, $dueOn)
    )->fetch();

    return $row !== false && $row['sent_at'] !== null && $row['sent_at'] !== '';
}

/**
 * Record a delivery.
 *
 * The timestamp comes from the caller rather than from NOW(), keeping the one
 * clock rule intact — and it is the answer to "when did that email actually
 * go", which is a question asked exactly once, in a panic.
 */
function reminder_mark_sent(int $taskId, string $dueOn, string $sentAt): void
{
    q(
        'UPDATE task_reminder_sends SET sent_at = ?, last_error = NULL WHERE task_id = ? AND due_on = ?',
        array($sentAt, $taskId, $dueOn)
    );
}

/** Record a failure. sent_at stays NULL, so tomorrow tries again. */
function reminder_mark_failed(int $taskId, string $dueOn, string $error): void
{
    q(
        'UPDATE task_reminder_sends SET last_error = ? WHERE task_id = ? AND due_on = ?',
        array(mb_substr($error, 0, 255, 'UTF-8'), $taskId, $dueOn)
    );
}

/* --------------------------------------------------------------- entry point */

/* Only when this file IS the script being run. Required from public/cron.php,
 * this block does nothing and the function above is what gets called. */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $dryRun = in_array('--dry-run', $argv, true);
    $today  = sw_today();

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--today=')) {
            $given = substr($arg, 8);
            if (sw_parse_date($given) !== null) {
                $today = $given;
            }
        }
    }

    $result = cron_reminders_run($today, $dryRun);

    printf(
        "[%s] %s%d due, %d to send, %d sent, %d already handled, %d failed%s\n",
        $today,
        $dryRun ? 'DRY RUN — ' : '',
        $result['due'],
        $result['to_send'],
        $result['sent'],
        $result['skipped'],
        $result['failed'],
        $result['error'] === '' ? '' : ' — ' . $result['error']
    );

    exit($result['failed'] > 0 ? 1 : 0);
}
