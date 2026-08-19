<?php
/**
 * The test entry point.
 *
 *   php tools/run-tests.php
 *
 * Exits non-zero on any failure, so it works as a pre-commit or pre-deploy
 * check.
 *
 * These are the FOUNDATION tests: the schema, the seed data, the constraints,
 * the shared helpers in lib/, lib/tags.php, and all of lib/dates.php including
 * the recurrence engine. They deliberately do not test any module — Issues,
 * Maintenance, Service/Vendors and the Dashboard belong to later passes, and
 * their tests belong beside their code. Add a section; don't rewrite these.
 *
 * The database is SQLite in memory, translated from schema.sql by
 * tools/test-harness.php. Read that file's header before adding a test: MySQL
 * is the production target and a test that only passes because of SQLite
 * semantics is worse than no test at all.
 *
 * lib/dates.php needs no database at all, which is the point of it being pure.
 * RUN THE RECURRENCE SECTION FIRST when a due date looks wrong: recur_next_after()
 * is the only function in this app that can be wrong about one, and a schedule
 * that drifts is invisible until a season has already been missed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appRoot = dirname(__DIR__);

/* lib/bootstrap.php refuses to load without config.php, and config.php is
 * gitignored — so a fresh checkout has none. Stand one up from the tracked
 * example for the duration of the run and take it away afterwards, so that
 * `git clone && php tools/run-tests.php` works with no setup at all.
 *
 * Only ever created when absent, and only ever removed when this script was
 * the thing that created it: a real config.php with real credentials in it must
 * survive a test run untouched. */
$configPath = $appRoot . '/config.php';
$madeConfig = false;
if (!is_file($configPath)) {
    if (!@copy($appRoot . '/config.example.php', $configPath)) {
        fwrite(STDERR, "Could not create a temporary config.php from config.example.php\n");
        exit(1);
    }
    $madeConfig = true;
    register_shutdown_function(static function () use ($configPath): void {
        @unlink($configPath);
    });
}

require_once __DIR__ . '/test-harness.php';
require_once $appRoot . '/lib/bootstrap.php';
require_once $appRoot . '/lib/dates.php';
require_once $appRoot . '/lib/layout.php';
require_once $appRoot . '/lib/tags.php';

/* ------------------------------------------------------------------ harness */

$T = array('pass' => 0, 'fail' => 0, 'failures' => array());

