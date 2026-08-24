<?php
/* Generate a plausible house of test data.
 *
 *   php tools/seed.php [--reset]
 *
 * ---------------------------------------------------------------------------
 * THIS IS WHAT UNBLOCKS THE MODULE PASSES.
 * ---------------------------------------------------------------------------
 *
 * Every screen in this app is a list of something, and a list screen built
 * against an empty database cannot be judged at all: you cannot see that the
 * severity pills are illegible at phone size, that the timeline's sticky date
 * headings collide, or that a two-line title wraps under the tag chips. So the
 * data below is shaped to put those cases on the screen deliberately rather
 * than leaving them to be discovered in production, one at a time.
 *
 * What it deliberately includes:
 *   · log entries in all four states, including two resolved by the service
 *     visit that fixed them and one dismissed
 *   · a THREE-YEAR timeline on one entry, so the progression view has
 *     something to actually show
 *   · BOTH KINDS OF UPDATE on the same timelines — notes and paid visits —
 *     because the whole point of the merge is that they interleave
 *   · severities across all four levels AND several deliberately unset, which
 *     is the case that renders nothing (schema.sql)
 *   · tasks in BOTH recurrence shapes, several overdue by different amounts,
 *     one due today, one due tomorrow
 *   · a vendor with no rated jobs (so the average is NULL), one with a manual
 *     override, and one with no work at all
 *   · paid work in BOTH the places it can happen: service updates on log
 *     entries, and maintenance completions that cost money. The Service
 *     screen reads across both, so a seed with only one half would make a
 *     broken query look like a short list
 *   · service events with and without a cost, with and without a vendor
 *   · a long title and a long instruction block, because those are what break
 *     a layout
 *
 * --reset DELETES EVERY ROW IN THE APP'S TABLES FIRST. It refuses to run on a
 * database whose media rows point at files, since that is the signature of a
 * real install rather than a scratch one.
 *
 * NEVER UPLOAD THIS FILE. tools/.htaccess denies the directory over HTTP and
 * tools/build-deploy.php keeps it out of the bundle, but it writes directly to
 * the database and the only correct number of ways to reach it from a browser
 * is zero. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/tags.php';
require_once __DIR__ . '/../lib/media.php';
require_once __DIR__ . '/../lib/log.php';

$reset = in_array('--reset', array_slice($argv, 1), true);
$today = sw_today();

/* ------------------------------------------------------------------ reset */

if ($reset) {
    $real = (int) q("SELECT COUNT(*) FROM media WHERE original_path IS NOT NULL")->fetchColumn();
    $seeded = (int) q("SELECT COUNT(*) FROM media WHERE caption = 'seed'")->fetchColumn();
    if ($real > $seeded) {
        fatal_error(
            'refusing_reset',
            "There are $real media rows with files behind them and only $seeded look seeded.\n"
            . "That looks like a real install. Refusing to wipe it.\n"
            . "If you are certain, empty the tables by hand."
        );
    }

    /* Children before parents even though the cascades would handle it: this
     * has to work on SQLite too, where foreign keys are off unless switched on
     * per connection, and a delete order that only works with them enabled is
     * one that silently leaves orphans in the test harness. */
    foreach (array(
        'media', 'entry_tags', 'task_tags', 'vendor_tags',
        'log_updates', 'task_completions', 'task_reminder_sends',
        'log_entries', 'maintenance_tasks', 'vendors',
    ) as $table) {
        q("DELETE FROM $table");
    }
    echo "Cleared.\n";
}

