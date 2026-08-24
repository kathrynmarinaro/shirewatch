<?php
/* The Service view: every time somebody was paid to do something to the house.
 *
 * ---------------------------------------------------------------------------
 * THE ONLY CODE THAT KNOWS THERE ARE TWO SOURCES.
 * ---------------------------------------------------------------------------
 *
 * "Service" is not a kind of row in this schema. It is something that can
 * happen in two places, because there are two ways work gets done:
 *
 *   UNPLANNED  the AC stops working, you call somebody. That visit is a
 *              log_updates row of kind 'service' — an event in the story of
 *              the thing that broke.
 *
 *   PLANNED    the annual HVAC service, the septic pump-out. That is a
 *              task_completions row that happens to carry a cost. It was
 *              never a problem, so filing it as a log entry would mean
 *              inventing one.
 *
 * Everywhere else in the app those stay apart, because they genuinely are
 * different: one is a story, the other is a schedule. This file is the one
 * place the question is "what did we pay, and to whom" — and there the
 * distinction stops mattering.
 *
 * NOTHING HERE WRITES. Both sources are owned by lib/log.php and lib/tasks.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/vendors.php';

/**
 * Every service event, newest first.
 *
 * A UNION of two same-shaped projections rather than a join: they share no key
 * and never will, and forcing them into one table was the mistake this whole
 * refactor undid.
 *
 * @param array $filters { year?: int, vendor_id?: int, search?: string }
 * @return list<array{
 *   source:string, id:int, on_date:string, title:string, note:string,
 *   vendor_id:?int, vendor_name:string, cost:?string, rating:?int,
 *   parent_id:int, parent_title:string
 * }>
 */
function service_list(array $filters = array()): array
{
    $where  = array();
    $params = array();

    if (!empty($filters['year'])) {
        /* A string range, not YEAR(col) — a function on the column is not
         * sargable, and the harness cannot evaluate it either. */
        $year     = (int) $filters['year'];
        $where[]  = 'ON_DATE BETWEEN ? AND ?';
        $params[] = $year . '-01-01';
        $params[] = $year . '-12-31';
    }
    if (!empty($filters['vendor_id'])) {
        $where[]  = 'VENDOR_ID = ?';
        $params[] = (int) $filters['vendor_id'];
    }

    $search = trim((string) ($filters['search'] ?? ''));

    /* ---- unplanned: service updates on a log entry --------------------- */

    $sqlA = "SELECT u.id, u.noted_on AS on_date, u.note, u.vendor_id, u.vendor_name,
                    u.cost, u.rating, e.id AS parent_id, e.title AS parent_title
               FROM log_updates u
               JOIN log_entries e ON e.id = u.entry_id
              WHERE u.kind = 'service'";
    $paramsA = array();
    foreach ($where as $clause) {
        $sqlA .= ' AND ' . str_replace(array('ON_DATE', 'VENDOR_ID'), array('u.noted_on', 'u.vendor_id'), $clause);
    }
    $paramsA = array_merge($paramsA, $params);
    if ($search !== '') {
        $sqlA .= ' AND (u.note LIKE ? OR u.vendor_name LIKE ? OR e.title LIKE ?)';
        $like = '%' . $search . '%';
        $paramsA[] = $like; $paramsA[] = $like; $paramsA[] = $like;
    }

    /* ---- planned: completions that cost something or named somebody ---- */

    /* A completion with NEITHER a cost nor a vendor is you doing it yourself,
     * which is not a service event. It belongs on the task's history, not
     * here. */
    $sqlB = "SELECT c.id, c.completed_on AS on_date, c.note, c.vendor_id, c.vendor_name,
                    c.cost, c.rating, t.id AS parent_id, t.title AS parent_title
               FROM task_completions c
               JOIN maintenance_tasks t ON t.id = c.task_id
              WHERE (c.vendor_id IS NOT NULL OR c.vendor_name IS NOT NULL OR c.cost IS NOT NULL)";
    $paramsB = array();
    foreach ($where as $clause) {
        $sqlB .= ' AND ' . str_replace(array('ON_DATE', 'VENDOR_ID'), array('c.completed_on', 'c.vendor_id'), $clause);
    }
    $paramsB = array_merge($paramsB, $params);
    if ($search !== '') {
        $sqlB .= ' AND (c.note LIKE ? OR c.vendor_name LIKE ? OR t.title LIKE ?)';
        $like = '%' . $search . '%';
        $paramsB[] = $like; $paramsB[] = $like; $paramsB[] = $like;
    }

    $out = array();
    foreach (q($sqlA, $paramsA)->fetchAll() ?: array() as $row) {
        $out[] = service_row($row, 'log');
    }
    foreach (q($sqlB, $paramsB)->fetchAll() ?: array() as $row) {
        $out[] = service_row($row, 'maintenance');
    }

    /* Sorted in PHP rather than by a SQL UNION: the two halves have different
     * date column names and different joins, and a UNION would force both into
     * one shape at the cost of being able to read either query. At the scale
     * of one household's repair history this is a few hundred rows. */
    usort($out, static function (array $a, array $b): int {
        return array($b['on_date'], $b['source'], $b['id'])
           <=> array($a['on_date'], $a['source'], $a['id']);
    });

    return $out;
}

