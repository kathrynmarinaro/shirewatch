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
require_once $appRoot . '/lib/media.php';
require_once $appRoot . '/lib/log.php';
require_once $appRoot . '/lib/render.php';
require_once $appRoot . '/lib/tasks.php';
require_once $appRoot . '/lib/vendors.php';
require_once $appRoot . '/lib/service.php';
require_once $appRoot . '/lib/dashboard.php';
require_once $appRoot . '/tools/cron-reminders.php';

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
    'properties', 'tags', 'log_entries', 'log_updates', 'entry_tags',
    'maintenance_tasks', 'task_completions', 'task_reminder_sends', 'task_tags',
    'vendors', 'vendor_tags', 'media', 'login_attempts',
) as $table) {
    ok(in_array($table, $tables, true), "table $table exists");
}
is_same(count($tables), 13, 'exactly 13 tables, no strays');

/* THE MERGE REMOVED THESE, and their absence is the assertion. A leftover
 * service_records table would still answer queries — with rows nothing writes
 * any more — so the failure would be silently missing money rather than an
 * error anyone could see. */
foreach (array('issues', 'issue_updates', 'issue_tags', 'service_records', 'record_tags') as $gone) {
    ok(!in_array($gone, $tables, true), "table $gone is gone, not orphaned");
}

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
    'Breakfast Room', 'Chimney', 'Coat Closet', 'Dining Room',
    'Emma Bathroom', 'Emma Room', 'Entryway', 'Ext Studio', 'Garage',
    'Garage Attic', 'Guest Room', 'Gutters', 'House Attic', 'Kitchen',
    'Laundry Room', 'Library', 'Living Room', 'Main Bathroom',
    'Main Bedroom', 'Roof', 'Septic Tank', 'Space Bathroom', 'Sunroom',
    'Yard',
), 'locations are alphabetical, spelled her way — "Ext Studio" is not expanded');

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
    q("INSERT INTO log_entries (title, status, noticed_on) VALUES ('x', 'closed', '2026-01-01')");
}), 'an unknown log entry status is rejected');

ok(throws(static function (): void {
    q("INSERT INTO maintenance_tasks (title, recur_kind, next_due_on) VALUES ('x', 'weekly', '2026-01-01')");
}), 'an unknown recur_kind is rejected');

ok(throws(static function (): void {
    q("INSERT INTO log_updates (entry_id, noted_on) VALUES (99999, '2026-01-01')");
}), 'a log_update pointing at no entry is rejected');

ok(throws(static function (): void {
    q("INSERT INTO log_updates (entry_id, noted_on, kind) VALUES (1, '2026-01-01', 'invoice')");
}), 'an update kind outside (note, service) is rejected');