/** Y-m-d, $days ago. */
function ago(int $days): string
{
    return (new DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d');
}
/** Y-m-d, $days ahead. */
function ahead(int $days): string
{
    return (new DateTimeImmutable('+' . $days . ' days'))->format('Y-m-d');
}
function tag_id_for(string $kind, string $name): ?int
{
    $tag = tag_find($kind, $name);
    return $tag === null ? null : $tag['id'];
}
/** @param list<array{0:string,1:string}> $pairs */
function attach_tags(string $type, int $id, array $pairs): void
{
    $ids = array();
    foreach ($pairs as $pair) {
        $tagId = tag_id_for($pair[0], $pair[1]);
        if ($tagId !== null) {
            $ids[] = $tagId;
        }
    }
    tags_set($type, $id, $ids);
}

/* ---------------------------------------------------------------- vendors */

$vendors = array(
    array('Brightwater Plumbing', '(512) 555-0142', 'office@brightwater.example', 'Ask for Danny. Charges a trip fee outside business hours.', null, array('Plumber')),
    array('Hill Country HVAC',    '(512) 555-0188', 'service@hchvac.example',     '', null, array('HVAC')),
    array('Marisol Roofing',      '(512) 555-0119', '',                            "Did the 2024 hail inspection. Slow to call back but the work held up.", 5, array('Roofer')),
    array('Ace Handyman',         '(512) 555-0170', '',                            '', null, array('Handyman', 'Painter')),
    array('Ridgeline Septic',     '(512) 555-0103', '',                            'Three-year contract, they call to schedule.', null, array('Septic Service')),
    array('Nobody Yet',           '',               '',                            'Recommended by the neighbours, never used.', null, array('Electrician')),
);
$vendorIds = array();
foreach ($vendors as $v) {
    q('INSERT INTO vendors (property_id, name, phone, email, notes, rating_override) VALUES (?, ?, ?, ?, ?, ?)',
        array(1, $v[0], $v[1] ?: null, $v[2] ?: null, $v[3] ?: null, $v[4]));
    $id = (int) db()->lastInsertId();
    $vendorIds[$v[0]] = $id;
    attach_tags('vendor', $id, array_map(static fn(string $w): array => array(TAG_WORK_TYPE, $w), $v[5]));
}
printf("%d vendors\n", count($vendorIds));

/* ------------------------------------------------------------- log entries */

/* title, description, status, severity, noticed days ago, check interval,
   last checked days ago, [location, category], [ [days ago, note, severity], … ] */
$entries = array(
    array(
        'Hairline crack in the library ceiling, north-west corner',
        "Noticed it after the storm. About 30cm long. Photographed against the light switch for scale.",
        'watching', 2, 1100, 180, 95,
        array(array(TAG_LOCATION, 'Library'), array(TAG_CATEGORY, 'Structural')),
        array(
            array(1100, 'First noticed. Roughly 30cm, hairline.', 1),
            array(730,  'No obvious change over the winter.', 1),
            array(400,  'Slightly longer — reaches the cornice now. Marked the end with a pencil.', 2),
            array(95,   'Pencil mark is 4cm behind the end of the crack. It is moving.', 2),
        ),
    ),
    array(
        'Slow drip under the kitchen sink',
        'Only when the dishwasher is running. Bowl underneath for now.',
        'active', 3, 40, null, null,
        array(array(TAG_LOCATION, 'Kitchen'), array(TAG_CATEGORY, 'Plumbing')),
        array(
            array(40, 'Started sometime this week. Maybe a cup a day.', 2),
            array(12, 'Worse. Filling the bowl in about a day and a half now.', 3),
        ),
    ),
    array(
        'Damp patch on the Space Bathroom ceiling',
        'Directly under the roof valley. Grows after heavy rain and dries out in a few days.',
        'watching', 3, 220, 90, 20,
        array(array(TAG_LOCATION, 'Space Bathroom'), array(TAG_LOCATION, 'Roof'), array(TAG_CATEGORY, 'Roofing')),
        array(
            array(220, 'First appeared after the March storms.', 2),
            array(120, 'Back again. Same shape, same place.', 3),
            array(20,  'Dried out completely this time. Might be the flashing rather than the shingles.', 3),
        ),
    ),
    array(
        'Garage door reverses halfway',
        'Sensor alignment, probably. Intermittent.',
        'active', 2, 65, 30, 60,
        array(array(TAG_LOCATION, 'Garage'), array(TAG_CATEGORY, 'Electrical')),
        array(array(65, 'Started doing this maybe twice a week.', 2)),
    ),
    array(
        'Wasp nest under the Ext Studio eave',
        '',
        'resolved', 4, 300, null, null,
        array(array(TAG_LOCATION, 'Ext Studio'), array(TAG_CATEGORY, 'Pest')),
        array(array(300, 'About the size of a fist and growing.', 4)),
    ),
    array(
        'Sticking window latch in the Guest Room',
        'Swollen frame, probably seasonal.',
        'dismissed', 1, 180, null, null,
        array(array(TAG_LOCATION, 'Guest Room'), array(TAG_CATEGORY, 'Interior')),
        array(array(90, 'Opens fine now it has dried out. Not a problem.', 1)),
    ),
    array(
        'Gutter overflowing at the south-east downpipe',
        '',
        'resolved', null, 420, null, null,
        array(array(TAG_LOCATION, 'Gutters'), array(TAG_CATEGORY, 'Roofing')),
        array(),
    ),
    array(
        'Faint smell in the Coat Closet after rain',
        'Cannot find a source. Nothing visible, nothing damp to the touch.',
        'watching', null, 55, 60, 55,
        array(array(TAG_LOCATION, 'Coat Closet')),
        array(array(55, 'Noticed it twice now, both times a day or two after rain.', null)),
    ),
);

$entryIds = array();
$updateIds = array();          // [entry index][update index] => log_updates.id

foreach ($entries as $spec) {
    list($title, $desc, $status, $severity, $noticedAgo, $interval, $checkedAgo, $tags, $updates) = $spec;

    $lastChecked = $checkedAgo === null ? null : ago($checkedAgo);
    /* next_check_on is STORED, never derived — the dashboard and the timeline
     * both range-scan it (schema.sql). Computed here the same way the app
     * computes it on every check-in. */
    $nextCheck = ($interval === null || $lastChecked === null)
        ? null
        : (new DateTimeImmutable($lastChecked))->modify('+' . $interval . ' days')->format('Y-m-d');

    q('INSERT INTO log_entries (property_id, title, description, status, severity, noticed_on,
                                check_interval_days, last_checked_on, next_check_on, resolved_on)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array(1, $title, $desc ?: null, $status, $severity, ago($noticedAgo),
              $interval, $lastChecked, $nextCheck,
              in_array($status, array('resolved', 'dismissed'), true) ? ago(max(1, (int) ($noticedAgo / 3))) : null));

    $entryId = (int) db()->lastInsertId();
    $index = count($entryIds);
    $entryIds[] = $entryId;
    $updateIds[$index] = array();
    attach_tags('entry', $entryId, $tags);

    foreach ($updates as $u) {
        q("INSERT INTO log_updates (entry_id, kind, noted_on, note, severity)
           VALUES (?, 'note', ?, ?, ?)",
            array($entryId, ago($u[0]), $u[1], $u[2]));
        $updateId = (int) db()->lastInsertId();
        $updateIds[$index][] = $updateId;
        seed_photo('update', $updateId, $u[0]);
    }
    seed_photo('entry', $entryId, $noticedAgo);
}
printf("%d log entries\n", count($entryIds));