function ok(bool $condition, string $label, string $detail = ''): bool
{
    global $T;
    if ($condition) {
        $T['pass']++;
        printf("  \033[32m✓\033[0m %s\n", $label);
        return true;
    }
    $T['fail']++;
    $T['failures'][] = $label . ($detail !== '' ? ' — ' . $detail : '');
    printf("  \033[31m✗\033[0m %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    return false;
}

function is_same($actual, $expected, string $label): bool
{
    return ok(
        $actual === $expected,
        $label,
        $actual === $expected ? '' : 'got ' . var_export($actual, true) . ', want ' . var_export($expected, true)
    );
}

/** True when running $fn throws. Used to prove a constraint actually bites. */
function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

function section(string $title): void
{
    printf("\n\033[1m%s\033[0m\n", $title);
}

printf("\033[1mShirewatch — foundation tests\033[0m\n");
if ($madeConfig) {
    printf("  (using a temporary config.php copied from config.example.php)\n");
}

/* ==================================================================== schema */

section('Schema loads under the SQLite harness');

$pdo = null;
try {
    $pdo = harness_pdo();
    ok(true, 'schema.sql translates and creates cleanly');
} catch (Throwable $e) {
    ok(false, 'schema.sql translates and creates cleanly', $e->getMessage());
    fwrite(STDERR, "\nCannot continue without a database.\n");
    exit(1);
}

$tables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

foreach (array(
    'properties', 'tags', 'issues', 'issue_updates', 'issue_tags',
    'maintenance_tasks', 'task_completions', 'task_reminder_sends', 'task_tags',
    'vendors', 'vendor_tags', 'service_records', 'record_tags',
    'media', 'login_attempts',
) as $table) {
    ok(in_array($table, $tables, true), "table $table exists");
}
is_same(count($tables), 15, 'exactly 15 tables, no strays');

/* ============================================================== seed data */

section('Seed data');

is_same((int) $pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn(), 1,
    'exactly one property is seeded');

is_same((int) $pdo->query("SELECT COUNT(*) FROM tags WHERE kind='location'")->fetchColumn(), 24,
    "24 locations seeded");
is_same((int) $pdo->query("SELECT COUNT(*) FROM tags WHERE kind='category'")->fetchColumn(), 12,
    '12 categories seeded');
is_same((int) $pdo->query("SELECT COUNT(*) FROM tags WHERE kind='work_type'")->fetchColumn(), 12,
    '12 work types seeded');

/* The room names are the author's and are stored EXACTLY as she wrote them
 * (DELEGATION-PLAN.md §2.12). This assertion exists to fail loudly the day
 * somebody "tidies" one of them, which is a thing that will otherwise happen
 * during a refactor and be discovered by a filter quietly going empty. */
$locations = $pdo->query(
    "SELECT name FROM tags WHERE kind='location' ORDER BY sort_order"
)->fetchAll(PDO::FETCH_COLUMN);
is_same($locations, array(
    'Library', 'Dining Room', 'Entryway', 'Living Room', 'Emma Bathroom',
    'Emma Room', 'Guest Room', 'Coat Closet', 'Main Bedroom', 'Main Bathroom',
    'Sunroom', 'Breakfast Room', 'Kitchen', 'Space Bathroom', 'Laundry Room',
    'Garage', 'Ext Studio', 'Yard', 'Roof', 'Septic Tank', 'Gutters',
    'Chimney', 'Garage Attic', 'House Attic',
), 'locations are in the authors order, spelled her way — "Ext Studio" is not expanded');

/* Locations are property-scoped; systems and trades are not (§2.1). */
is_same((int) $pdo->query(
    "SELECT COUNT(*) FROM tags WHERE kind='location' AND property_id IS NULL"
)->fetchColumn(), 0, 'every location is scoped to a property');
is_same((int) $pdo->query(
    "SELECT COUNT(*) FROM tags WHERE kind IN ('category','work_type') AND property_id IS NOT NULL"
)->fetchColumn(), 0, 'no category or work type is property-scoped');

/* ============================================================= constraints */

section('Constraints actually bite');

ok(throws(static function (): void {
    q("INSERT INTO tags (kind, property_id, name) VALUES ('location', 1, 'Kitchen')");
}), 'a duplicate location name is rejected');

ok(!throws(static function (): void {
    q("INSERT INTO tags (kind, property_id, name) VALUES ('category', NULL, 'Kitchen')");
}), 'the same name under a DIFFERENT kind is fine — the unique key is per kind');

ok(throws(static function (): void {
    q("INSERT INTO issues (title, status, noticed_on) VALUES ('x', 'closed', '2026-01-01')");
}), 'an unknown issue status is rejected');

ok(throws(static function (): void {
    q("INSERT INTO maintenance_tasks (title, recur_kind, next_due_on) VALUES ('x', 'weekly', '2026-01-01')");
}), 'an unknown recur_kind is rejected');

ok(throws(static function (): void {
    q("INSERT INTO issue_updates (issue_id, noted_on) VALUES (99999, '2026-01-01')");
}), 'an issue_update pointing at no issue is rejected');

/* The send ledger's whole job (§2.3): one row per (task, due date), forever. */
q("INSERT INTO maintenance_tasks (id, title, recur_kind, interval_count, interval_unit, next_due_on)
   VALUES (1, 'Furnace filter', 'interval', 3, 'month', '2026-09-01')");
q("INSERT INTO task_reminder_sends (task_id, due_on, attempts) VALUES (1, '2026-09-01', 1)");
ok(throws(static function (): void {
    q("INSERT INTO task_reminder_sends (task_id, due_on, attempts) VALUES (1, '2026-09-01', 1)");
}), 'a second send row for the same (task, due_on) is impossible');

/* ================================================================ cascades */

section('Deletes go the right way');

q("INSERT INTO issues (id, title, status, noticed_on) VALUES (1, 'Basement crack', 'watching', '2026-01-05')");
q("INSERT INTO issue_updates (id, issue_id, noted_on, note) VALUES (1, 1, '2026-03-05', 'wider')");
q("INSERT INTO issue_tags (issue_id, tag_id) SELECT 1, id FROM tags WHERE kind='location' AND name='Kitchen'");
q("INSERT INTO vendors (id, name) VALUES (1, 'Ace Plumbing')");
q("INSERT INTO service_records (id, vendor_id, vendor_name, title, performed_on, issue_id)
   VALUES (1, 1, 'Ace Plumbing', 'Sealed it', '2026-04-01', 1)");

q('DELETE FROM issues WHERE id = 1');
is_same((int) q('SELECT COUNT(*) FROM issue_updates WHERE issue_id = 1')->fetchColumn(), 0,
    'deleting an issue takes its timeline with it');
is_same((int) q('SELECT COUNT(*) FROM issue_tags WHERE issue_id = 1')->fetchColumn(), 0,
    'deleting an issue takes its tag links with it');
is_same((int) q('SELECT COUNT(*) FROM service_records WHERE id = 1')->fetchColumn(), 1,
    'deleting an issue does NOT delete the service record that referenced it');

q('DELETE FROM vendors WHERE id = 1');
$rec = q('SELECT vendor_id, vendor_name FROM service_records WHERE id = 1')->fetch();
is_same($rec['vendor_id'], null, 'deleting a vendor nulls the link, it does not delete the record');
is_same((string) $rec['vendor_name'], 'Ace Plumbing',
    'the snapshot name survives the vendor — a 2023 invoice still says who sent it');

/* ================================================================== tags */

section('lib/tags.php');

$kitchen = tag_find(TAG_LOCATION, 'Kitchen');
ok($kitchen !== null, 'tag_find locates a seeded room');

is_same(count(tags_of_kind(TAG_LOCATION)), 24, 'tags_of_kind returns every location');
is_same(count(tags_of_kind(TAG_CATEGORY)), 13, 'tags_of_kind returns categories (12 seeded + the one added above)');
is_same(tags_of_kind('nonsense'), array(), 'an unknown kind returns nothing rather than throwing');

q("INSERT INTO issues (id, title, status, noticed_on) VALUES (2, 'Drip', 'active', '2026-02-01')");
$plumbing = tag_find(TAG_CATEGORY, 'Plumbing');
$set = tags_set('issue', 2, array($kitchen['id'], $plumbing['id'], 999999));
is_same(count($set), 2, 'tags_set drops an id that does not exist rather than failing the save');
is_same(count(tags_for('issue', 2)), 2, 'tags_for reads them back');

$many = tags_for_many('issue', array(2, 12345));
is_same(count($many[2]), 2, 'tags_for_many keys results by item id');
is_same($many[12345], array(), 'an item with no tags is present with an empty array, not absent');

is_same(tags_set('issue', 2, array()), array(), 'tags_set with an empty list clears them');
is_same(tags_for('issue', 2), array(), 'and the item really has none');

/* The rename behaviour the author asked for: the tag follows every item. */
tags_set('issue', 2, array($kitchen['id']));
ok(tag_rename($kitchen['id'], 'The Kitchen'), 'tag_rename succeeds');
$after = tags_for('issue', 2);
is_same($after[0]['name'], 'The Kitchen',
    'RENAMING A ROOM FOLLOWS EVERY ITEM ALREADY TAGGED WITH IT');
is_same($after[0]['id'], $kitchen['id'], 'and it is the same tag, not a new one');
tag_rename($kitchen['id'], 'Kitchen');

ok(!tag_rename($kitchen['id'], 'Library'),
    'renaming onto a sibling name is refused — merging two tags is a different operation');

$usage = tag_usage($kitchen['id']);
is_same($usage['issue'], 1, 'tag_usage counts the issues carrying a tag');

$newId = tag_add(TAG_LOCATION, '  Wine Cellar  ');
ok($newId !== null, 'tag_add creates a tag');
is_same(tag_by_id($newId)['name'], 'Wine Cellar', 'tag_add trims, and does nothing else to the name');
is_same(tag_add(TAG_LOCATION, 'Wine Cellar'), $newId, 'tag_add is idempotent on the name');
is_same(tag_add(TAG_LOCATION, '   '), null, 'tag_add refuses an empty name');

ok(tag_delete($newId), 'tag_delete removes it');
is_same(tag_by_id($newId), null, 'and it is gone');

tags_reorder(array($plumbing['id'], $kitchen['id']));
is_same(tag_by_id($plumbing['id'])['sort_order'], 10, 'tags_reorder writes spaced sort orders');
is_same(tag_by_id($kitchen['id'])['sort_order'], 20, 'spaced by ten, leaving room to insert between');

/* ============================================================== recurrence */

section('lib/dates.php — recurrence (READ THIS SECTION FIRST when a date looks wrong)');

$every3mo = array('recur_kind' => 'interval', 'interval_count' => 3, 'interval_unit' => 'month');
is_same(recur_next_after($every3mo, '2026-08-19'), '2026-11-19', 'every 3 months');
is_same(recur_next_after($every3mo, '2026-11-19'), '2027-02-19', 'and across the year boundary');

/* PHP's own arithmetic gives March 3 here, not February 28. A task set up on
 * the 31st would drift a few days later every short month and eventually land
 * in the wrong month, and nobody would trace that back to the date library. */
$monthly = array('recur_kind' => 'interval', 'interval_count' => 1, 'interval_unit' => 'month');
is_same(recur_next_after($monthly, '2026-01-31'), '2026-02-28',
    'Jan 31 + 1 month CLAMPS to Feb 28 — it does not overflow into March');
is_same(recur_next_after($monthly, '2028-01-31'), '2028-02-29', 'and to Feb 29 in a leap year');

is_same(recur_next_after(array('recur_kind' => 'interval', 'interval_count' => 1, 'interval_unit' => 'year'),
    '2026-08-19'), '2027-08-19', 'every year');
is_same(recur_next_after(array('recur_kind' => 'interval', 'interval_count' => 2, 'interval_unit' => 'week'),
    '2026-08-19'), '2026-09-02', 'every 2 weeks');
is_same(recur_next_after(array('recur_kind' => 'interval', 'interval_count' => 90, 'interval_unit' => 'day'),
    '2026-08-19'), '2026-11-17', 'every 90 days');

/* Seasonal. The whole reason this second recurrence kind exists: a "180 days"
 * approximation walks gutter cleaning out of its season within a few years. */
$gutters = array('recur_kind' => 'months', 'recur_months' => '3,10', 'recur_day' => 15);
is_same(recur_next_after($gutters, '2026-08-19'), '2026-10-15', 'seasonal: next is October');
is_same(recur_next_after($gutters, '2026-10-15'), '2027-03-15', 'from the October date, next is March');
is_same(recur_next_after($gutters, '2026-11-01'), '2027-03-15', 'past October, it wraps to next year');
is_same(recur_next_after($gutters, '2027-02-01'), '2027-03-15', 'and picks up March again');
is_same(recur_next_after(array('recur_kind' => 'months', 'recur_months' => '2', 'recur_day' => 31),
    '2026-01-05'), '2026-02-28', 'a month-anchored day is clamped too');

/* A corrupt rule must produce NO schedule, visibly — never a silent default,
 * which would quietly acquire January and look like it was working. */
is_same(recur_next_after(array('recur_kind' => 'interval', 'interval_count' => 0, 'interval_unit' => 'month'),
    '2026-08-19'), null, 'a zero interval has no next date');
is_same(recur_next_after(array('recur_kind' => 'interval', 'interval_count' => 1, 'interval_unit' => 'fortnight'),
    '2026-08-19'), null, 'an unknown unit has no next date');
is_same(recur_next_after(array('recur_kind' => 'months', 'recur_months' => 'abc,13,0', 'recur_day' => 1),
    '2026-08-19'), null, 'an unparseable month list has no next date');
is_same(recur_next_after($every3mo, 'not-a-date'), null, 'an unparseable start date has no next date');

is_same(recur_parse_months('10,3,3'), array(3, 10), 'month lists are de-duplicated and sorted');
is_same(recur_describe($every3mo), 'Every 3 months', 'recur_describe: interval, plural');
is_same(recur_describe($monthly), 'Every month', 'recur_describe: interval, singular');
is_same(recur_describe($gutters), 'March and October', 'recur_describe: seasonal');
is_same(recur_describe(array('recur_kind' => 'interval', 'interval_count' => 0)), '',
    'recur_describe returns nothing for a broken rule rather than "Every 0 s"');

/* ================================================================== dates */

section('lib/dates.php — the rest');

is_same(days_since('2026-08-01', '2026-08-19'), 18, 'days_since counts forward');
is_same(days_since(null, '2026-08-19'), null, 'days_since tolerates a null');
is_same(days_since('2026-08-01 09:30:00', '2026-08-19'), 18, 'and a trailing time');

is_same(fmt_relative_due('2026-08-19', '2026-08-19'), 'Today', 'fmt_relative_due: today');
is_same(fmt_relative_due('2026-08-20', '2026-08-19'), 'Tomorrow', 'fmt_relative_due: tomorrow');
is_same(fmt_relative_due('2026-08-18', '2026-08-19'), 'Yesterday', 'fmt_relative_due: yesterday');
is_same(fmt_relative_due('2026-07-16', '2026-08-19'), '34 days ago',
    'overdue stays in days however far back — how overdue it is, is the information');
is_same(fmt_relative_due('2026-10-01', '2026-08-19'), 'October 1', 'beyond a week it becomes a date');

/* ================================================================ helpers */

section('Shared helpers');

is_same(h('<b>&</b>'), '&lt;b&gt;&amp;&lt;/b&gt;', 'h() escapes');
is_same(h(null), '', 'h(null) is an empty string, not "null"');
is_same(app_name(), 'Shirewatch', 'app_name() reads the config value');
is_same(cfg('db.charset'), 'utf8mb4', 'cfg() reads a dotted path');
is_same(cfg('nope.nothing', 'fallback'), 'fallback', 'cfg() falls back');

is_same(array_keys(nav_tabs()), array('dashboard', 'issues', 'maintenance', 'vendors'),
    'four tabs, in the authors order');
ok(count(menu_items()) >= 4, 'the hamburger carries the once-in-a-while actions');

/* The import map in layout.php exists to stop a stale cached ES module. If a
 * shared module is added and this list is not updated, the new one is the one
 * that goes stale — silently, and only in browsers that already have a copy. */
foreach (SHARED_MODULES as $module) {
    ok(is_file($appRoot . '/public/assets/' . $module),
        "SHARED_MODULES names a file that exists: $module");
}

/* Throttle curve. Factored out of auth_attempt_login() precisely so it can be
 * checked without a database, a session or a real password. */
is_same(auth_attempt_delay(0), 0.25, 'first failure costs a flat 0.25s');
is_same(auth_attempt_delay(2), 0.25, 'still flat below the escalation threshold');
ok(auth_attempt_delay(6) > auth_attempt_delay(4), 'the delay escalates');
is_same(auth_attempt_delay(50), 4.0, 'and is capped, so a request cannot hang');

/* ============================================================== M1 · tags */

section('M1 — Rooms & Tags');

/* The endpoints are thin wrappers over lib/tags.php, which the section above
 * already exercises. What is worth testing HERE is the wrapping: that each one
 * refuses what it should, and that the screen's no-JS path and the JS path
 * cannot diverge because both go through the same functions. */

foreach (array(
    'public/tags.php',
    'public/assets/tags.js',
    'public/assets/tagfield.js',
    'public/api/tag-add.php',
    'public/api/tag-rename.php',
    'public/api/tag-delete.php',
    'public/api/tag-reorder.php',
    'public/api/tag-usage.php',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M1 ships $file");
}

/* Every mutating endpoint must gate on all three of login, same-origin and
 * method. A missing require_same_origin() is invisible until somebody proves
 * it by posting a form from another site. */
foreach (glob($appRoot . '/public/api/tag-*.php') as $endpoint) {
    $src  = (string) file_get_contents($endpoint);
    $name = basename($endpoint);
    $isRead = str_contains($src, "require_method('GET')");

    ok(str_contains($src, 'require_login_api()'), "$name gates on login");
    ok(str_contains($src, 'require_method('), "$name pins its HTTP verb");
    ok(
        $isRead || str_contains($src, 'require_same_origin()'),
        "$name checks same-origin (or is a GET)"
    );
}

/* The no-JS path posts a real form, which cannot send a header — so it needs a
 * token, and every form on the screen needs to carry one. */
$tagsScreen = (string) file_get_contents($appRoot . '/public/tags.php');
is_same(
    substr_count($tagsScreen, 'csrf_field()'),
    substr_count($tagsScreen, '<form method="post"'),
    'every form on the Rooms & Tags screen carries a CSRF token'
);
ok(str_contains($tagsScreen, 'require_csrf_form()'),
    'and the screen checks it on POST');

/* csrf_token() needs a session; the gate fails open, so the screen cannot
 * assume the gate started one. */
ok(str_contains($tagsScreen, 'auth_start_session()'),
    'the screen starts its own session rather than assuming the gate did');

/* The token check itself. */
$_SESSION = array();
$token = csrf_token();
ok($token !== '' && strlen($token) === 64, 'csrf_token() mints a 32-byte token');
is_same(csrf_token(), $token, 'and is stable within a session');
ok(str_contains(csrf_field(), $token), 'csrf_field() embeds it');

/* Rename refuses a collision rather than merging — the endpoint returns 409
 * and lib/tags.php returns false. Proven here on the library, since the
 * endpoint is a direct pass-through. */
$library = tag_find(TAG_LOCATION, 'Library');
$sunroom = tag_find(TAG_LOCATION, 'Sunroom');
ok(!tag_rename($library['id'], 'Sunroom'),
    'renaming a room onto another rooms name is refused, never merged');
is_same(tag_by_id($library['id'])['name'], 'Library', 'and the original name is untouched');

/* Cross-kind names do NOT collide: "HVAC" is legitimately both a system and a
 * trade, and the seed data relies on it. */
ok(tag_find(TAG_CATEGORY, 'HVAC') !== null && tag_find(TAG_WORK_TYPE, 'HVAC') !== null,
    'the same name under two kinds coexists — HVAC is both a system and a trade');

/* The picker is a closed list over seeded tags, so tagfield.js must not carry
 * the Gallery's normalizer. A client-side lowercase would disagree with
 * "stored exactly as typed" and quietly re-case "Ext Studio". */
$picker = (string) file_get_contents($appRoot . '/public/assets/tagfield.js');
ok(!str_contains($picker, 'toLowerCase'),
    'tagfield.js does NOT normalize names — they are stored exactly as typed');
ok(str_contains($picker, 'textContent'),
    'tagfield.js writes tag names with textContent, never innerHTML');

/* =================================================================== done */

printf("\n");
if ($T['fail'] === 0) {
    printf("\033[32m%d assertions, all passing\033[0m\n", $T['pass']);
    exit(0);
}
printf("\033[31m%d of %d failed\033[0m\n", $T['fail'], $T['pass'] + $T['fail']);
foreach ($T['failures'] as $failure) {
    printf("  · %s\n", $failure);
}
exit(1);
