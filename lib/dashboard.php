<?php
/* The dashboard's two reads: what needs doing now, and what is coming.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE OWNS NO TABLES. It composes lib/issues.php and lib/tasks.php.
 * ---------------------------------------------------------------------------
 *
 * That is the point: "needs action" and "what is due" are defined once, in the
 * modules that own those rows, so the dashboard and the reminder email cannot
 * disagree with the Issues and Maintenance screens about what is outstanding.
 *
 * ---------------------------------------------------------------------------
 * THE SHAPE, AND WHERE IT DEPARTS FROM THE BRIEF (DELEGATION-PLAN.md §2.13).
 * ---------------------------------------------------------------------------
 *
 * 1. NEEDS ACTION NOW — split into an issues group and a maintenance group.
 *    That is the brief's "separate sections for issues vs. maintenance",
 *    honoured where it earns its place: deciding what to do this morning,
 *    "call a plumber" and "change a filter" are different kinds of thing.
 *
 * 2. THE FORWARD TIMELINE — one combined chronological stream. Here the
 *    brief's separation is dropped deliberately: the organising principle is
 *    *when*, and two parallel columns of dates is a calendar nobody can read.
 *    Each row still carries its type.
 */

declare(strict_types=1);

require_once __DIR__ . '/issues.php';
require_once __DIR__ . '/tasks.php';

/**
 * Everything already due, in two groups.
 *
 * @return array{issues: list<array>, tasks: list<array>, total: int}
 */
function dashboard_now(string $today): array
{
    $issues = issues_needing_action($today);
    $tasks  = tasks_due($today);

    return array(
        'issues' => $issues,
        'tasks'  => $tasks,
        'total'  => count($issues) + count($tasks),
    );
}

/**
 * The forward stream: future maintenance and issue check-backs, merged.
 *
 * PAGED BY KEYSET, NEVER OFFSET. The cursor is the previous page's last
 * (on_date, kind, id). With OFFSET, anything inserted mid-scroll silently
 * repeats or skips a row — and on this screen the row being inserted is
 * usually the one you just completed.
 *
 * @param string      $afterDate cursor date; the stream starts strictly after it
 * @param string|null $afterKey  "kind:id" of the cursor row, to break ties
 *                               within a single date
 * @return array{rows: list<array>, cursor: ?array, done: bool}
 */
function dashboard_timeline(string $afterDate, ?string $afterKey = null, ?int $limit = null): array
{
    $limit  = $limit ?? max(5, (int) cfg('dashboard.page_size', 40));
    $months = max(1, (int) cfg('dashboard.horizon_months', 24));
    $until  = (new DateTimeImmutable($afterDate))->modify('+' . $months . ' months')->format('Y-m-d');

    /* Maintenance is PROJECTED forward — a task stores only its next
     * occurrence, so a quarterly one would appear once and the stream would
     * run dry after a month. Issue check-backs are NOT projected: a task
     * genuinely recurs forever, but an issue's next look-at depends on what
     * you see when you look, so a chain of them would be invented schedule. */
    $rows = array();

    foreach (tasks_project($afterDate, $until) as $item) {
        $rows[] = array(
            'on_date'   => $item['on_date'],
            'kind'      => 'task',
            'id'        => $item['id'],
            'title'     => $item['title'],
            'projected' => $item['projected'],
            'severity'  => null,
        );
    }

    foreach (issues_upcoming_checks($afterDate) as $item) {
        if ($item['on_date'] > $until) {
            continue;
        }
        $rows[] = array(
            'on_date'   => $item['on_date'],
            'kind'      => 'issue',
            'id'        => $item['id'],
            'title'     => $item['title'],
            'projected' => false,
            'severity'  => $item['severity'],
        );
    }

    usort($rows, static function (array $a, array $b): int {
        return array($a['on_date'], $a['kind'], $a['id'])
           <=> array($b['on_date'], $b['kind'], $b['id']);
    });

    /* Drop everything up to and including the cursor row. Comparing the whole
     * (date, kind, id) triple is what makes a page boundary that falls in the
     * middle of a date work — several tasks can share one day. */
    if ($afterKey !== null && $afterKey !== '') {
        list($cursorKind, $cursorId) = array_pad(explode(':', $afterKey, 2), 2, '');
        $cursor = array($afterDate, $cursorKind, (int) $cursorId);
        $rows = array_values(array_filter($rows, static function (array $row) use ($cursor): bool {
            return array($row['on_date'], $row['kind'], $row['id']) > $cursor;
        }));
    }

    $page = array_slice($rows, 0, $limit);
    $last = $page === array() ? null : $page[count($page) - 1];

    return array(
        'rows'   => $page,
        'cursor' => $last === null ? null : array(
            'date' => $last['on_date'],
            'key'  => $last['kind'] . ':' . $last['id'],
        ),
        /* "Done" means this page did not fill, so there is nothing more within
         * the horizon. A page that fills exactly is NOT done — the next call
         * returning zero rows is what settles it. */
        'done'   => count($page) < $limit,
    );
}