/* ------------------------------------------------------------------ tasks */

$starters = require __DIR__ . '/../data/starter-tasks.php';
$taskIds  = array();
$i        = 0;

foreach ($starters as $spec) {
    $row = array('recur_kind' => (string) $spec['recur_kind']);
    if ($row['recur_kind'] === 'months') {
        $row['recur_months'] = (string) $spec['months'];
        $row['recur_day']    = (int) $spec['day'];
    } else {
        list($c, $u) = $spec['interval'];
        $row['interval_count'] = (int) $c;
        $row['interval_unit']  = (string) $u;
    }

    /* Spread the due dates deliberately: the first few OVERDUE by different
     * amounts, then today, then tomorrow, then into the future. An "all due
     * next year" seed makes the dashboard look finished when it is empty. */
    $offsets = array(-34, -12, -3, 0, 1, 4, 9, 21, 46);
    $due = isset($offsets[$i])
        ? ($offsets[$i] < 0 ? ago(-$offsets[$i]) : ahead($offsets[$i]))
        : (recur_next_after($row, $today) ?? ahead(60 + $i));

    $lastDone = $i % 3 === 0 ? ago(90 + $i * 7) : null;

    q('INSERT INTO maintenance_tasks
         (property_id, title, instructions, recur_kind, interval_count, interval_unit,
          interval_from, recur_months, recur_day, next_due_on, last_completed_on, is_active)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array(1, $spec['title'], $spec['instructions'] ?? '', $row['recur_kind'],
              $row['interval_count'] ?? null, $row['interval_unit'] ?? null, 'completion',
              $row['recur_months'] ?? null, $row['recur_day'] ?? null,
              $due, $lastDone, $i === 17 ? 0 : 1));

    $taskId = (int) db()->lastInsertId();
    $taskIds[] = $taskId;

    $pairs = array();
    if (!empty($spec['location'])) { $pairs[] = array(TAG_LOCATION, $spec['location']); }
    if (!empty($spec['category'])) { $pairs[] = array(TAG_CATEGORY, $spec['category']); }
    attach_tags('task', $taskId, $pairs);

    if ($lastDone !== null) {
        q('INSERT INTO task_completions (task_id, completed_on, note) VALUES (?, ?, ?)',
            array($taskId, $lastDone, $i === 0 ? 'Took ten minutes. Filters are on the shelf above the dryer.' : null));
    }
    $i++;
}
printf("%d maintenance tasks\n", count($taskIds));

/* -------------------------------------------------- paid work, both halves */

/* SERVICE IS NOT A TABLE ANY MORE, so this seeds both places it can happen.
 * A seed with only one half would make a broken query on the Service screen
 * look like a short list rather than a bug — which is exactly the failure the
 * merge introduced the possibility of. */