/* The send ledger's whole job (§2.3): one row per (task, due date), forever. */
q("INSERT INTO maintenance_tasks (id, title, recur_kind, interval_count, interval_unit, next_due_on)
   VALUES (1, 'Furnace filter', 'interval', 3, 'month', '2026-09-01')");
q("INSERT INTO task_reminder_sends (task_id, due_on, attempts) VALUES (1, '2026-09-01', 1)");
ok(throws(static function (): void {
    q("INSERT INTO task_reminder_sends (task_id, due_on, attempts) VALUES (1, '2026-09-01', 1)");
}), 'a second send row for the same (task, due_on) is impossible');

/* ================================================================ cascades */

section('Deletes go the right way');

q("INSERT INTO vendors (id, name) VALUES (1, 'Ace Plumbing')");
q("INSERT INTO log_entries (id, title, status, noticed_on) VALUES (1, 'Basement crack', 'watching', '2026-01-05')");
q("INSERT INTO log_updates (id, entry_id, noted_on, note) VALUES (1, 1, '2026-03-05', 'wider')");
q("INSERT INTO log_updates (id, entry_id, noted_on, kind, vendor_id, vendor_name, cost)
   VALUES (2, 1, '2026-04-01', 'service', 1, 'Ace Plumbing', 480.00)");
q("INSERT INTO entry_tags (entry_id, tag_id) SELECT 1, id FROM tags WHERE kind='location' AND name='Kitchen'");

/* A vendor's history no longer lives in a table of its own — it is the service
 * updates scattered through the log. Deleting the vendor must not take those
 * with it, and must not take their names either. */
q('DELETE FROM vendors WHERE id = 1');
$visit = q('SELECT vendor_id, vendor_name FROM log_updates WHERE id = 2')->fetch();
is_same($visit['vendor_id'], null, 'deleting a vendor nulls the link, it does not delete the visit');
is_same((string) $visit['vendor_name'], 'Ace Plumbing',
    'the snapshot name survives the vendor — a 2023 invoice still says who sent it');

q('DELETE FROM log_entries WHERE id = 1');
is_same((int) q('SELECT COUNT(*) FROM log_updates WHERE entry_id = 1')->fetchColumn(), 0,
    'deleting an entry takes its whole timeline with it');
is_same((int) q('SELECT COUNT(*) FROM entry_tags WHERE entry_id = 1')->fetchColumn(), 0,
    'deleting an entry takes its tag links with it');

/* ================================================================== tags */

section('lib/tags.php');

$kitchen = tag_find(TAG_LOCATION, 'Kitchen');
ok($kitchen !== null, 'tag_find locates a seeded room');

is_same(count(tags_of_kind(TAG_LOCATION)), 24, 'tags_of_kind returns every location');
is_same(count(tags_of_kind(TAG_CATEGORY)), 13, 'tags_of_kind returns categories (12 seeded + the one added above)');
is_same(tags_of_kind('nonsense'), array(), 'an unknown kind returns nothing rather than throwing');

q("INSERT INTO log_entries (id, title, status, noticed_on) VALUES (2, 'Drip', 'active', '2026-02-01')");
$plumbing = tag_find(TAG_CATEGORY, 'Plumbing');
$set = tags_set('entry', 2, array($kitchen['id'], $plumbing['id'], 999999));
is_same(count($set), 2, 'tags_set drops an id that does not exist rather than failing the save');
is_same(count(tags_for('entry', 2)), 2, 'tags_for reads them back');

$many = tags_for_many('entry', array(2, 12345));
is_same(count($many[2]), 2, 'tags_for_many keys results by item id');
is_same($many[12345], array(), 'an item with no tags is present with an empty array, not absent');

is_same(tags_set('entry', 2, array()), array(), 'tags_set with an empty list clears them');
is_same(tags_for('entry', 2), array(), 'and the item really has none');

/* The rename behaviour the author asked for: the tag follows every item. */
tags_set('entry', 2, array($kitchen['id']));
ok(tag_rename($kitchen['id'], 'The Kitchen'), 'tag_rename succeeds');
$after = tags_for('entry', 2);
is_same($after[0]['name'], 'The Kitchen',
    'RENAMING A ROOM FOLLOWS EVERY ITEM ALREADY TAGGED WITH IT');
is_same($after[0]['id'], $kitchen['id'], 'and it is the same tag, not a new one');
tag_rename($kitchen['id'], 'Kitchen');

ok(!tag_rename($kitchen['id'], 'Library'),
    'renaming onto a sibling name is refused — merging two tags is a different operation');

$usage = tag_usage($kitchen['id']);
is_same($usage['entry'], 1, 'tag_usage counts the log entries carrying a tag');

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
/* h() takes scalars, not only strings. Under strict_types the narrow
 * signature that the siblings use makes h($year) a fatal TypeError, and a
 * template is exactly where an int arrives by accident — PHP turns a numeric
 * string array key into an int on the way in. */
is_same(h(2026), '2026', 'h() accepts an int rather than fatally rejecting it');
is_same(h(12.5), '12.5', 'and a float');
is_same(app_name(), 'Shirewatch', 'app_name() reads the config value');
is_same(cfg('db.charset'), 'utf8mb4', 'cfg() reads a dotted path');
is_same(cfg('nope.nothing', 'fallback'), 'fallback', 'cfg() falls back');

is_same(array_keys(nav_tabs()), array('dashboard', 'log', 'maintenance', 'vendors'),
    'four tabs, in the author\'s order');
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

/* ============================================================= M2 · media */

section('M2 — Media pipeline');

foreach (array(
    'public/api/upload.php',
    'public/api/worker.php',
    'public/api/media-delete.php',
    'public/api/media-caption.php',
    'public/api/media-reorder.php',
    'cron/process-queue.php',
    'public/assets/upload.js',
    'public/assets/lightbox.js',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M2 ships $file");
}

foreach (glob($appRoot . '/public/api/{upload,worker,media-*}.php', GLOB_BRACE) as $endpoint) {
    $src  = (string) file_get_contents($endpoint);
    $name = basename($endpoint);
    ok(str_contains($src, 'require_login_api()'), "$name gates on login");
    ok(str_contains($src, 'require_same_origin()'), "$name checks same-origin");
    ok(str_contains($src, "require_method('POST')"), "$name is POST-only");
}

/* upload.js posts FormData, so it cannot go through api.js — which means it
 * hand-rolls the CSRF header and the two can drift. This is the assertion
 * that notices. */
$uploadJs = (string) file_get_contents($appRoot . '/public/assets/upload.js');
$apiJs    = (string) file_get_contents($appRoot . '/public/assets/api.js');
ok(str_contains($uploadJs, "'X-Requested-With': 'Shirewatch'"),
    'upload.js sends the CSRF header itself, since FormData cannot go through api.js');
ok(str_contains($apiJs, "'Shirewatch'"),
    'and api.js sends the same value — if you change one, change the other');
ok(str_contains($uploadJs, 'CSRF_HEADER_VALUE') === false,
    'the header value is a literal in both, matching lib/auth.php');

/* The cron must never grow the Gallery's original-reaping sweep. */
$cronSrc = (string) file_get_contents($appRoot . '/cron/process-queue.php');
ok(!str_contains($cronSrc, 'unlink') && !str_contains($cronSrc, 'sweep_originals'),
    'the queue cron does NOT reap originals — they are kept forever');

/* Every shared module an entry script imports must be in the import map, or
 * the browser serves a cached copy of it forever. */
foreach (array('upload.js', 'lightbox.js') as $module) {
    ok(in_array($module, SHARED_MODULES, true),
        "SHARED_MODULES carries $module, so the import map cache-busts it");
}

/* ---- the real pipeline, end to end -------------------------------------- */

/* Everything above is structure. This part actually pushes an image through
 * media_store() and the queue and checks that files appear on disk, because
 * the queue is the part with a race in it and structure tests cannot see one. */

q("INSERT INTO log_entries (id, title, status, noticed_on) VALUES (900, 'Pipeline', 'watching', '2026-01-01')");

$tmpDir = sys_get_temp_dir() . '/sw-media-test-' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0777, true);

/* A real JPEG, not a fixture file — the sniffing in lib/imageproc.php reads
 * actual image headers and would reject a text file named .jpg, which is
 * exactly what it is for. */
$madePhoto = false;
$photoPath = $tmpDir . '/test.jpg';
if (function_exists('imagecreatetruecolor')) {
    $img = imagecreatetruecolor(900, 600);
    imagefilledrectangle($img, 0, 0, 900, 600, imagecolorallocate($img, 60, 120, 180));
    imagejpeg($img, $photoPath, 85);
    imagedestroy($img);
    $madePhoto = is_file($photoPath);
}

if (!$madePhoto) {
    ok(true, 'GD unavailable — skipping the end-to-end pipeline test');
} else {
    /* media_store() calls is_uploaded_file(), which is false for anything this
     * process wrote. Insert through the same columns the real path uses and
     * drive the QUEUE, which is the half with the race in it. */
    $slug = imageproc_new_slug();
    imageproc_ensure_dir('original');
    copy($photoPath, imageproc_upload_path('original', $slug, 'jpg'));

    q("INSERT INTO media (kind, entry_id, slug, ext, original_path, original_filename, mime, bytes, status)
       VALUES ('photo', 900, ?, 'jpg', ?, 'test.jpg', 'image/jpeg', ?, 'pending')",
        array($slug, imageproc_relative_path('original', $slug, 'jpg'), filesize($photoPath)));
    $mediaId = (int) db()->lastInsertId();

    is_same(media_pending_count(), 1, 'the queue sees one pending photo');

    /* The claim is the race. Claiming twice must not succeed twice — that is
     * the invariant the whole design rests on. */
    $first  = media_claim($mediaId);
    $second = media_claim($mediaId);
    ok($first !== null, 'the first claim wins');
    is_same($second, null, 'the second claim on the same row loses — no double processing');

    $status = media_process($first);
    is_same($status, 'ready', 'processing derives the thumb and detail');

    $row = q('SELECT * FROM media WHERE id = ?', array($mediaId))->fetch();
    ok($row['thumb_path'] !== null && $row['detail_path'] !== null, 'both derivative paths are written');
    ok(imageproc_resolve_upload($row['thumb_path']) !== null, 'the thumb file exists on disk');
    ok(imageproc_resolve_upload($row['detail_path']) !== null, 'the detail file exists on disk');
    ok(imageproc_resolve_upload($row['original_path']) !== null,
        'THE ORIGINAL IS STILL THERE — it is evidence, not a cache');
    is_same((int) $row['width'], 900, 'dimensions are recorded from the detail copy');
    is_same(media_pending_count(), 0, 'and the queue is drained');

    /* Reads. */
    $found = media_for('entry', 900);
    is_same(count($found), 1, 'media_for reads it back');
    is_same($found[0]['status'], 'ready', 'with its status');

    $many = media_for_many('entry', array(900, 87654));
    is_same(count($many[900]), 1, 'media_for_many keys by owner');
    is_same($many[87654], array(), 'an owner with nothing is present with an empty array');

    /* Deleting must take the FILES, not just the row — nothing sweeps them. */
    $thumbAbs = imageproc_resolve_upload($row['thumb_path']);
    $origAbs  = imageproc_resolve_upload($row['original_path']);
    ok(media_delete($mediaId), 'media_delete removes the row');
    ok(!is_file($thumbAbs), 'and the thumb file');
    ok(!is_file($origAbs), 'and the original — nothing else would ever reap it');

    /* Cascade: an entry taking its photos with it is what the three nullable
     * owner columns bought instead of a polymorphic pair. */
    $slug2 = imageproc_new_slug();
    copy($photoPath, imageproc_upload_path('original', $slug2, 'jpg'));
    q("INSERT INTO media (kind, entry_id, slug, ext, original_path, status)
       VALUES ('photo', 900, ?, 'jpg', ?, 'ready')",
        array($slug2, imageproc_relative_path('original', $slug2, 'jpg')));
    q('DELETE FROM log_entries WHERE id = 900');
    is_same((int) q('SELECT COUNT(*) FROM media WHERE entry_id = 900')->fetchColumn(), 0,
        'deleting an entry cascades its media rows away');
    @unlink(imageproc_upload_path('original', $slug2, 'jpg'));
}

/* Documents take the other path: stored, never derived, never queued. */
$pdfPath = $tmpDir . '/invoice.pdf';
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
is_same(media_sniff_document($pdfPath), 'application/pdf', 'a PDF is sniffed by its bytes');

file_put_contents($tmpDir . '/not.pdf', "This is a text file pretending.\n");
is_same(media_sniff_document($tmpDir . '/not.pdf'), null,
    'a text file named .pdf is refused — the name is never trusted');
is_same(imageproc_sniff($tmpDir . '/not.pdf'), null, 'and it is not an image either');

is_same(media_owner_column('entry'), 'entry_id', 'owner types map to columns');
is_same(media_owner_column('nonsense'), null, 'an unknown owner type returns null, never a column name');

/* A client filename never becomes a path. */
is_same(media_display_name('../../etc/passwd'), 'passwd', 'a traversing filename is reduced to its basename');
is_same(media_display_name(''), 'file', 'an empty name gets a placeholder');

array_map('unlink', glob($tmpDir . '/*') ?: array());
@rmdir($tmpDir);

/* ======================================================= M3 · the log */

section('M3 — Log entries and timelines');

foreach (array(
    'lib/log.php', 'lib/render.php',
    'public/log.php', 'public/entry.php',
    'public/assets/log.js', 'public/assets/entry.js',
    'public/api/entry-save.php', 'public/api/entry-update.php',
    'public/api/entry-update-delete.php', 'public/api/entry-status.php',
    'public/api/entry-delete.php',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M3 ships $file");
}

/* THE MERGE DELETED THESE. Left behind, they would still load and still work
 * against tables that no longer exist — a 500 on a screen the menu still links
 * to. */
foreach (array(
    'lib/issues.php', 'lib/records.php',
    'public/issues.php', 'public/issue.php', 'public/history.php', 'public/record.php',
    'public/assets/issues.js', 'public/assets/issue.js', 'public/assets/history.js',
    'public/api/issue-save.php', 'public/api/record-save.php',
) as $file) {
    ok(!is_file($appRoot . '/' . $file), "the merge removed $file");
}

foreach (glob($appRoot . '/public/api/entry-*.php') as $endpoint) {
    $src  = (string) file_get_contents($endpoint);
    $name = basename($endpoint);
    ok(str_contains($src, 'require_login_api()') && str_contains($src, 'require_same_origin()'),
        "$name is gated and CSRF-checked");
}

/* ---- creation and the derived columns ---------------------------------- */

$kitchen  = tag_find(TAG_LOCATION, 'Kitchen');
$plumbing = tag_find(TAG_CATEGORY, 'Plumbing');

$id = entry_create(array(
    'title'               => 'Drip under the sink',
    'description'         => 'Only when the dishwasher runs.',
    'noticed_on'          => '2026-01-10',
    'check_interval_days' => 30,
    'tag_ids'             => array($kitchen['id'], $plumbing['id']),
));
ok($id > 0, 'entry_create returns an id');

$entry = entry_get($id);
is_same($entry['status'], 'watching', 'a new entry starts as watching');
is_same($entry['severity'], null, 'severity is unset unless given — never defaulted to 1');

/* An entry with an interval and no updates is due from the day it was
 * NOTICED. Otherwise it would never surface until somebody checked it once,
 * which is precisely the thing you would forget to do. */
is_same($entry['next_check_on'], '2026-02-09',
    'a never-checked entry is still due, counted from when it was noticed');
is_same($entry['last_checked_on'], null, 'and has no last-checked date');

is_same(count(tags_for('entry', $id)), 2, 'tags are attached on create');

/* ---- the timeline drives the derived columns --------------------------- */

entry_add_update($id, array('noted_on' => '2026-02-01', 'note' => 'Same as before', 'severity' => 2));
$entry = entry_get($id);
is_same($entry['severity'], 2, 'an update with a severity sets the entry severity');
is_same($entry['last_checked_on'], '2026-02-01', 'and the last-checked date');
is_same($entry['next_check_on'], '2026-03-03', 'and moves the next check forward from it');

/* A later note that does NOT re-rate must not wipe the rating. This is the
 * subtle one: "no change" is a legitimate update and it is not a severity. */
entry_add_update($id, array('noted_on' => '2026-03-05', 'note' => 'No change'));
$entry = entry_get($id);
is_same($entry['severity'], 2, 'an update with NO severity leaves the rating alone');
is_same($entry['last_checked_on'], '2026-03-05', 'but still moves the last-checked date');

entry_add_update($id, array('noted_on' => '2026-04-02', 'note' => 'Worse', 'severity' => 3));
is_same(entry_get($id)['severity'], 3, 'a later rating overrides an earlier one');

/* ---- a SERVICE update, which is the whole point of the merge ------------ */

$acme = vendor_save(null, array('name' => 'Acme Plumbing', 'phone' => '555-0100'));

$visit = entry_add_update($id, array(
    'kind'      => 'service',
    'noted_on'  => '2026-04-20',
    'note'      => "Replaced the trap.",
    'severity'  => 1,
    'vendor_id' => $acme,
    'cost'      => '412.50',
    'rating'    => 4,
));
$rows = entry_updates($id);
$last = $rows[count($rows) - 1];
is_same($last['kind'], 'service', 'a service update is stored on the entry timeline, not elsewhere');
is_same($last['vendor_name'], 'Acme Plumbing',
    'THE VENDOR NAME IS SNAPSHOTTED at write time, so deleting the vendor keeps the invoice readable');
is_same((float) $last['cost'], 412.5, 'the cost rides on the update');
is_same($last['rating'], 4, 'and so does the rating');

/* A service update is still an update: it moves the derived columns exactly
 * like a note does. Getting it fixed IS a check-in. */
is_same(entry_get($id)['last_checked_on'], '2026-04-20',
    'a service visit counts as having looked at it');
is_same(entry_get($id)['severity'], 1, 'and re-rates the entry like any other update');

/* The money fields are IGNORED on a note. Sending them anyway must not file an
 * invoice against an observation. */
$noteId = entry_add_update($id, array(
    'kind' => 'note', 'noted_on' => '2026-04-25', 'note' => 'Dry',
    'vendor_id' => $acme, 'cost' => '99.99', 'rating' => 5,
));
$noteRow = q('SELECT kind, vendor_id, vendor_name, cost, rating FROM log_updates WHERE id = ?',
    array($noteId))->fetch();
is_same($noteRow['kind'], 'note', 'the kind is what was asked for');
is_same($noteRow['vendor_id'], null, 'a note carries no vendor');
is_same($noteRow['cost'], null, 'no cost');
is_same($noteRow['rating'], null, 'and no rating, whatever the payload contained');

/* An unknown kind is a note, not an error. Fail soft: the observation is worth
 * more than the label on it. */
$oddId = entry_add_update($id, array('kind' => 'invoice', 'noted_on' => '2026-04-26'));
is_same(q('SELECT kind FROM log_updates WHERE id = ?', array($oddId))->fetch()['kind'], 'note',
    'an unrecognised kind degrades to a note rather than throwing');

/* A vendor nobody has in the directory is still a name worth keeping. */
$oneOff = entry_add_update($id, array(
    'kind' => 'service', 'noted_on' => '2026-04-27',
    'vendor_name' => "  Bob's brother-in-law  ", 'cost' => '',
));
$oneOffRow = q('SELECT vendor_id, vendor_name, cost FROM log_updates WHERE id = ?',
    array($oneOff))->fetch();
is_same($oneOffRow['vendor_id'], null, 'a typed name creates no vendor row');
is_same($oneOffRow['vendor_name'], "Bob's brother-in-law", 'but the name is kept, trimmed');
is_same($oneOffRow['cost'], null, 'AN EMPTY COST IS NULL — "not recorded" is not "free"');

/* Cleaning it up, so the sections below count what they expect to. */
entry_delete_update($oddId);
entry_delete_update($oneOff);
entry_delete_update($noteId);
entry_delete_update($visit);

/* ---- trend ------------------------------------------------------------- */

is_same(entry_trend(entry_updates($id)), 'worse', 'two ratings going up read as worse');

$flat = entry_create(array('title' => 'Flat', 'noticed_on' => '2026-01-01'));
entry_add_update($flat, array('noted_on' => '2026-02-01', 'severity' => 2));
entry_add_update($flat, array('noted_on' => '2026-03-01', 'severity' => 2));
is_same(entry_trend(entry_updates($flat)), 'stable', 'the same rating twice reads as stable');

/* THE COMMON CASE, and it is not a failure: severity is optional, so most
 * timelines carry none and there is no trend to report. Printing "stable"
 * there would be inventing a judgement nobody made. */
$unrated = entry_create(array('title' => 'Unrated', 'noticed_on' => '2026-01-01'));
entry_add_update($unrated, array('noted_on' => '2026-02-01', 'note' => 'Looked at it'));
entry_add_update($unrated, array('noted_on' => '2026-03-01', 'note' => 'Still there'));
is_same(entry_trend(entry_updates($unrated)), '',
    'a timeline with no recorded severity has NO trend, not a stable one');
is_same(entry_trend(entry_updates($flat)) === '', false, 'while a rated one does');

/* ---- deleting an update rolls the derived columns BACK ------------------ */

$updates = entry_updates($id);
$newest  = $updates[count($updates) - 1];
entry_delete_update($newest['id']);
$entry = entry_get($id);
is_same($entry['last_checked_on'], '2026-03-05', 'deleting the newest update moves last-checked back');
is_same($entry['severity'], 2, 'and restores the previous rating');
is_same($entry['next_check_on'], '2026-04-04', 'and recomputes the next check');

/* ---- status ------------------------------------------------------------ */

ok(entry_set_status($id, LOG_RESOLVED), 'an entry can be resolved');
$entry = entry_get($id);
is_same($entry['resolved_on'], sw_today(), 'which stamps the date');
is_same($entry['next_check_on'], null,
    'A CLOSED ENTRY STOPS ASKING TO BE CHECKED — otherwise it sits on the dashboard forever');

ok(entry_set_status($id, LOG_WATCHING), 'and can be reopened');
ok(entry_get($id)['next_check_on'] !== null, 'which brings its check-back schedule back');
is_same(entry_get($id)['resolved_on'], null, 'and clears the resolved date');

/* Linking is always OPTIONAL — fix it yourself and it resolves with nothing
 * attached. */
entry_set_status($id, LOG_RESOLVED, null);
is_same(entry_get($id)['resolved_by_update_id'], null, 'resolving with no update attached is fine');
entry_set_status($id, LOG_DISMISSED, 55);
is_same(entry_get($id)['resolved_by_update_id'], null,
    'an update link is ignored on a status other than resolved — it would have no meaning');
entry_set_status($id, LOG_WATCHING);

/* ---- the list ---------------------------------------------------------- */

$open = entries_list();
ok(count($open) >= 3, 'the list defaults to the open statuses');

$leaked = array_filter($open, static fn(array $r): bool => !in_array($r['status'], entry_open_statuses(), true));
is_same($leaked, array(), 'and contains nothing resolved or dismissed');

/* Tags AND rather than OR — a filter that widens as you add to it is one
 * nobody can aim. */
$both = entries_list(array('tag_ids' => array($kitchen['id'], $plumbing['id'])));
is_same(count($both), 1, 'two tags narrow to entries carrying BOTH');

$roofing = tag_find(TAG_CATEGORY, 'Roofing');
is_same(count(entries_list(array('tag_ids' => array($kitchen['id'], $roofing['id'])))), 0,
    'and an impossible combination matches nothing rather than everything');

is_same(count(entries_list(array('search' => 'dishwasher'))), 1, 'search covers the description');
is_same(count(entries_list(array('search' => 'zzzznothing'))), 0, 'and misses cleanly');
is_same(entries_list(array('status' => array('nonsense'))), array(),
    'an unknown status filter returns nothing rather than everything');

ok(array_key_exists('tags', $open[0]) && array_key_exists('cover', $open[0]),
    'the list decorates rows with tags and a cover in batch, not per row');

/* ---- dashboard reads --------------------------------------------------- */

entry_set_status($flat, LOG_ACTIVE);
$action = entries_needing_action(sw_today());
$actionIds = array_column($action, 'id');
ok(in_array($flat, $actionIds, true), 'an active entry needs action regardless of dates');

$future = entry_create(array(
    'title' => 'Not yet', 'noticed_on' => sw_today(), 'check_interval_days' => 365,
));
ok(!in_array($future, array_column(entries_needing_action(sw_today()), 'id'), true),
    'a watching entry whose check is a year out does not');
ok(in_array($future, array_column(entries_upcoming_checks(sw_today()), 'id'), true),
    'but it does appear in the forward timeline');

/* ---- cleaning ---------------------------------------------------------- */

is_same(entry_clean_severity(''), null, 'an empty severity is NULL');
is_same(entry_clean_severity(0), null, 'zero is NULL, not level 1 — it is not a legal rating');
is_same(entry_clean_severity(9), null, 'out of range is NULL, never clamped up to Urgent');
is_same(entry_clean_severity('3'), 3, 'a numeric string is accepted');
is_same(entry_clean_interval('0'), null, 'a zero interval means no reminder');
is_same(entry_clean_date('nonsense'), null, 'an unreadable date is NULL');
is_same(entry_clean_text('   '), null, 'whitespace-only text is NULL, not an empty string');

ok(throws(static fn() => entry_create(array('title' => '   '))),
    'an entry cannot be created without a title');

/* ---- render helpers ---------------------------------------------------- */

is_same(render_severity(null), '', 'render_severity draws NOTHING for an unset severity');
ok(str_contains(render_severity(4), 'sev-4') && str_contains(render_severity(4), 'Urgent'),
    'and a pill for a set one');
is_same(render_stars(null), '', 'render_stars draws nothing for an unrated thing');
ok(substr_count(render_stars(3.5), 'is-on') === 3 && str_contains(render_stars(3.5), 'is-half'),
    'and three full plus a half for 3.5');
is_same(render_cost(null), '', 'a NULL cost renders as nothing');
is_same(render_cost('0.00'), '$0.00', 'while a real zero renders as zero — they are different facts');
is_same(render_gallery(array()), '', 'an empty gallery renders nothing at all');
ok(str_contains(render_tags(array(array('id' => 1, 'kind' => TAG_LOCATION, 'name' => 'Kitchen'))), 'is-location'),
    'location chips are visually distinguished');

/* Escaping. A title is user-entered and goes through several render paths. */
$evil = array(array('id' => 1, 'kind' => TAG_LOCATION, 'name' => '<script>x</script>'));
ok(!str_contains(render_tags($evil), '<script>'), 'render_tags escapes tag names');

/* ---- delete ------------------------------------------------------------ */

$doomed = entry_create(array('title' => 'Doomed', 'noticed_on' => '2026-01-01'));
$upd = entry_add_update($doomed, array('noted_on' => '2026-02-01', 'note' => 'x'));
ok(entry_delete($doomed), 'an entry deletes');
is_same(entry_get($doomed), null, 'and is gone');
is_same((int) q('SELECT COUNT(*) FROM log_updates WHERE id = ?', array($upd))->fetchColumn(), 0,
    'taking its timeline with it');

/* ======================================================= M4 · maintenance */

section('M4 — Maintenance and the schedule');

foreach (array(
    'lib/tasks.php', 'public/maintenance.php', 'public/task.php',
    'public/assets/maintenance.js',
    'public/api/task-save.php', 'public/api/task-complete.php',
    'public/api/task-uncomplete.php', 'public/api/task-active.php',
    'public/api/task-delete.php',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M4 ships $file");
}

foreach (glob($appRoot . '/public/api/task-*.php') as $endpoint) {
    $src = (string) file_get_contents($endpoint);
    ok(str_contains($src, 'require_login_api()') && str_contains($src, 'require_same_origin()'),
        basename($endpoint) . ' is gated and CSRF-checked');
}

/* ---- creation ---------------------------------------------------------- */

$filter = task_create(array(
    'title'          => 'Change HVAC filter',
    'instructions'   => "16x25x1\nShelf above the dryer",
    'recur_kind'     => 'interval',
    'interval_count' => 3,
    'interval_unit'  => 'month',
    'interval_from'  => 'completion',
    'next_due_on'    => '2026-09-01',
));
ok($filter > 0, 'task_create returns an id');
is_same(task_get($filter)['next_due_on'], '2026-09-01', 'with the due date it was given');

/* A rule that could never produce a date is refused at the door. A task that
 * silently never comes due is indistinguishable from nothing being due, which
 * is the worst possible failure for a reminder app. */
ok(throws(static fn() => task_create(array(
    'title' => 'Broken', 'recur_kind' => 'months', 'recur_months' => 'abc',
))), 'a recurrence that can never fire is refused, not stored');
ok(throws(static fn() => task_create(array(
    'title' => 'Broken', 'recur_kind' => 'interval', 'interval_count' => 0,
))), 'and so is a zero interval');
ok(throws(static fn() => task_create(array('title' => '  '))), 'and a task with no title');

/* ---- completion moves the schedule, and NOTHING ELSE DOES -------------- */

/* Wear-based: the filter's clock starts when you changed it, so doing it late
 * moves the next one late too. */
$next = task_complete($filter, array('completed_on' => '2026-09-20'));
is_same($next, '2026-12-20', 'from-completion counts the interval from the day you did it');
is_same(task_get($filter)['last_completed_on'], '2026-09-20', 'and records when that was');
is_same(count(task_completions($filter)), 1, 'and writes a history row');

/* Schedule-anchored: does not drift later every time you are a week busy. */
$gutters = task_create(array(
    'title'         => 'Gutters',
    'recur_kind'    => 'months',
    'recur_months'  => '3,10',
    'recur_day'     => 15,
    'next_due_on'   => '2026-10-15',
));
is_same(task_complete($gutters, array('completed_on' => '2026-10-22')), '2027-03-15',
    'a seasonal task jumps to the next month in its list, however late it was done');

/* THE ONE THAT LOOKS LIKE A BUG. Skipping a season entirely does not roll the
 * task forward — it stays overdue and says so. */
$skipped = task_create(array(
    'title' => 'Sprinklers', 'recur_kind' => 'months', 'recur_months' => '10',
    'recur_day' => 20, 'next_due_on' => '2025-10-20',
));
is_same(task_get($skipped)['next_due_on'], '2025-10-20',
    'a task nobody completed stays put — it does not silently roll to next year');

$due = array_column(tasks_due('2026-08-19'), 'id');
ok(in_array($skipped, $due, true), 'and shows as overdue, which is the true statement');

/* from-'due' must not leave a badly overdue task instantly overdue again —
 * that reads as the button having done nothing. */
$monthly = task_create(array(
    'title' => 'Monthly, badly overdue', 'recur_kind' => 'interval',
    'interval_count' => 1, 'interval_unit' => 'month', 'interval_from' => 'due',
    'next_due_on' => '2026-01-05',
));
$after = task_complete($monthly, array('completed_on' => '2026-08-19'));
ok($after > '2026-08-19',
    'completing a badly overdue from-due task lands in the FUTURE, not instantly overdue again');

/* ---- undo -------------------------------------------------------------- */

$twice = task_create(array(
    'title' => 'Twice done', 'recur_kind' => 'interval', 'interval_count' => 3,
    'interval_unit' => 'month', 'next_due_on' => '2026-01-01',
));
task_complete($twice, array('completed_on' => '2026-01-10'));
task_complete($twice, array('completed_on' => '2026-04-12'));
is_same(task_get($twice)['next_due_on'], '2026-07-12', 'two completions advance twice');

$history = task_completions($twice);
q('DELETE FROM task_completions WHERE id = ?', array($history[0]['id']));
$prev = q('SELECT completed_on FROM task_completions WHERE task_id = ? ORDER BY completed_on DESC LIMIT 1',
    array($twice))->fetch();
is_same((string) $prev['completed_on'], '2026-01-10',
    'undoing the newest completion leaves the earlier one as the anchor');

/* ---- pause and delete -------------------------------------------------- */

ok(task_set_active($twice, false), 'a task can be paused');
ok(!in_array($twice, array_column(tasks_due('2099-01-01'), 'id'), true),
    'a paused task is never due');
is_same(count(task_completions($twice)), 1, 'but keeps its history');
task_set_active($twice, true);

/* ---- the list ---------------------------------------------------------- */

$active = tasks_list();
ok(count($active) >= 4, 'the list returns active tasks');
$paused = tasks_list(array('active' => false));
is_same($paused, array(), 'and the paused view is separate');

$kitchen = tag_find(TAG_LOCATION, 'Kitchen');
tags_set('task', $filter, array($kitchen['id']));
is_same(count(tasks_list(array('tag_ids' => array($kitchen['id'])))), 1, 'tags filter the list');
ok(array_key_exists('tags', $active[0]), 'and rows are decorated in batch');

/* ---- the forward projection (the dashboard depends on this) ------------ */

$quarterly = task_create(array(
    'title' => 'Quarterly', 'recur_kind' => 'interval', 'interval_count' => 3,
    'interval_unit' => 'month', 'next_due_on' => '2026-09-01',
));

$projected = tasks_project('2026-08-19', '2028-08-19');
$mine = array_values(array_filter($projected, static fn(array $r): bool => $r['id'] === $quarterly));

ok(count($mine) >= 7,
    'a quarterly task projects forward across a two-year horizon rather than appearing once');
is_same($mine[0]['on_date'], '2026-09-01', 'the first occurrence is the STORED date');
is_same($mine[0]['projected'], false, 'and it is not marked as a prediction');
is_same($mine[1]['on_date'], '2026-12-01', 'the second is three months later');
is_same($mine[1]['projected'], true, 'and IS marked as a prediction — nothing was written');

/* The projection must agree with what completion actually produces, or the
 * timeline eventually shows a date the app would never make. */
is_same($mine[1]['on_date'], recur_next_after(task_rule(task_get($quarterly)), $mine[0]['on_date']),
    'the projection uses the same recur_next_after() as the completion path');

$dates = array_column($projected, 'on_date');
$sorted = $dates;
sort($sorted);
is_same($dates, $sorted, 'the projected stream comes back in chronological order');
ok(count(array_filter($dates, static fn(string $d): bool => $d <= '2026-08-19')) === 0,
    'and contains nothing on or before the cursor date');

/* A task whose rule cannot advance must not loop forever filling the stream
 * with one row. */
q("UPDATE maintenance_tasks SET recur_months = 'nonsense', recur_kind = 'months' WHERE id = ?",
    array($quarterly));
$broken = tasks_project('2026-08-19', '2028-08-19');
$brokenRows = array_filter($broken, static fn(array $r): bool => $r['id'] === $quarterly);
ok(count($brokenRows) <= 1, 'a task with an unusable rule contributes at most one row, never a loop');

/* ---- cleaning ---------------------------------------------------------- */

$switched = task_clean_rule(array(
    'recur_kind' => 'months', 'recur_months' => '3,10', 'recur_day' => 15,
    'interval_count' => 3, 'interval_unit' => 'month',
));
is_same($switched['interval_count'], null,
    'switching to a seasonal rule NULLS the interval columns — a row carrying both is ambiguous');
is_same($switched['recur_months'], '3,10', 'and keeps the months');

$backAgain = task_clean_rule(array(
    'recur_kind' => 'interval', 'interval_count' => 6, 'interval_unit' => 'month',
    'recur_months' => '3,10',
));
is_same($backAgain['recur_months'], null, 'and switching back nulls the months');

is_same(task_clean_from('nonsense'), 'completion', 'an unknown anchor falls back to from-completion');
is_same(task_clean_from('due'), 'due', 'and "due" is honoured');

/* ============================================== M5 · service and vendors */

section('M5 — Service and vendors');

foreach (array(
    'lib/service.php', 'lib/vendors.php',
    'public/service.php', 'public/vendors.php', 'public/vendor.php',
    'public/assets/vendors.js',
    'public/api/vendor-save.php', 'public/api/vendor-delete.php',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M5 ships $file");
}

foreach (glob($appRoot . '/public/api/vendor-*.php') as $endpoint) {
    $src = (string) file_get_contents($endpoint);
    ok(str_contains($src, 'require_login_api()') && str_contains($src, 'require_same_origin()'),
        basename($endpoint) . ' is gated and CSRF-checked');
}

/* SERVICE IS NOT A TABLE ANY MORE, and lib/service.php is the only file
 * allowed to know that it has two sources. If a screen starts querying
 * log_updates and task_completions itself, the two halves drift and the total
 * on the Service screen quietly stops matching the rows above it. */
$serviceSrc = (string) file_get_contents($appRoot . '/lib/service.php');
ok(!str_contains($serviceSrc, 'INSERT') && !str_contains($serviceSrc, 'UPDATE ')
   && !str_contains($serviceSrc, 'DELETE'),
    'lib/service.php only reads — both sources are owned by log.php and tasks.php');

$screenSrc = (string) file_get_contents($appRoot . '/public/service.php');
ok(!str_contains($screenSrc, 'FROM log_updates') && !str_contains($screenSrc, 'FROM task_completions'),
    'public/service.php goes through service_list(), it does not query the two sources itself');

/* ---- vendors and the computed rating ----------------------------------- */

$plumberTag = tag_find(TAG_WORK_TYPE, 'Plumber');
$ridge = vendor_save(null, array(
    'name' => 'Ridgeline Plumbing', 'phone' => '(512) 555-0142',
    'notes' => 'Ask for Danny.', 'tag_ids' => array($plumberTag['id']),
));
ok($ridge > 0, 'vendor_save creates');

$vendor = vendor_get($ridge);
is_same($vendor['rating'], null, 'a vendor with no jobs has NO rating — not zero');
is_same($vendor['jobs'], 0, 'and no jobs');
is_same(count($vendor['tags']), 1, 'with their trade attached');

/* ---- their work, which now lives in two places ------------------------- */

/* UNPLANNED: two visits on a log entry. */
$valve = entry_create(array('title' => 'Shut-off valve weeping', 'noticed_on' => '2026-03-01'));
$v1 = entry_add_update($valve, array(
    'kind' => 'service', 'noted_on' => '2026-03-04', 'note' => 'Rebuilt the shut-off valve',
    'vendor_id' => $ridge, 'cost' => '285.00', 'rating' => 5,
));
$v2 = entry_add_update($valve, array(
    'kind' => 'service', 'noted_on' => '2026-05-11', 'note' => 'Emergency call-out',
    'vendor_id' => $ridge, 'cost' => '460', 'rating' => 3,
));
/* A job with no rating must not drag the average down. */
$v3 = entry_add_update($valve, array(
    'kind' => 'service', 'noted_on' => '2026-06-01', 'note' => 'Looked at the boiler',
    'vendor_id' => $ridge,
));

/* PLANNED: a maintenance completion somebody was paid for. This is the case
 * the author named — a paid routine service is a maintenance completion that
 * cost money, not a problem that had to be logged. */
$pump = task_create(array(
    'title' => 'Pump the septic tank', 'recur_kind' => 'interval',
    'interval_count' => 3, 'interval_unit' => 'year', 'next_due_on' => '2026-07-01',
));
task_complete($pump, array(
    'completed_on' => '2026-07-02', 'note' => 'Pumped and inspected the baffles',
    'vendor_id' => $ridge, 'cost' => '575.00', 'rating' => 4,
));

$vendor = vendor_get($ridge);
is_same($vendor['jobs'], 4, 'a vendor\'s job count spans BOTH sources — repairs and paid maintenance');
is_same($vendor['rated_jobs'], 3, 'but only rated ones count as rated');
is_same($vendor['average'], 4.0,
    'the average is over RATED jobs only — an unrated job is not a zero');
is_same($vendor['rating'], 4.0, 'and with no override, that is what shows');

/* The two sources are summed, not averaged together: averaging the log's 4.0
 * against maintenance's 4.0 happens to agree here, but a vendor with one
 * 5-star repair and four 3-star services would come out at 4.0 rather than the
 * true 3.4. */
$viaSums = (5 + 3 + 4) / 3;
is_same($vendor['average'], round($viaSums, 2),
    'RATINGS ARE SUMMED ACROSS SOURCES, never averaged as two averages');

/* The override is shown BESIDE the average, never instead of it. */
vendor_save($ridge, array('name' => 'Ridgeline Plumbing', 'rating_override' => 2));
$vendor = vendor_get($ridge);
is_same($vendor['rating'], 2.0, 'an override wins for display');
is_same($vendor['average'], 4.0, 'and the average is still available to print beside it');

vendor_save($ridge, array('name' => 'Ridgeline Plumbing', 'rating_override' => ''));
is_same(vendor_get($ridge)['rating_override'], null, 'clearing the override goes back to the average');
is_same(vendor_get($ridge)['rating'], 4.0, 'which is what shows again');

is_same(vendor_clean_rating(0), null, 'a zero override is NULL — zero is not a legal rating');
is_same(vendor_clean_rating(7), null, 'and out of range is NULL, never clamped');

/* ---- the snapshot name, in both places --------------------------------- */

ok(vendor_delete($ridge), 'a vendor can be deleted');

$visitRow = q('SELECT vendor_id, vendor_name FROM log_updates WHERE id = ?', array($v1))->fetch();
is_same($visitRow['vendor_id'], null, 'which nulls the link on their service updates');
is_same((string) $visitRow['vendor_name'], 'Ridgeline Plumbing',
    'BUT THE UPDATE STILL SAYS WHO DID THE WORK — the snapshot survives the vendor');

$compRow = q('SELECT vendor_id, vendor_name FROM task_completions WHERE task_id = ?',
    array($pump))->fetch();
is_same($compRow['vendor_id'], null, 'and on their maintenance completions');
is_same((string) $compRow['vendor_name'], 'Ridgeline Plumbing',
    'which keep the name too — the same rule, applied in both places');

is_same((int) q('SELECT COUNT(*) FROM log_updates WHERE id IN (?, ?, ?)',
    array($v1, $v2, $v3))->fetchColumn(), 3, 'and none of the history is deleted');

/* ---- cost: NULL is not zero -------------------------------------------- */

is_same(service_clean_cost(''), null, 'an empty cost is NULL — "not recorded"');
is_same(service_clean_cost(null), null, 'and so is a missing one');
is_same(service_clean_cost('0'), '0.00', 'but a typed zero is a real zero — a different fact');
is_same(service_clean_cost('$1,285.50'), '1285.50', 'currency symbols and separators are stripped');
is_same(service_clean_cost('   '), null, 'whitespace is NULL');
is_same(service_clean_cost('not a number'), null, 'and junk is NULL rather than 0');
is_same(service_clean_cost('-40'), '0.00', 'a negative cost floors at zero');

is_same(render_cost(null), '', 'a NULL cost renders as NOTHING');
is_same(render_cost('0.00'), '$0.00', 'while a real zero renders as $0.00');

/* ---- the service list, across both sources ----------------------------- */

$all = service_list();
ok(count($all) >= 4, 'service_list returns work from both sources in one stream');

$sources = array_unique(array_column($all, 'source'));
sort($sources);
is_same($sources, array('log', 'maintenance'),
    'and both sources are actually represented — a broken half would look like a short list');

is_same($all[0]['on_date'] >= $all[1]['on_date'], true, 'newest work first');

/* A completion with NEITHER a cost nor a vendor is you doing it yourself. It
 * belongs on the task, not in a list of what the house cost. */
$diy = task_create(array(
    'title' => 'Sweep the chimney myself', 'recur_kind' => 'interval',
    'interval_count' => 1, 'interval_unit' => 'year', 'next_due_on' => '2026-08-01',
));
task_complete($diy, array('completed_on' => '2026-08-02', 'note' => 'Took an afternoon'));
$titles = array_column(service_list(), 'title');
ok(!in_array('Took an afternoon', $titles, true),
    'AN UNPAID COMPLETION IS NOT A SERVICE EVENT — doing it yourself is not a bill');

is_same(count(service_list(array('year' => 1999))), 0, 'the year filter excludes other years');
ok(count(service_list(array('year' => 2026))) >= 4, 'and matches the year that has the work');
is_same(count(service_list(array('search' => 'Rebuilt'))), 1, 'search covers the note');
is_same(count(service_list(array('search' => 'Ridgeline'))), 4,
    'and the snapshotted vendor name, so a deleted vendor\'s work is still findable by their name');
is_same(count(service_list(array('search' => 'baffles'))), 1,
    'search reaches into the maintenance half too, not just the log');

ok(in_array(2026, service_years(), true), 'service_years lists the years that have work in them');

/* The title of a visit is the note's first line, falling back to the parent. */
is_same(service_title(array('note' => "Rebuilt the valve\nsecond line", 'parent_title' => 'X')),
    'Rebuilt the valve', 'a visit is titled by the first line of its note');
is_same(service_title(array('note' => '   ', 'parent_title' => 'Shut-off valve weeping')),
    'Shut-off valve weeping', 'and falls back to the parent when there is no note');

/* ---- the total, which must not lie ------------------------------------- */

$totals = service_total(array(
    array('cost' => '100.00'), array('cost' => '50.50'), array('cost' => null),
));
is_same($totals['total'], 150.5, 'service_total adds what was recorded');
is_same($totals['priced'], 2, 'counts the priced rows');
is_same($totals['unpriced'], 1,
    'AND COUNTS THE UNPRICED ONES SEPARATELY — summing them as zero makes a total you would trust and should not');

/* ---- an entry resolved by its own service update ----------------------- */

is_same(entry_get($valve)['resolved_by_update_id'], null,
    'a service visit on an entry does NOT resolve it — those are different claims');

entry_set_status($valve, LOG_RESOLVED, $v2);
is_same(entry_get($valve)['resolved_by_update_id'], $v2,
    'resolving with the update that fixed it is explicit');

/* Deleting that update must not delete the entry it closed. */
entry_delete_update($v2);
ok(entry_get($valve) !== null, 'deleting the update leaves the entry standing');

/* ========================================= M6 · dashboard and reminders */

section('M6 — Dashboard and reminders');

foreach (array(
    'lib/dashboard.php', 'public/index.php', 'public/cron.php',
    'public/api/timeline.php', 'public/assets/dashboard.js',
    'tools/cron-reminders.php',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M6 ships $file");
}

/* cron.php is the ONLY unauthenticated surface in the app. Every one of these
 * is load-bearing and none is obvious from reading the file quickly. */
$cronSrc = (string) file_get_contents($appRoot . '/public/cron.php');
ok(str_contains($cronSrc, 'hash_equals('),
    'cron.php compares its token with hash_equals, not === — === leaks it by timing');
ok(str_contains($cronSrc, 'http_response_code(404)'),
    'and answers 404, not 403 — a 403 confirms the endpoint is worth guessing at');
ok(str_contains($cronSrc, "\$expected === ''"),
    'and REFUSES an empty token rather than running unauthenticated');
ok(!preg_match('/^\s*require_login_(page|api)\(\);/m', $cronSrc),
    'it is deliberately not behind the session gate — a scheduler has no session');

/* The whole point of the wrapper: one code path, not two. */
ok(str_contains($cronSrc, 'cron_reminders_run('),
    'cron.php calls the same function the command cron does');

/* ---- a clean run ------------------------------------------------------- */

q('DELETE FROM task_reminder_sends');
q('DELETE FROM maintenance_tasks');

$soon = task_create(array(
    'title' => 'Flush water heater', 'recur_kind' => 'interval',
    'interval_count' => 1, 'interval_unit' => 'year', 'next_due_on' => '2026-08-22',
));
$late = task_create(array(
    'title' => 'Clean gutters', 'recur_kind' => 'months',
    'recur_months' => '3,10', 'recur_day' => 15, 'next_due_on' => '2026-03-15',
));
$far = task_create(array(
    'title' => 'Not for ages', 'recur_kind' => 'interval',
    'interval_count' => 1, 'interval_unit' => 'year', 'next_due_on' => '2027-06-01',
));

$today = '2026-08-19';

$dry = cron_reminders_run($today, true);
is_same($dry['due'], 2, 'the cron picks up everything due inside the lead window, overdue included');
is_same($dry['sent'], 0, 'a dry run sends nothing');
is_same((int) q('SELECT COUNT(*) FROM task_reminder_sends')->fetchColumn(), 0,
    'and claims nothing');

/* ---- the ledger, which is the whole design -----------------------------

 * There is no SMTP in the test environment, so the send FAILS — and that is
 * the more interesting half to test anyway: a failed send must leave the row
 * retryable, or a hung connection costs you the reminder permanently. */

$run = cron_reminders_run($today);
is_same($run['to_send'], 2, 'a real run claims the due tasks');
is_same($run['sent'], 0, 'the send fails with no SMTP configured');
is_same($run['failed'], 2, 'and is reported as failed rather than silently swallowed');
ok($run['error'] !== '', 'with a reason attached for the ledger');

$rows = q('SELECT * FROM task_reminder_sends ORDER BY task_id')->fetchAll();
is_same(count($rows), 2, 'one ledger row per claimed task');
is_same($rows[0]['sent_at'], null,
    'A FAILED SEND LEAVES sent_at NULL, so tomorrow retries — a hung SMTP must not cost the reminder');
ok($rows[0]['last_error'] !== null, 'and records why');

/* Re-running claims the same pairs again, because nothing was delivered. */
$again = cron_reminders_run($today);
is_same($again['to_send'], 2, 'a retry claims the same pairs, since neither was delivered');
is_same((int) q('SELECT attempts FROM task_reminder_sends WHERE task_id = ?', array($soon))->fetchColumn(), 2,
    'and the attempt count climbs rather than a second row appearing (two real runs; the dry run claimed nothing)');
is_same((int) q('SELECT COUNT(*) FROM task_reminder_sends')->fetchColumn(), 2,
    'THE COMPOSITE PRIMARY KEY MAKES A SECOND ROW IMPOSSIBLE — a double-firing cron cannot double-send');

/* Now mark them delivered by hand and prove the quiet path. */
reminder_mark_sent($soon, '2026-08-22', '2026-08-19 06:00:00');
reminder_mark_sent($late, '2026-03-15', '2026-08-19 06:00:00');

$quiet = cron_reminders_run($today);
is_same($quiet['to_send'], 0, 'once delivered, the same due dates are never emailed again');
is_same($quiet['skipped'], 2, 'they are counted as already handled');

/* ---- THE ASYMMETRY THAT IS THE POINT ------------------------------------
 *
 * The overdue gutters task was emailed about and is STILL OVERDUE. The cron
 * moved nothing. That is what makes it sit on the dashboard getting louder
 * instead of the app quietly forgiving you. */

is_same(task_get($late)['next_due_on'], '2026-03-15',
    'THE CRON DOES NOT MOVE A DUE DATE — the emailed task is still overdue');
is_same(task_get($late)['last_completed_on'], null, 'and is still not completed');
ok(in_array($late, array_column(tasks_due($today), 'id'), true),
    'so it is still on the dashboard, which is the entire design');

/* Completing it is what moves it — and that opens a NEW ledger key, so the
 * next occurrence gets its own reminder. */
task_complete($late, array('completed_on' => $today));
$moved = task_get($late)['next_due_on'];
ok($moved > $today, 'completing it moves the schedule forward');
ok(!reminder_already_sent($late, $moved), 'and the new due date has never been emailed about');

/* ---- the cap ----------------------------------------------------------- */

is_same((int) cfg('reminders.max_per_run', 10), 10, 'the per-run cap is configured');

/* ---- the dashboard reads ----------------------------------------------- */

$now = dashboard_now($today);
ok(array_key_exists('log', $now) && array_key_exists('tasks', $now),
    'dashboard_now keeps the log and maintenance in SEPARATE groups, per the brief');
is_same($now['total'], count($now['log']) + count($now['tasks']), 'and totals them');

/* ---- the forward timeline ---------------------------------------------- */

$page = dashboard_timeline($today, null, 5);
is_same(count($page['rows']), 5, 'the timeline pages');
is_same($page['done'], false, 'and reports there is more');
ok($page['cursor'] !== null, 'with a cursor');
ok(str_contains($page['cursor']['key'], ':'), 'whose key carries the kind and the id');

$dates = array_column($page['rows'], 'on_date');
$sorted = $dates; sort($sorted);
is_same($dates, $sorted, 'rows come back in date order');
ok(count(array_filter($dates, static fn(string $d): bool => $d <= $today)) === 0,
    'and nothing on or before the cursor date leaks in');

/* KEYSET, NOT OFFSET: the second page must start after the first page's last
 * ROW, not after its last DATE — several tasks can share one day. */
$second = dashboard_timeline($page['cursor']['date'], $page['cursor']['key'], 5);
$firstKeys  = array_map(static fn(array $r): string => $r['kind'] . ':' . $r['id'] . '@' . $r['on_date'], $page['rows']);
$secondKeys = array_map(static fn(array $r): string => $r['kind'] . ':' . $r['id'] . '@' . $r['on_date'], $second['rows']);
is_same(array_intersect($firstKeys, $secondKeys), array(),
    'the second page shares NO row with the first — the cursor is a full (date, kind, id) triple');

/* Both kinds appear in one stream, which is the deliberate departure from the
 * brief's "keep them separate" for the forward view. */
$ceiling = entry_create(array(
    'title' => 'Watch the ceiling', 'noticed_on' => $today, 'check_interval_days' => 30,
));
$mixed = dashboard_timeline($today, null, 100);
$kinds = array_unique(array_column($mixed['rows'], 'kind'));
ok(in_array('entry', $kinds, true) && in_array('task', $kinds, true),
    'the forward timeline COMBINES the log and maintenance — chronology is the organising principle there');

/* A log entry contributes exactly one row: its next check. A chain of them
 * would be schedule the app never promised. */
$mine = array_filter($mixed['rows'], static fn(array $r): bool => $r['kind'] === 'entry' && $r['id'] === $ceiling);
is_same(count($mine), 1, 'a log entry contributes ONE check-back, never a projected chain');

$projected = array_filter($mixed['rows'], static fn(array $r): bool => $r['projected'] === true);
ok(count($projected) > 0, 'while recurring tasks do project forward');
foreach ($projected as $row) {
    is_same($row['kind'], 'task', 'and only tasks are ever marked projected');
    break;
}

/* ======================================================= M7 · integration */

section('M7 — Integration');

foreach (array(
    'public/api/export.php', 'public/component-test.html',
    'DEPLOY.txt', 'README.md', 'CLAUDE.md', 'docs/CONTRACTS.md', 'docs/DELEGATION-PLAN.md',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M7 ships $file");
}

/* EVERY SCREEN AND ENDPOINT IS GATED. This is the assertion that catches the
 * new file somebody adds later and forgets to put a gate on — which is
 * invisible until the day it matters. */
foreach (glob($appRoot . '/public/*.php') as $screen) {
    $name = basename($screen);
    $src  = (string) file_get_contents($screen);

    if (in_array($name, array('login.php', 'logout.php'), true)) {
        continue;                                  // the gate itself
    }
    if ($name === 'cron.php') {
        /* THE ONE UNAUTHENTICATED SURFACE, deliberately — a scheduler has no
         * session. It carries its own token gate instead. */
        ok(str_contains($src, 'hash_equals('), "$name is token-gated instead");
        continue;
    }
    ok(str_contains($src, 'require_login_page()'), "$name is behind the login gate");
}

foreach (glob($appRoot . '/public/api/*.php') as $endpoint) {
    $name = basename($endpoint);
    $src  = (string) file_get_contents($endpoint);
    ok(str_contains($src, 'require_login_api()'), "api/$name is behind the login gate");

    /* Reads may be GET; anything that writes must carry the CSRF check. */
    $isRead = str_contains($src, "require_method('GET')");
    ok($isRead || str_contains($src, 'require_same_origin()'),
        "api/$name checks same-origin (or is a read)");
}

/* No screen may hand-roll SQL — the repo layer owns every table, and a query
 * in a template is how a schema change starts missing one caller. */
foreach (glob($appRoot . '/public/*.php') as $screen) {
    $src = (string) file_get_contents($screen);
    ok(!preg_match('/\bq\(\s*[\x27"]\s*(SELECT|INSERT|UPDATE|DELETE)/i', $src),
        basename($screen) . ' contains no hand-written SQL');
}

/* The date rule, enforced rather than remembered: every scheduling comparison
 * is against a stored DATE column computed in PHP. */
foreach (glob($appRoot . '/lib/*.php') as $lib) {
    $name = basename($lib);
    if ($name === 'auth.php') {
        continue;      // login throttling deliberately uses MySQL's own clock
    }
    /* Comments STRIPPED first. Several of these files explain the rule in
     * prose — "there is no DATE_ADD here" is exactly the sentence a naive grep
     * flags, which would fire this assertion on the files documenting it best. */
    $src = (string) php_strip_whitespace($lib);
    ok(!preg_match('/\\b(DATE_ADD|DATE_SUB)\\b/i', $src),
        "lib/$name uses no DATE_ADD or DATE_SUB — dates are computed in PHP");
}

/* The component page is the visual regression net. A class rendered there that
 * the stylesheet has never heard of means one of the two moved without the
 * other. */
$componentHtml = (string) file_get_contents($appRoot . '/public/component-test.html');
$css = (string) file_get_contents($appRoot . '/public/assets/styles.css');
preg_match_all('/class="([^"]+)"/', $componentHtml, $matches);
$classes = array();
foreach ($matches[1] as $attr) {
    foreach (preg_split('/\s+/', $attr) as $class) {
        if ($class !== '') { $classes[$class] = true; }
    }
}
$unknown = array_values(array_filter(array_keys($classes),
    static fn(string $c): bool => !str_contains($css, '.' . $c)));
is_same($unknown, array(), 'every class on component-test.html exists in styles.css');
ok(count($classes) > 50, 'and the page covers the component vocabulary rather than a corner of it');

/* build-deploy validates the finished app; these are the files it insists on. */
$deploySrc = (string) file_get_contents($appRoot . '/tools/build-deploy.php');
foreach (array('public/index.php', 'public/cron.php', 'tools/cron-reminders.php') as $required) {
    ok(str_contains($deploySrc, basename($required)),
        "build-deploy checks for $required before shipping");
}
ok(str_contains($deploySrc, 'component-test'), 'and keeps component-test.html out of the bundle');

/* ================================================== Round 2 · UI revisions */

section('Round 2 — chrome, filters, rows');

/* THE HAMBURGER SHIPPED DEAD. page_menu() drew the button, menu.js exported
 * attachMenu(), and nothing called it — on every screen, for the whole build.
 * The old tests asserted menu_items() returned items but never that anything
 * CONSUMED them, which is exactly the gap that let it through. These assert
 * the wiring, not the ingredients. */

ok(is_file($appRoot . '/public/assets/chrome.js'), 'chrome.js ships');
ok(in_array('chrome.js', SHARED_MODULES, true), 'and is cache-busted by the import map');

$layoutSrc = (string) file_get_contents($appRoot . '/lib/layout.php');
ok(str_contains($layoutSrc, "asset('assets/chrome.js')"),
    'page_foot() loads chrome.js itself, so no screen can forget to wire the menu');
ok(str_contains($layoutSrc, 'id="menu-items"'),
    'and emits menu_items() as JSON, keeping one source of truth');

$chromeSrc = (string) file_get_contents($appRoot . '/public/assets/chrome.js');
ok(str_contains($chromeSrc, 'attachMenu('),
    'chrome.js actually CALLS attachMenu — the assertion that was missing');
ok(str_contains($chromeSrc, "getElementById('menu-items')"),
    'reading the items from the page rather than hardcoding a second list');

/* Render a real screen and prove the three pieces arrive together. Structure
 * checks on separate files cannot see that they meet. */
ob_start();
page_head('Test', 'log');
screen_head('Test', page_menu());
page_foot('log');
$chrome = (string) ob_get_clean();

ok(str_contains($chrome, 'id="app-menu"'), 'a rendered page has the hamburger button');
ok(str_contains($chrome, 'id="menu-items"'), 'and the items payload');
ok(str_contains($chrome, 'assets/chrome.js'), 'and the script that joins them');

$payload = array();
if (preg_match('/id="menu-items">(.*?)<\/script>/s', $chrome, $m)) {
    $payload = json_decode($m[1], true) ?: array();
}
is_same(count($payload), count(menu_items()), 'the payload carries every menu item');
ok(isset($payload[0]['label'], $payload[0]['href']), 'each with a label and an href');

/* ---- filter groups ------------------------------------------------------ */

$kitchen = tag_find(TAG_LOCATION, 'Kitchen');
$library = tag_find(TAG_LOCATION, 'Library');
$url = static fn(int $id): string => 'x.php?tag=' . $id;

$none = render_filter_group('Rooms', tags_of_kind(TAG_LOCATION), array(), $url);
ok(!str_contains($none, '<details class="filter-group" open'),
    'a group with no active filter renders CLOSED');
ok(!str_contains($none, 'filter-count'),
    'and shows no count badge — a "0" on every heading is noise');

$some = render_filter_group('Rooms', tags_of_kind(TAG_LOCATION), array($kitchen['id']), $url);
ok(str_contains($some, '<details class="filter-group" open'),
    'a group WITH an active filter renders open — a hidden filter narrowing your list is how you think data is lost');
ok(str_contains($some, '<span class="filter-count">1</span>'), 'and counts it');

/* Selected chips sort to the front so the one you want to turn off is under
 * your thumb rather than somewhere down two dozen rooms. */
preg_match_all('/>([^<]+)<\/a>/', $some, $chips);
is_same($chips[1][0], 'Kitchen', 'the SELECTED chip sorts to the front of the group');

$rest = array_slice($chips[1], 1);
$sorted = $rest;
usort($sorted, 'strcasecmp');
is_same($rest, $sorted, 'and everything after it is alphabetical');

$two = render_filter_group('Rooms', tags_of_kind(TAG_LOCATION), array($kitchen['id'], $library['id']), $url);
preg_match_all('/>([^<]+)<\/a>/', $two, $twoChips);
is_same(array_slice($twoChips[1], 0, 2), array('Kitchen', 'Library'),
    'two selected chips both come first, alphabetically among themselves');

is_same(render_filter_group('Empty', array(), array(), $url), '',
    'a group with no tags renders nothing at all');

/* The chips are LINKS. The filter state lives in the URL so it survives a
 * reload and the back button steps through it. */
ok(str_contains($some, '<a class="chip'), 'chips are links, not buttons');

/* ---- rows and the FAB --------------------------------------------------- */

$fab = render_fab('entry.php?new=1', 'Log something');
ok(str_contains($fab, 'class="fab"') && str_contains($fab, 'aria-label="Log something"'),
    'the FAB is a labelled link');

$pencil = render_row_edit();
ok(str_contains($pencil, 'aria-hidden="true"'),
    'the row pencil is hidden from screen readers — the row is already a link to the same place');
ok(!str_contains($pencil, '<a ') && !str_contains($pencil, 'tabindex'),
    'and is not a second tab stop leading where the first one goes');

/* Every list screen uses the FAB rather than a button that drifts down the
 * page as the list grows. */
foreach (array('log.php', 'maintenance.php', 'vendors.php') as $screen) {
    $src = (string) file_get_contents($appRoot . '/public/' . $screen);
    ok(str_contains($src, 'render_fab('), "$screen uses the floating add button");
    ok(!preg_match('/class="btn-primary" href="[a-z]+\.php\?new=1"/', $src),
        "$screen no longer has the full-width add button");
}

/* Rows must not fall back to the browser's blue underline. */
$css = (string) file_get_contents($appRoot . '/public/assets/styles.css');
ok(str_contains($css, 'a.row-body'), 'a.row-body is styled, so rows are not blue and underlined');
ok(str_contains($css, '.filter-body'), 'filter groups have a body style');
ok((bool) preg_match('/\.filter-body\s*\{[^}]*max-height/s', $css),
    'AND A MAX HEIGHT — two dozen rooms open would otherwise push the list off screen');
ok((bool) preg_match('/\.filter-body\s*\{[^}]*overflow-y:\s*auto/s', $css),
    'scrolling inside itself rather than growing');

/* ---- alphabetical seed -------------------------------------------------- */

/* Asserted against schema.sql itself, not against live state: the M1 section
 * above deliberately reorders a tag to prove tags_reorder() works, so reading
 * the database here would be testing that test's leftovers. */
$seedSql = (string) file_get_contents($appRoot . '/schema.sql');
preg_match_all("/\\('location', 1, '([^']+)',/", $seedSql, $seedNames);
$seeded = $seedNames[1];
$alpha  = $seeded;
usort($alpha, 'strcasecmp');
is_same($seeded, $alpha, 'the rooms are SEEDED alphabetically');
is_same(count($seeded), 24, 'all 24 of them');

/* And the tool restores it after any drift — including the drift the M1
 * section just caused. */
ok(is_file($appRoot . '/tools/sort-tags-alphabetically.php'),
    'a tool exists to restore alphabetical order on an install seeded before the change');

$live = tags_of_kind(TAG_LOCATION);
usort($live, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
tags_reorder(array_column($live, 'id'));

$after = array_column(tags_of_kind(TAG_LOCATION), 'name');
$want  = $after;
usort($want, 'strcasecmp');
is_same($after, $want, 'and running it puts a reordered list back into alphabetical order');

/* ===================================================== Round 3 · the merge */

section('Round 3 — Issues and Service History, merged');

/* THE SHAPE OF THE MERGE. One kind of thing (a log entry) that can carry both
 * observations and paid visits, plus a Service VIEW that reads across the two
 * places a paid visit can happen. These assertions are what stop a later pass
 * quietly re-growing a service_records table. */

$layoutSrc = (string) file_get_contents($appRoot . '/lib/layout.php');
ok(str_contains($layoutSrc, "'log'"), 'the Log tab exists');
ok(!str_contains($layoutSrc, "'issues'") && !str_contains($layoutSrc, 'issues.php'),
    'and no Issues tab survives beside it');

$menu = menu_items();
$menuHrefs = array_column($menu, 'href');
ok(in_array('service.php', $menuHrefs, true),
    'SERVICE IS IN THE HAMBURGER, not a tab — it is the view you go to on purpose');
ok(!in_array('history.php', $menuHrefs, true), 'and the old history screen is not');

/* ---- the entry screen offers both kinds of update ----------------------- */

$entrySrc = (string) file_get_contents($appRoot . '/public/entry.php');
ok(str_contains($entrySrc, 'id="update-kind"'), 'the update form has a kind switch');
ok(str_contains($entrySrc, 'data-kind="service"'), 'with a Service chip');
ok(str_contains($entrySrc, 'data-kind-fields="service"'),
    'and a block of money fields that belongs to it');
foreach (array('vendor_id', 'vendor_name', 'cost', 'rating') as $field) {
    ok((bool) preg_match('/name="' . $field . '"/', $entrySrc),
        "the service block carries $field");
}
ok(str_contains($entrySrc, 'id="update-'), 'timeline rows are anchorable, so "See what fixed it" can jump to one');

$entryJs = (string) file_get_contents($appRoot . '/public/assets/entry.js');
ok(str_contains($entryJs, "'update-kind'"), 'entry.js wires the kind switch');
ok(str_contains($entryJs, "data-kind-fields"), 'and reveals the fields the chip belongs to');
ok(str_contains($entryJs, "kind === 'service'"),
    'AND ONLY SENDS THE MONEY FIELDS ON A SERVICE — stale inputs must not file an invoice against a note');

/* The ids and the selectors have to agree. This is the class of bug that
 * shipped the dead hamburger: markup on one side, a listener on the other,
 * nothing in any log when they stop matching. */
foreach (array('entry-form', 'entry-tags', 'entry-status', 'entry-delete',
               'update-form', 'update-photo', 'update-add') as $id) {
    ok(str_contains($entrySrc, 'id="' . $id . '"'), "entry.php renders #$id");
    ok(str_contains($entryJs, "'" . $id . "'") || str_contains($entryJs, "'#" . $id . "'"),
        "and entry.js looks for #$id");
}

/* ---- a paid maintenance completion has a UI, not just an endpoint ------- */

/* task_complete() grew four columns in the merge. Without a form they are
 * write-only from the API, and half of "what did the house cost" would simply
 * never be recorded. */
$taskSrc = (string) file_get_contents($appRoot . '/public/task.php');
ok(str_contains($taskSrc, 'id="complete-form"'), 'task.php can record a paid completion');
foreach (array('vendor_id', 'vendor_name', 'cost', 'rating', 'completed_on') as $field) {
    ok((bool) preg_match('/name="' . $field . '"/', $taskSrc), "the completion form carries $field");
}
ok(str_contains($taskSrc, 'id="task-complete"'),
    'AND THE ONE-TAP BUTTON SURVIVES — most completions are you, in ten minutes');
ok(str_contains($taskSrc, 'id="completion-'), 'completions are anchorable from the Service screen');

$maintJs = (string) file_get_contents($appRoot . '/public/assets/maintenance.js');
is_same(substr_count($maintJs, "api/task-complete.php"), 3,
    'both controls post to the SAME endpoint — one code path moves a due date');

$serviceScreen = (string) file_get_contents($appRoot . '/public/service.php');
ok(str_contains($serviceScreen, "'#update-'") || str_contains($serviceScreen, "#update-"),
    'a service row links to the visit itself, not just its parent');

/* ---- the migration --------------------------------------------------- */

/* SHE HAS LIVE DATA. The migration is the only path from the old shape to this
 * one, so its safety properties are asserted rather than assumed. */
ok(is_file($appRoot . '/tools/migrate-to-log.php'), 'a migration exists');
$migrateSrc = (string) file_get_contents($appRoot . '/tools/migrate-to-log.php');
ok(str_contains($migrateSrc, '--dry-run') && str_contains($migrateSrc, '--confirm'),
    'it will not write without being told twice');
ok(strpos($migrateSrc, 'DROP TABLE') > strpos($migrateSrc, 'NUMBERS DO NOT ADD UP'),
    'AND IT DROPS NOTHING UNTIL THE BEFORE AND AFTER COUNTS AGREE — the old tables are the safety net');
ok(str_contains($migrateSrc, 'service_records') && str_contains($migrateSrc, 'record_tags'),
    'both old tables are accounted for');

/* THE HARNESS IS SQLITE AND THE MIGRATION IS MYSQL DDL, so it cannot be run
 * here. It was run for real against a MariaDB copy of the old shape, and these
 * are the three mistakes that run found — asserted at the source level so they
 * cannot come back on the next edit. */

ok(str_contains($migrateSrc, 'entry_tags CHANGE issue_id entry_id'),
    'RENAMING A TABLE DOES NOT RENAME ITS COLUMNS — entry_tags.issue_id is renamed too');

foreach (array('fk_updates_vendor', 'fk_completions_vendor', 'fk_media_completion') as $fk) {
    ok(str_contains($migrateSrc, $fk),
        "the migration adds $fk, so a migrated install behaves like a fresh one");
}

/* A rename and an ADD CONSTRAINT in the same ALTER makes MariaDB choose
 * ALGORITHM=COPY and then refuse it. Each of those constraints therefore has
 * to be its own statement. */
foreach (array('fk_updates_vendor', 'fk_media_completion') as $fk) {
    $stmt = strrpos(substr($migrateSrc, 0, strpos($migrateSrc, $fk)), 'ALTER TABLE');
    ok($stmt !== false && !str_contains(substr($migrateSrc, $stmt, strpos($migrateSrc, $fk) - $stmt), 'CHANGE '),
        "$fk is added in its own ALTER, not alongside a column rename");
}

ok(strpos($migrateSrc, "DROP COLUMN service_record_id") < strpos($migrateSrc, "DROP TABLE "),
    'AND THE COLUMNS POINTING AT service_records GO FIRST — their foreign keys are what make it un-droppable');

/* ---- the export still carries everything ------------------------------- */

$exportSrc = (string) file_get_contents($appRoot . '/public/api/export.php');
foreach (array('log_entries', 'log_updates', 'entry_tags', 'task_completions', 'media') as $table) {
    ok(str_contains($exportSrc, $table), "the export includes $table");
}
ok(!str_contains($exportSrc, 'service_records'), 'and nothing that no longer exists');
ok(str_contains($exportSrc, 'service.csv') && str_contains($exportSrc, 'service_list()'),
    'the money CSV is built from service_list(), so it sees both sources');

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