function service_row(array $row, string $source): array
{
    return array(
        'source'       => $source,
        'id'           => (int) $row['id'],
        'on_date'      => (string) $row['on_date'],
        'note'         => $row['note'] !== null ? (string) $row['note'] : '',
        'title'        => service_title($row),
        'vendor_id'    => $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
        'vendor_name'  => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : '',
        'cost'         => $row['cost'] !== null ? (string) $row['cost'] : null,
        'rating'       => $row['rating'] !== null ? (int) $row['rating'] : null,
        'parent_id'    => (int) $row['parent_id'],
        'parent_title' => (string) $row['parent_title'],
    );
}

/**
 * What to call this visit in a list.
 *
 * The note's first line when there is one — "Replaced the capacitor" says more
 * than "AC not cooling" does about what happened on the day. Falls back to the
 * parent's title, which is always there.
 */
function service_title(array $row): string
{
    $note = trim((string) ($row['note'] ?? ''));
    if ($note !== '') {
        $first = trim((string) strtok($note, "\n"));
        if ($first !== '') {
            return mb_substr($first, 0, 120, 'UTF-8');
        }
    }
    return (string) $row['parent_title'];
}

/**
 * Years that have service in them, newest first — for the year filter.
 *
 * @return list<int>
 */
function service_years(): array
{
    $years = array();

    foreach (array(
        "SELECT noted_on AS d FROM log_updates WHERE kind = 'service'",
        'SELECT completed_on AS d FROM task_completions
          WHERE vendor_id IS NOT NULL OR vendor_name IS NOT NULL OR cost IS NOT NULL',
    ) as $sql) {
        foreach (q($sql)->fetchAll(PDO::FETCH_COLUMN) ?: array() as $date) {
            $year = (int) substr((string) $date, 0, 4);
            if ($year > 0 && !in_array($year, $years, true)) {
                $years[] = $year;
            }
        }
    }

    rsort($years);
    return $years;
}

/**
 * What a set of service rows cost, and how many had no cost recorded.
 *
 * UNPRICED ROWS ARE COUNTED, NOT SUMMED AS ZERO. "Not recorded" is not "free",
 * and a total that quietly treats them as nothing is a number you would trust
 * and shouldn't.
 *
 * @return array{total: float, priced: int, unpriced: int}
 */
function service_total(array $rows): array
{
    $total = 0.0;
    $priced = 0;
    $unpriced = 0;

    foreach ($rows as $row) {
        if ($row['cost'] === null) {
            $unpriced++;
            continue;
        }
        $total += (float) $row['cost'];
        $priced++;
    }

    return array('total' => $total, 'priced' => $priced, 'unpriced' => $unpriced);
}
