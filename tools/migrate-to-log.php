<?php
/* Migrate an install from Issues + Service Records to the merged Log.
 *
 *   php tools/migrate-to-log.php --dry-run     (say what it would do)
 *   php tools/migrate-to-log.php --confirm     (do it)
 *
 * ---------------------------------------------------------------------------
 * EXPORT FIRST. Menu -> Export data. This rewrites tables in place.
 * ---------------------------------------------------------------------------
 *
 * What moves, and why:
 *
 *   issues        -> log_entries        a rename; nothing about the row changes
 *   issue_updates -> log_updates        gains kind, vendor, cost, rating
 *   issue_tags    -> entry_tags         a rename
 *
 *   service_records dissolves into two homes, because it was two things
 *   wearing one shape:
 *
 *     · a record ON AN ISSUE becomes a log_update of kind 'service' on that
 *       entry. It was always an event in that story.
 *     · a record on a MAINTENANCE TASK becomes a task_completion carrying the
 *       cost. Routine paid work is a scheduled job that happened, not a
 *       problem that got fixed, and filing it as a log entry would invent a
 *       problem that never existed.
 *     · a record with neither gets a resolved log entry of its own, titled
 *       from the work, with one service update on it. Nothing is dropped.
 *
 * ---------------------------------------------------------------------------
 * IT REFUSES TO DROP THE OLD TABLES UNLESS THE NUMBERS ADD UP.
 * ---------------------------------------------------------------------------
 *
 * MySQL DDL is not transactional, so a half-finished run cannot be rolled
 * back. Instead every row is counted before and after, and service_records is
 * only dropped once every one of its rows is accounted for somewhere. If the
 * count is short the old table stays exactly where it is, and you can run this
 * again after telling me what it said.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';

$args    = array_slice($argv, 1);
$confirm = in_array('--confirm', $args, true);
$dryRun  = !$confirm;

$pdo = db();

/** Does this table exist? */
function has_table(string $name): bool
{
    try {
        q('SELECT 1 FROM `' . $name . '` LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Does this column exist on this table? */
function has_column(string $table, string $column): bool
{
    try {
        q('SELECT `' . $column . '` FROM `' . $table . '` LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The foreign keys currently declared on a table.
 *
 * Read from information_schema rather than hardcoded, because an install that
 * was set up before a constraint was renamed would have the old name and
 * DROP FOREIGN KEY takes the name, not the columns.
 *
 * @return list<string>
 */
function constraints_on(string $table): array
{
    try {
        return q(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_TYPE = ?',
            array($table, 'FOREIGN KEY')
        )->fetchAll(PDO::FETCH_COLUMN) ?: array();
    } catch (Throwable $e) {
        return array();
    }
}

/** Does this foreign key constrain this column? */
function fk_covers(string $table, string $constraint, string $column): bool
{
    try {
        return q(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ? AND COLUMN_NAME = ?',
            array($table, $constraint, $column)
        )->fetch() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function count_rows(string $table): int
{
    return has_table($table) ? (int) q('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() : 0;
}

function step(string $message): void
{
    printf("  %s\n", $message);
}

function run(string $sql, bool $dryRun): void
{
    if ($dryRun) {
        printf("    would run: %s\n", preg_replace('/\s+/', ' ', $sql));
        return;
    }
    q($sql);
}

/* ------------------------------------------------------------------ survey */

printf("Shirewatch — migrate to the merged Log\n%s\n\n", str_repeat('=', 38));

if (has_table('log_entries') && !has_table('issues')) {
    printf("Already migrated. log_entries exists and issues does not.\nNothing to do.\n");
    exit(0);
}
if (!has_table('issues')) {
    fatal_error('nothing_to_migrate',
        "There is no `issues` table. Either this is a fresh install (just load\n"
        . "schema.sql) or you are pointed at the wrong database.");
}

$before = array(
    'issues'          => count_rows('issues'),
    'issue_updates'   => count_rows('issue_updates'),
    'issue_tags'      => count_rows('issue_tags'),
    'service_records' => count_rows('service_records'),
    'record_tags'     => count_rows('record_tags'),
    'task_completions'=> count_rows('task_completions'),
    'media'           => count_rows('media'),
    'vendors'         => count_rows('vendors'),
);

printf("Before:\n");
foreach ($before as $table => $n) {
    printf("  %-18s %5d\n", $table, $n);
}
printf("\n%s\n\n", $dryRun ? 'DRY RUN — nothing will be written.' : 'Migrating…');

/* Where each service_record is going, decided before anything is written so
 * the plan can be printed and eyeballed on a dry run. */
$records = has_table('service_records')
    ? (q('SELECT * FROM service_records ORDER BY id')->fetchAll() ?: array())
    : array();

$plan = array('to_entry' => 0, 'to_completion' => 0, 'to_new_entry' => 0);
foreach ($records as $record) {
    if (!empty($record['issue_id'])) {
        $plan['to_entry']++;
    } elseif (!empty($record['task_id'])) {
        $plan['to_completion']++;
    } else {
        $plan['to_new_entry']++;
    }
}

printf("Service records (%d) will become:\n", count($records));
printf("  %-38s %d\n", 'updates on the log entry they related to', $plan['to_entry']);
printf("  %-38s %d\n", 'maintenance completions with a cost', $plan['to_completion']);
printf("  %-38s %d\n\n", 'new resolved log entries of their own', $plan['to_new_entry']);

/* ---------------------------------------------------------------- renames */

printf("Renaming tables\n");

if (has_table('issues') && !has_table('log_entries')) {
    step('issues -> log_entries');
    run('RENAME TABLE issues TO log_entries', $dryRun);
}
if (has_table('issue_updates') && !has_table('log_updates')) {
    step('issue_updates -> log_updates');
    run('RENAME TABLE issue_updates TO log_updates', $dryRun);
}
if (has_table('issue_tags') && !has_table('entry_tags')) {
    step('issue_tags -> entry_tags');
    run('RENAME TABLE issue_tags TO entry_tags', $dryRun);
}

/* ---------------------------------------------------------------- columns */

/* THE CONSTRAINTS MATTER AS MUCH AS THE COLUMNS. Adding vendor_id with an
 * index but no foreign key leaves a database that looks migrated and behaves
 * differently: deleting a vendor would leave a dangling id on every visit
 * instead of nulling it, and the snapshotted name — the whole point of that
 * column pair — would sit beside a link to a vendor that no longer exists.
 * A fresh install gets these from schema.sql, so a migrated one has to be
 * given them here or the two diverge silently. */
printf("\nAdding columns\n");

/* In a dry run the renames above have not happened, so asking about
 * `log_updates` would answer "no such table" and this whole section would
 * silently print nothing — which is the one thing a dry run must not do. Look
 * at the table under whichever name it currently has instead. */
$asks = static function (string $table) use ($dryRun): string {
    if (!$dryRun) {
        return $table;
    }
    $old = array('log_updates' => 'issue_updates', 'log_entries' => 'issues');
    return isset($old[$table]) && has_table($old[$table]) ? $old[$table] : $table;
};

{
    if (!has_column($asks('log_updates'), 'kind')) {
        step('log_updates: kind, vendor_id, vendor_name, cost, rating');
        run("ALTER TABLE log_updates
               CHANGE issue_id entry_id INT UNSIGNED NOT NULL,
               ADD COLUMN kind ENUM('note','service') NOT NULL DEFAULT 'note' AFTER entry_id,
               ADD COLUMN vendor_id INT UNSIGNED NULL AFTER severity,
               ADD COLUMN vendor_name VARCHAR(160) NULL AFTER vendor_id,
               ADD COLUMN cost DECIMAL(10,2) NULL AFTER vendor_name,
               ADD COLUMN rating TINYINT UNSIGNED NULL AFTER cost,
               ADD KEY idx_service (kind, noted_on),
               ADD KEY idx_vendor (vendor_id)", $dryRun);
        /* A SEPARATE STATEMENT, and it has to be. MariaDB rejects an ALTER
         * that both renames a foreign-key column and adds a constraint:
         * renaming forces ALGORITHM=COPY, which it will not do while a
         * foreign key names the column. The error says "Try ALGORITHM=INPLACE"
         * and means "do these one at a time". */
        run('ALTER TABLE log_updates
               ADD CONSTRAINT fk_updates_vendor FOREIGN KEY (vendor_id)
                   REFERENCES vendors (id) ON DELETE SET NULL', $dryRun);
    }
    if (!has_column($asks('log_entries'), 'resolved_by_update_id')) {
        step('log_entries: resolved_by_record_id -> resolved_by_update_id');
        run('ALTER TABLE log_entries
               CHANGE resolved_by_record_id resolved_by_update_id INT UNSIGNED NULL', $dryRun);
    }
    /* RENAMING A TABLE DOES NOT RENAME ITS COLUMNS. entry_tags still calls its
     * first column issue_id after the RENAME above, and every query in
     * lib/tags.php asks for entry_id — so this is the difference between a
     * migration that finishes and one that leaves every tag filter broken.
     * The foreign key has to come off first: MariaDB refuses to rename a
     * column an existing constraint names. */
    if (has_table('entry_tags') && !has_column('entry_tags', 'entry_id')) {
        step('entry_tags: issue_id -> entry_id');
        foreach (constraints_on('entry_tags') as $fk) {
            run('ALTER TABLE entry_tags DROP FOREIGN KEY `' . $fk . '`', $dryRun);
        }
        run('ALTER TABLE entry_tags CHANGE issue_id entry_id INT UNSIGNED NOT NULL', $dryRun);
        run('ALTER TABLE entry_tags
               ADD CONSTRAINT fk_et_entry FOREIGN KEY (entry_id) REFERENCES log_entries (id) ON DELETE CASCADE,
               ADD CONSTRAINT fk_et_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE', $dryRun);
    }

    if (!has_column('task_completions', 'vendor_id')) {
        step('task_completions: vendor_id, vendor_name, cost, rating');
        run('ALTER TABLE task_completions
               ADD COLUMN vendor_id INT UNSIGNED NULL AFTER note,
               ADD COLUMN vendor_name VARCHAR(160) NULL AFTER vendor_id,
               ADD COLUMN cost DECIMAL(10,2) NULL AFTER vendor_name,
               ADD COLUMN rating TINYINT UNSIGNED NULL AFTER cost,
               ADD KEY idx_paid (completed_on),
               ADD KEY idx_vendor (vendor_id),
               ADD CONSTRAINT fk_completions_vendor FOREIGN KEY (vendor_id)
                   REFERENCES vendors (id) ON DELETE SET NULL', $dryRun);
    }
    if (!has_column('media', 'entry_id')) {
        step('media: issue_id -> entry_id, issue_update_id -> update_id, + completion_id');
        run('ALTER TABLE media
               CHANGE issue_id entry_id INT UNSIGNED NULL,
               CHANGE issue_update_id update_id INT UNSIGNED NULL,
               ADD COLUMN completion_id INT UNSIGNED NULL AFTER update_id,
               ADD KEY idx_completion (completion_id)', $dryRun);
        run('ALTER TABLE media
               ADD CONSTRAINT fk_media_completion FOREIGN KEY (completion_id)
                   REFERENCES task_completions (id) ON DELETE CASCADE', $dryRun);
    }
}

/* --------------------------------------------------------------- the fold */

printf("\nFolding service records in\n");

$moved   = 0;
$mapping = array();      // old service_record id => ['update'|'completion', new id]

foreach ($records as $record) {
    $vendorName = $record['vendor_name'];
    $note = trim((string) $record['title']
        . ((string) $record['description'] !== '' ? "\n" . $record['description'] : ''));

    if (!empty($record['issue_id'])) {
        /* An event in that entry's story — which is what it always was. */
        if (!$dryRun) {
            q("INSERT INTO log_updates
                 (entry_id, kind, noted_on, note, vendor_id, vendor_name, cost, rating)
               VALUES (?, 'service', ?, ?, ?, ?, ?, ?)",
                array($record['issue_id'], $record['performed_on'], $note,
                      $record['vendor_id'], $vendorName, $record['cost'], $record['rating']));
            $mapping[(int) $record['id']] = array('update', (int) db()->lastInsertId());
        }
        $moved++;
        continue;
    }

    if (!empty($record['task_id'])) {
        /* Routine paid work: a completion that cost money. */
        if (!$dryRun) {
            q('INSERT INTO task_completions
                 (task_id, completed_on, note, vendor_id, vendor_name, cost, rating)
               VALUES (?, ?, ?, ?, ?, ?, ?)',
                array($record['task_id'], $record['performed_on'], $note,
                      $record['vendor_id'], $vendorName, $record['cost'], $record['rating']));
            $mapping[(int) $record['id']] = array('completion', (int) db()->lastInsertId());
        }
        $moved++;
        continue;
    }

    /* Neither: give it a log entry of its own, already resolved. Nothing is
     * dropped just because it never had a parent. */
    if (!$dryRun) {
        q("INSERT INTO log_entries
             (property_id, title, description, status, noticed_on, resolved_on)
           VALUES (1, ?, ?, 'resolved', ?, ?)",
            array(mb_substr((string) $record['title'], 0, 200, 'UTF-8'),
                  $record['description'], $record['performed_on'], $record['performed_on']));
        $entryId = (int) db()->lastInsertId();

        q("INSERT INTO log_updates
             (entry_id, kind, noted_on, note, vendor_id, vendor_name, cost, rating)
           VALUES (?, 'service', ?, ?, ?, ?, ?, ?)",
            array($entryId, $record['performed_on'], $note,
                  $record['vendor_id'], $vendorName, $record['cost'], $record['rating']));
        $updateId = (int) db()->lastInsertId();

        q('UPDATE log_entries SET resolved_by_update_id = ? WHERE id = ?', array($updateId, $entryId));
        $mapping[(int) $record['id']] = array('update', $updateId);

        /* Its tags belong to the entry that now represents it. */
        if (has_table('record_tags')) {
            q('INSERT IGNORE INTO entry_tags (entry_id, tag_id)
                 SELECT ?, tag_id FROM record_tags WHERE record_id = ?',
                array($entryId, $record['id']));
        }
    }
    $moved++;
}
step($moved . ' record(s) folded in');

/* Tags from records that DID have a parent go onto that parent. A service is
 * an event on an entry, and it is the entry that is in a room. */
if (has_table('record_tags')) {
    step('record tags -> their parent entry');
    run('INSERT IGNORE INTO entry_tags (entry_id, tag_id)
           SELECT r.issue_id, rt.tag_id
             FROM record_tags rt
             JOIN service_records r ON r.id = rt.record_id
            WHERE r.issue_id IS NOT NULL', $dryRun);
}

/* Photos and invoices follow their record to wherever it landed. */
if (has_column('media', 'service_record_id') && !$dryRun) {
    step('media -> the update or completion each record became');
    foreach ($mapping as $oldId => $target) {
        list($kind, $newId) = $target;
        q('UPDATE media SET ' . ($kind === 'update' ? 'update_id' : 'completion_id')
            . ' = ?, service_record_id = NULL WHERE service_record_id = ?',
            array($newId, $oldId));
    }
}

/* The "what fixed it" pointer, repointed at the update the record became. */
if (!$dryRun && $mapping !== array()) {
    step('resolved_by pointers -> the new updates');
    foreach ($mapping as $oldId => $target) {
        if ($target[0] === 'update') {
            q('UPDATE log_entries SET resolved_by_update_id = ? WHERE resolved_by_update_id = ?',
                array($target[1], $oldId));
        }
    }
}

/* ------------------------------------------------------------- verify, drop */

printf("\nChecking the numbers\n");

if ($dryRun) {
    printf("\nDRY RUN — nothing was written. Re-run with --confirm.\n");
    exit(0);
}

$after = array(
    'log_entries'      => count_rows('log_entries'),
    'log_updates'      => count_rows('log_updates'),
    'entry_tags'       => count_rows('entry_tags'),
    'task_completions' => count_rows('task_completions'),
    'media'            => count_rows('media'),
    'vendors'          => count_rows('vendors'),
);

foreach ($after as $table => $n) {
    printf("  %-18s %5d\n", $table, $n);
}

$expectedEntries = $before['issues'] + $plan['to_new_entry'];
$expectedUpdates = $before['issue_updates'] + $plan['to_entry'] + $plan['to_new_entry'];
$expectedDone    = $before['task_completions'] + $plan['to_completion'];

$ok = $after['log_entries'] === $expectedEntries
   && $after['log_updates'] === $expectedUpdates
   && $after['task_completions'] === $expectedDone
   && $after['media'] === $before['media']
   && $after['vendors'] === $before['vendors'];

printf("\n");

if (!$ok) {
    printf("NUMBERS DO NOT ADD UP.\n\n");
    printf("  log_entries       %d, expected %d\n", $after['log_entries'], $expectedEntries);
    printf("  log_updates       %d, expected %d\n", $after['log_updates'], $expectedUpdates);
    printf("  task_completions  %d, expected %d\n", $after['task_completions'], $expectedDone);
    printf("  media             %d, expected %d\n", $after['media'], $before['media']);
    printf("\nservice_records and record_tags have been LEFT IN PLACE so nothing is\n");
    printf("lost. Send me this output and do not run it again yet.\n");
    exit(1);
}

step('everything accounted for');

/* Only now. The old tables are the safety net until the count proves they are
 * no longer needed.
 *
 * THE COLUMNS POINTING AT service_records GO FIRST. Their foreign keys are
 * what makes the table un-droppable, and MySQL's error for that ("cannot
 * delete a parent row") names neither the table nor the column, so a run that
 * failed here would look like data corruption rather than an ordering
 * mistake. */
foreach (array('media', 'task_completions') as $table) {
    if (has_column($table, 'service_record_id')) {
        step($table . ': dropping service_record_id');
        foreach (constraints_on($table) as $fk) {
            /* Only the one naming service_record_id, so media keeps its own
             * cascade to log_entries and log_updates. */
            if (!fk_covers($table, $fk, 'service_record_id')) {
                continue;
            }
            run('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $fk . '`', false);
        }
        run('ALTER TABLE `' . $table . '` DROP COLUMN service_record_id', false);
    }
}

foreach (array('record_tags', 'service_records') as $dead) {
    if (has_table($dead)) {
        step('dropping ' . $dead);
        run('DROP TABLE ' . $dead, false);
    }
}

printf("\nDone. Upload the new files, then load the app.\n");