/* ---- unplanned: a visit on the timeline of the thing that broke --------- */

/* entry index, days ago, note, vendor, cost, rating, severity after */
$visits = array(
    /* An open entry with a visit already on it: called somebody, still not
     * fixed. This is the case the merge exists for. */
    array(1, 8,   'Emergency call-out, burst hose bib. Capped it, coming back for the valve.',
          'Brightwater Plumbing', 460.00, 2, 3),
    array(2, 15,  'Replaced flashing in the roof valley. Watching to see if it comes back.',
          'Marisol Roofing', 1240.00, 5, null),
    array(4, 295, 'Removed the nest and sealed the gap under the eave.', 'Ace Handyman', 95.00, 4, null),
    /* No cost recorded — the Service screen must count this row without
     * summing it as zero. */
    array(6, 415, 'Cleared the downpipe.', 'Ace Handyman', null, 3, null),
    /* Somebody not in the directory. The name is kept anyway. */
    array(7, 30,  'Damp meter reading behind the closet wall — nothing.',
          "Neighbour's brother", 0, null, null),
);

$visitCount = 0;
$fixedBy = array();            // entry index => the update that closed it

foreach ($visits as $v) {
    list($entryIndex, $daysAgo, $note, $vendorName, $cost, $rating, $severity) = $v;
    if (!isset($entryIds[$entryIndex])) {
        continue;
    }
    $vendorId = $vendorIds[$vendorName] ?? null;

    q("INSERT INTO log_updates
         (entry_id, kind, noted_on, note, severity, vendor_id, vendor_name, cost, rating)
       VALUES (?, 'service', ?, ?, ?, ?, ?, ?, ?)",
        array($entryIds[$entryIndex], ago($daysAgo), $note, $severity,
              $vendorId, $vendorName, $cost, $rating));

    $updateId = (int) db()->lastInsertId();
    $visitCount++;
    $fixedBy[$entryIndex] = $updateId;

    seed_photo('update', $updateId, $daysAgo);
    if ($cost !== null) {
        seed_document('update', $updateId, 'invoice-' . $updateId . '.pdf');
    }
}

/* Two entries were closed BY one of their own updates. This is the link that
 * makes a timeline end with what fixed it (schema.sql), and it is always
 * optional — the other resolved entries have no update attached. */
foreach (array(4, 6) as $closed) {
    if (isset($fixedBy[$closed], $entryIds[$closed])) {
        q('UPDATE log_entries SET resolved_by_update_id = ? WHERE id = ?',
            array($fixedBy[$closed], $entryIds[$closed]));
    }
}

/* A visit IS a check-in, so the entries it touched have new derived columns.
 * Run the app's own rule rather than writing them here — a second
 * implementation of entry_recompute() in a seed script is how a seed starts
 * showing dates the app would never produce. */
foreach (array_keys($fixedBy) as $touched) {
    entry_recompute($entryIds[$touched]);
}

printf("%d service visits on log entries\n", $visitCount);

/* ---- planned: maintenance somebody was paid to do ---------------------- */

/* A PAID ROUTINE SERVICE IS A MAINTENANCE COMPLETION THAT COST MONEY, not a
 * problem that had to be logged. Filing the annual HVAC service as a log entry
 * would mean inventing a fault that never existed. */

/* task title, days ago, note, vendor, cost, rating */
$paid = array(
    array('Change HVAC filter', 120, 'Annual service at the same visit — coils cleaned, refrigerant checked.',
          'Hill Country HVAC', 189.00, 4),
    array('Pump septic tank', 500, 'Pumped and inspected the baffles.', 'Ridgeline Septic', 415.00, null),
    array('Clean gutters', 210, 'Whole house, plus the studio.', 'Ace Handyman', 140.00, 4),
    /* Materials only, nobody paid. Still a cost, so it belongs on the Service
     * screen — "what did the house cost" is not only "who did we call". */
    array('Check caulking and exterior seals', 200, 'Two tubes of sealant.', null, 24.50, null),
);

$paidCount = 0;
foreach ($paid as $p) {
    list($taskTitle, $daysAgo, $note, $vendorName, $cost, $rating) = $p;

    $task = q('SELECT id FROM maintenance_tasks WHERE title = ? LIMIT 1', array($taskTitle))->fetch();
    if ($task === false) {
        continue;
    }
    $vendorId = $vendorName === null ? null : ($vendorIds[$vendorName] ?? null);

    q('INSERT INTO task_completions (task_id, completed_on, note, vendor_id, vendor_name, cost, rating)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
        array((int) $task['id'], ago($daysAgo), $note, $vendorId, $vendorName, $cost, $rating));

    $completionId = (int) db()->lastInsertId();
    $paidCount++;

    seed_photo('completion', $completionId, $daysAgo);
    if ($cost !== null) {
        seed_document('completion', $completionId, 'receipt-' . $completionId . '.pdf');
    }
}
printf("%d paid maintenance completions\n", $paidCount);

