<?php
/* Install the generic starter maintenance list.
 *
 *   php tools/install-starter-tasks.php [--dry-run]
 *
 * Run ONCE at setup. Reads data/starter-tasks.php and creates a maintenance
 * task for each, with its first due date computed FORWARD FROM TODAY by the
 * real recurrence engine — so a fresh install opens on a calendar of upcoming
 * work rather than on twenty things that are already overdue.
 *
 * IDEMPOTENT ON TITLE. Running it twice adds nothing, and a task you renamed
 * or deleted stays that way: this never resurrects one and never overwrites
 * your edits. That matters because the natural instinct after deleting half
 * the list is to wonder whether you can get it back, and re-running a setup
 * script is what you would try.
 *
 * Everything it creates is ordinary data with no marking on it. Nothing in the
 * app treats a seeded task differently from one you typed — same rule as the
 * seeded rooms and Grocery's four seeded stores. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/tags.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$today  = sw_today();
$starters = require __DIR__ . '/../data/starter-tasks.php';

if (!is_array($starters) || $starters === array()) {
    fatal_error('starter_list_empty', 'data/starter-tasks.php returned nothing.');
}

$created = 0;
$skipped = 0;
$warned  = array();

foreach ($starters as $spec) {
    $title = (string) ($spec['title'] ?? '');
    if ($title === '') {
        continue;
    }

    $exists = q(
        'SELECT id FROM maintenance_tasks WHERE property_id = ? AND title = ?',
        array(1, $title)
    )->fetch();
    if ($exists !== false) {
        $skipped++;
        continue;
    }

    /* Build the row the recurrence engine expects, then ask it for the first
     * date. Going through recur_next_after() rather than computing a date here
     * is the point: the first due date and every one after it are then produced
     * by the same function, so they cannot disagree. */
    $row = array('recur_kind' => (string) ($spec['recur_kind'] ?? 'interval'));

    if ($row['recur_kind'] === 'months') {
        $row['recur_months'] = (string) ($spec['months'] ?? '');
        $row['recur_day']    = (int) ($spec['day'] ?? 1);
    } else {
        list($count, $unit)     = $spec['interval'] ?? array(1, 'year');
        $row['interval_count']  = (int) $count;
        $row['interval_unit']   = (string) $unit;
    }

    $due = recur_next_after($row, $today);
    if ($due === null) {
        $warned[] = $title . ': recurrence rule produced no date, skipped';
        continue;
    }

    if ($dryRun) {
        printf("  would add  %-38s  first due %s  (%s)\n", $title, $due, recur_describe($row));
        $created++;
        continue;
    }

    q(
        'INSERT INTO maintenance_tasks
           (property_id, title, instructions, recur_kind, interval_count, interval_unit,
            interval_from, recur_months, recur_day, next_due_on, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
        array(
            1,
            $title,
            (string) ($spec['instructions'] ?? ''),
            $row['recur_kind'],
            $row['interval_count'] ?? null,
            $row['interval_unit'] ?? null,
            'completion',
            $row['recur_months'] ?? null,
            $row['recur_day'] ?? null,
            $due,
        )
    );
    $taskId = (int) db()->lastInsertId();

    /* Tags are matched by NAME against what schema.sql seeded. A name that
     * matches nothing is warned about and skipped rather than created — a typo
     * in the starter list should not quietly grow a 25th room. */
    $tagIds = array();
    foreach (array(TAG_LOCATION => 'location', TAG_CATEGORY => 'category') as $kind => $key) {
        $name = trim((string) ($spec[$key] ?? ''));
        if ($name === '') {
            continue;
        }
        $tag = tag_find($kind, $name);
        if ($tag === null) {
            $warned[] = $title . ': no ' . $key . ' tag named "' . $name . '"';
            continue;
        }
        $tagIds[] = $tag['id'];
    }
    if ($tagIds !== array()) {
        tags_set('task', $taskId, $tagIds);
    }

    printf("  added  %-38s  first due %s\n", $title, $due);
    $created++;
}

printf("\n%s%d task%s, %d already present.\n",
    $dryRun ? 'DRY RUN — would add ' : 'Added ',
    $created,
    $created === 1 ? '' : 's',
    $skipped
);

foreach ($warned as $warning) {
    fwrite(STDERR, "  ! " . $warning . "\n");
}

if ($skipped > 0 && $created === 0) {
    printf("Nothing to do. Deleted tasks are NOT restored by re-running this.\n");
}