/* ------------------------------------------------------------------ media */

/**
 * A generated photo, so the galleries have something with real pixels in them.
 *
 * Flat colour with the date drawn on it — enough to prove the thumbs are
 * square, the strip scrolls and the lightbox opens. Without GD the row is
 * still created, with no file behind it: every read path has to tolerate a
 * missing image anyway (a failed queue job leaves exactly that), so seeding
 * the case is a feature rather than a shortcoming.
 */
function seed_photo(string $ownerType, int $ownerId, int $daysAgo): void
{
    $column = media_owner_column($ownerType);
    if ($column === null) {
        return;
    }

    $slug = imageproc_new_slug();
    $ok   = false;

    if (function_exists('imagecreatetruecolor')) {
        foreach (array('original' => 1400, 'detail' => 1400, 'thumb' => 400) as $dir => $size) {
            imageproc_ensure_dir($dir);
            $img = imagecreatetruecolor($size, (int) round($size * 0.75));
            /* Deterministic hue per photo, so the same seed run looks the same
             * twice and a screenshot diff means something. */
            $h = crc32($slug) % 360;
            $rgb = seed_hsl_to_rgb($h, 0.30, 0.62);
            imagefilledrectangle($img, 0, 0, $size, $size, imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
            imagestring($img, 5, 14, 14, ago($daysAgo), imagecolorallocate($img, 255, 255, 255));
            $ext = $dir === 'original' ? 'jpg' : 'webp';
            $path = imageproc_upload_path($dir, $slug, $ext);
            $dir === 'original' ? imagejpeg($img, $path, 82) : imagewebp($img, $path, 82);
            imagedestroy($img);
            $ok = true;
        }
    }

    q("INSERT INTO media (kind, $column, slug, ext, original_path, thumb_path, detail_path,
                          original_filename, mime, bytes, width, height, caption, status)
       VALUES ('photo', ?, ?, 'jpg', ?, ?, ?, ?, 'image/jpeg', ?, 1400, 1050, 'seed', ?)",
        array(
            $ownerId,
            $slug,
            $ok ? imageproc_relative_path('original', $slug, 'jpg') : null,
            $ok ? imageproc_relative_path('thumb', $slug, 'webp') : null,
            $ok ? imageproc_relative_path('detail', $slug, 'webp') : null,
            'IMG_' . substr($slug, 0, 4) . '.jpg',
            $ok ? 240000 : 0,
            $ok ? 'ready' : 'pending',
        ));
}

/** A one-page PDF, so the document list has a real file to link to. */
function seed_document(string $ownerType, int $ownerId, string $filename): void
{
    $column = media_owner_column($ownerType);
    if ($column === null) {
        return;
    }

    $slug = imageproc_new_slug();
    imageproc_ensure_dir('docs');
    $path = imageproc_upload_path('docs', $slug, 'pdf');

    /* The smallest thing a viewer will open. Hand-written rather than
     * generated by a library, because pulling in a PDF dependency to make a
     * fixture would be the tail wagging the dog. */
    $body = "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
          . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
          . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 120]>>endobj\n";
    file_put_contents($path, "%PDF-1.4\n" . $body . "trailer<</Root 1 0 R>>\n%%EOF\n");

    q("INSERT INTO media (kind, $column, slug, ext, original_path,
                          original_filename, mime, bytes, caption, status)
       VALUES ('document', ?, ?, 'pdf', ?, ?, 'application/pdf', ?, 'seed', 'ready')",
        array($ownerId, $slug, imageproc_relative_path('docs', $slug, 'pdf'),
              $filename, (int) filesize($path)));
}

/** @return array{0:int,1:int,2:int} */
function seed_hsl_to_rgb(float $h, float $s, float $l): array
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    $t = array(array($c,$x,0), array($x,$c,0), array(0,$c,$x), array(0,$x,$c), array($x,0,$c), array($c,0,$x));
    $p = $t[(int) floor($h / 60) % 6];
    return array((int) round(($p[0] + $m) * 255), (int) round(($p[1] + $m) * 255), (int) round(($p[2] + $m) * 255));
}

printf("%d media rows\n", (int) q('SELECT COUNT(*) FROM media')->fetchColumn());
printf("\nSeeded. Sign in and open the dashboard.\n");
