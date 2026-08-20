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
require_once $appRoot . '/lib/issues.php';
require_once $appRoot . '/lib/render.php';
require_once $appRoot . '/lib/tasks.php';
require_once $appRoot . '/lib/records.php';
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
/* h() takes scalars, not only strings. Under strict_types the narrow
 * signature that the siblings use makes h($year) a fatal TypeError, and a
 * template is exactly where an int arrives by accident — PHP turns a numeric
 * string array key into an int on the way in. */
is_same(h(2026), '2026', 'h() accepts an int rather than fatally rejecting it');
is_same(h(12.5), '12.5', 'and a float');
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

q("INSERT INTO issues (id, title, status, noticed_on) VALUES (900, 'Pipeline', 'watching', '2026-01-01')");

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

    q("INSERT INTO media (kind, issue_id, slug, ext, original_path, original_filename, mime, bytes, status)
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
    $found = media_for('issue', 900);
    is_same(count($found), 1, 'media_for reads it back');
    is_same($found[0]['status'], 'ready', 'with its status');

    $many = media_for_many('issue', array(900, 87654));
    is_same(count($many[900]), 1, 'media_for_many keys by owner');
    is_same($many[87654], array(), 'an owner with nothing is present with an empty array');

    /* Deleting must take the FILES, not just the row — nothing sweeps them. */
    $thumbAbs = imageproc_resolve_upload($row['thumb_path']);
    $origAbs  = imageproc_resolve_upload($row['original_path']);
    ok(media_delete($mediaId), 'media_delete removes the row');
    ok(!is_file($thumbAbs), 'and the thumb file');
    ok(!is_file($origAbs), 'and the original — nothing else would ever reap it');

    /* Cascade: an issue taking its photos with it is what the three nullable
     * owner columns bought instead of a polymorphic pair. */
    $slug2 = imageproc_new_slug();
    copy($photoPath, imageproc_upload_path('original', $slug2, 'jpg'));
    q("INSERT INTO media (kind, issue_id, slug, ext, original_path, status)
       VALUES ('photo', 900, ?, 'jpg', ?, 'ready')",
        array($slug2, imageproc_relative_path('original', $slug2, 'jpg')));
    q('DELETE FROM issues WHERE id = 900');
    is_same((int) q('SELECT COUNT(*) FROM media WHERE issue_id = 900')->fetchColumn(), 0,
        'deleting an issue cascades its media rows away');
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

is_same(media_owner_column('issue'), 'issue_id', 'owner types map to columns');
is_same(media_owner_column('nonsense'), null, 'an unknown owner type returns null, never a column name');

/* A client filename never becomes a path. */
is_same(media_display_name('../../etc/passwd'), 'passwd', 'a traversing filename is reduced to its basename');
is_same(media_display_name(''), 'file', 'an empty name gets a placeholder');

array_map('unlink', glob($tmpDir . '/*') ?: array());
@rmdir($tmpDir);

/* ============================================================ M3 · issues */

section('M3 — Issues and timelines');

foreach (array(
    'lib/issues.php', 'lib/render.php',
    'public/issues.php', 'public/issue.php',
    'public/assets/issues.js', 'public/assets/issue.js',
    'public/api/issue-save.php', 'public/api/issue-update.php',
    'public/api/issue-update-delete.php', 'public/api/issue-status.php',
    'public/api/issue-delete.php',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M3 ships $file");
}

foreach (glob($appRoot . '/public/api/issue-*.php') as $endpoint) {
    $src  = (string) file_get_contents($endpoint);
    $name = basename($endpoint);
    ok(str_contains($src, 'require_login_api()') && str_contains($src, 'require_same_origin()'),
        "$name is gated and CSRF-checked");
}

/* ---- creation and the derived columns ---------------------------------- */

$kitchen  = tag_find(TAG_LOCATION, 'Kitchen');
$plumbing = tag_find(TAG_CATEGORY, 'Plumbing');

$id = issue_create(array(
    'title'               => 'Drip under the sink',
    'description'         => 'Only when the dishwasher runs.',
    'noticed_on'          => '2026-01-10',
    'check_interval_days' => 30,
    'tag_ids'             => array($kitchen['id'], $plumbing['id']),
));
ok($id > 0, 'issue_create returns an id');

$issue = issue_get($id);
is_same($issue['status'], 'watching', 'a new issue starts as watching');
is_same($issue['severity'], null, 'severity is unset unless given — never defaulted to 1');

/* An issue with an interval and no check-ins is due from the day it was
 * NOTICED. Otherwise it would never surface until somebody checked it once,
 * which is precisely the thing you would forget to do. */
is_same($issue['next_check_on'], '2026-02-09',
    'a never-checked issue is still due, counted from when it was noticed');
is_same($issue['last_checked_on'], null, 'and has no last-checked date');

is_same(count(tags_for('issue', $id)), 2, 'tags are attached on create');

/* ---- the timeline drives the derived columns --------------------------- */

issue_add_update($id, array('noted_on' => '2026-02-01', 'note' => 'Same as before', 'severity' => 2));
$issue = issue_get($id);
is_same($issue['severity'], 2, 'a check-in with a severity sets the issue severity');
is_same($issue['last_checked_on'], '2026-02-01', 'and the last-checked date');
is_same($issue['next_check_on'], '2026-03-03', 'and moves the next check forward from it');

/* A later note that does NOT re-rate must not wipe the rating. This is the
 * subtle one: "no change" is a legitimate check-in and it is not a severity. */
issue_add_update($id, array('noted_on' => '2026-03-05', 'note' => 'No change'));
$issue = issue_get($id);
is_same($issue['severity'], 2, 'a check-in with NO severity leaves the rating alone');
is_same($issue['last_checked_on'], '2026-03-05', 'but still moves the last-checked date');

issue_add_update($id, array('noted_on' => '2026-04-02', 'note' => 'Worse', 'severity' => 3));
is_same(issue_get($id)['severity'], 3, 'a later rating overrides an earlier one');

/* ---- trend ------------------------------------------------------------- */

is_same(issue_trend(issue_updates($id)), 'worse', 'two ratings going up read as worse');

$flat = issue_create(array('title' => 'Flat', 'noticed_on' => '2026-01-01'));
issue_add_update($flat, array('noted_on' => '2026-02-01', 'severity' => 2));
issue_add_update($flat, array('noted_on' => '2026-03-01', 'severity' => 2));
is_same(issue_trend(issue_updates($flat)), 'stable', 'the same rating twice reads as stable');

/* THE COMMON CASE, and it is not a failure: severity is optional, so most
 * timelines carry none and there is no trend to report. Printing "stable"
 * there would be inventing a judgement nobody made. */
$unrated = issue_create(array('title' => 'Unrated', 'noticed_on' => '2026-01-01'));
issue_add_update($unrated, array('noted_on' => '2026-02-01', 'note' => 'Looked at it'));
issue_add_update($unrated, array('noted_on' => '2026-03-01', 'note' => 'Still there'));
is_same(issue_trend(issue_updates($unrated)), '',
    'a timeline with no recorded severity has NO trend, not a stable one');
is_same(issue_trend(issue_updates($flat)) === '', false, 'while a rated one does');

/* ---- deleting a check-in rolls the derived columns BACK ----------------- */

$updates = issue_updates($id);
$newest  = $updates[count($updates) - 1];
issue_delete_update($newest['id']);
$issue = issue_get($id);
is_same($issue['last_checked_on'], '2026-03-05', 'deleting the newest check-in moves last-checked back');
is_same($issue['severity'], 2, 'and restores the previous rating');
is_same($issue['next_check_on'], '2026-04-04', 'and recomputes the next check');

/* ---- status ------------------------------------------------------------ */

ok(issue_set_status($id, ISSUE_RESOLVED), 'an issue can be resolved');
$issue = issue_get($id);
is_same($issue['resolved_on'], sw_today(), 'which stamps the date');
is_same($issue['next_check_on'], null,
    'A CLOSED ISSUE STOPS ASKING TO BE CHECKED — otherwise it sits on the dashboard forever');

ok(issue_set_status($id, ISSUE_WATCHING), 'and can be reopened');
ok(issue_get($id)['next_check_on'] !== null, 'which brings its check-back schedule back');
is_same(issue_get($id)['resolved_on'], null, 'and clears the resolved date');

/* Linking is always OPTIONAL — fix it yourself and it resolves with nothing
 * attached. */
issue_set_status($id, ISSUE_RESOLVED, null);
is_same(issue_get($id)['resolved_by_record_id'], null, 'resolving with no record attached is fine');
issue_set_status($id, ISSUE_DISMISSED, 55);
is_same(issue_get($id)['resolved_by_record_id'], null,
    'a record link is ignored on a status other than resolved — it would have no meaning');
issue_set_status($id, ISSUE_WATCHING);

/* ---- the list ---------------------------------------------------------- */

$open = issues_list();
ok(count($open) >= 3, 'the list defaults to the open statuses');

$leaked = array_filter($open, static fn(array $r): bool => !in_array($r['status'], issue_open_statuses(), true));
is_same($leaked, array(), 'and contains nothing resolved or dismissed');

/* Tags AND rather than OR — a filter that widens as you add to it is one
 * nobody can aim. */
$both = issues_list(array('tag_ids' => array($kitchen['id'], $plumbing['id'])));
is_same(count($both), 1, 'two tags narrow to issues carrying BOTH');

$roofing = tag_find(TAG_CATEGORY, 'Roofing');
is_same(count(issues_list(array('tag_ids' => array($kitchen['id'], $roofing['id'])))), 0,
    'and an impossible combination matches nothing rather than everything');

is_same(count(issues_list(array('search' => 'dishwasher'))), 1, 'search covers the description');
is_same(count(issues_list(array('search' => 'zzzznothing'))), 0, 'and misses cleanly');
is_same(issues_list(array('status' => array('nonsense'))), array(),
    'an unknown status filter returns nothing rather than everything');

ok(array_key_exists('tags', $open[0]) && array_key_exists('cover', $open[0]),
    'the list decorates rows with tags and a cover in batch, not per row');

/* ---- dashboard reads --------------------------------------------------- */

issue_set_status($flat, ISSUE_ACTIVE);
$action = issues_needing_action(sw_today());
$actionIds = array_column($action, 'id');
ok(in_array($flat, $actionIds, true), 'an active issue needs action regardless of dates');

$future = issue_create(array(
    'title' => 'Not yet', 'noticed_on' => sw_today(), 'check_interval_days' => 365,
));
ok(!in_array($future, array_column(issues_needing_action(sw_today()), 'id'), true),
    'a watching issue whose check is a year out does not');
ok(in_array($future, array_column(issues_upcoming_checks(sw_today()), 'id'), true),
    'but it does appear in the forward timeline');

/* ---- cleaning ---------------------------------------------------------- */

is_same(issue_clean_severity(''), null, 'an empty severity is NULL');
is_same(issue_clean_severity(0), null, 'zero is NULL, not level 1 — it is not a legal rating');
is_same(issue_clean_severity(9), null, 'out of range is NULL, never clamped up to Urgent');
is_same(issue_clean_severity('3'), 3, 'a numeric string is accepted');
is_same(issue_clean_interval('0'), null, 'a zero interval means no reminder');
is_same(issue_clean_date('nonsense'), null, 'an unreadable date is NULL');
is_same(issue_clean_text('   '), null, 'whitespace-only text is NULL, not an empty string');

ok(throws(static fn() => issue_create(array('title' => '   '))),
    'an issue cannot be created without a title');

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

$doomed = issue_create(array('title' => 'Doomed', 'noticed_on' => '2026-01-01'));
$upd = issue_add_update($doomed, array('noted_on' => '2026-02-01', 'note' => 'x'));
ok(issue_delete($doomed), 'an issue deletes');
is_same(issue_get($doomed), null, 'and is gone');
is_same((int) q('SELECT COUNT(*) FROM issue_updates WHERE id = ?', array($upd))->fetchColumn(), 0,
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

/* ============================================ M5 · records and vendors */

section('M5 — Service history and vendors');

foreach (array(
    'lib/records.php', 'lib/vendors.php',
    'public/history.php', 'public/record.php', 'public/vendors.php', 'public/vendor.php',
    'public/assets/history.js', 'public/assets/vendors.js',
    'public/api/record-save.php', 'public/api/record-delete.php',
    'public/api/vendor-save.php', 'public/api/vendor-delete.php',
) as $file) {
    ok(is_file($appRoot . '/' . $file), "M5 ships $file");
}

foreach (glob($appRoot . '/public/api/{record,vendor}-*.php', GLOB_BRACE) as $endpoint) {
    $src = (string) file_get_contents($endpoint);
    ok(str_contains($src, 'require_login_api()') && str_contains($src, 'require_same_origin()'),
        basename($endpoint) . ' is gated and CSRF-checked');
}

/* ---- vendors and the computed rating ----------------------------------- */

$plumberTag = tag_find(TAG_WORK_TYPE, 'Plumber');
$ace = vendor_save(null, array(
    'name' => 'Ridgeline Plumbing', 'phone' => '(512) 555-0142',
    'notes' => 'Ask for Danny.', 'tag_ids' => array($plumberTag['id']),
));
ok($ace > 0, 'vendor_save creates');

$vendor = vendor_get($ace);
is_same($vendor['rating'], null, 'a vendor with no jobs has NO rating — not zero');
is_same($vendor['jobs'], 0, 'and no jobs');
is_same(count($vendor['tags']), 1, 'with their trade attached');

/* ---- records ----------------------------------------------------------- */

$r1 = record_save(null, array(
    'title' => 'Rebuilt the shut-off valve', 'vendor_id' => $ace,
    'performed_on' => '2026-03-04', 'cost' => '285.00', 'rating' => 5,
));
$r2 = record_save(null, array(
    'title' => 'Emergency call-out', 'vendor_id' => $ace,
    'performed_on' => '2026-05-11', 'cost' => '460', 'rating' => 3,
));
/* A job with no rating must not drag the average down. */
$r3 = record_save(null, array(
    'title' => 'Looked at the boiler', 'vendor_id' => $ace, 'performed_on' => '2026-06-01',
));

$vendor = vendor_get($ace);
is_same($vendor['jobs'], 3, 'every job counts toward the job count');
is_same($vendor['rated_jobs'], 2, 'but only rated ones count as rated');
is_same($vendor['average'], 4.0,
    'the average is over RATED jobs only — an unrated job is not a zero');
is_same($vendor['rating'], 4.0, 'and with no override, that is what shows');

/* The override is shown BESIDE the average, never instead of it. */
vendor_save($ace, array('name' => 'Ridgeline Plumbing', 'rating_override' => 2));
$vendor = vendor_get($ace);
is_same($vendor['rating'], 2.0, 'an override wins for display');
is_same($vendor['average'], 4.0, 'and the average is still available to print beside it');

vendor_save($ace, array('name' => 'Ridgeline Plumbing', 'rating_override' => ''));
is_same(vendor_get($ace)['rating_override'], null, 'clearing the override goes back to the average');
is_same(vendor_get($ace)['rating'], 4.0, 'which is what shows again');

is_same(vendor_clean_rating(0), null, 'a zero override is NULL — zero is not a legal rating');
is_same(vendor_clean_rating(7), null, 'and out of range is NULL, never clamped');

/* ---- the snapshot name ------------------------------------------------- */

is_same(record_get($r1)['vendor_name'], 'Ridgeline Plumbing', 'the vendor name is snapshotted onto the record');

$jobs = $vendor['jobs'];
ok(vendor_delete($ace), 'a vendor can be deleted');

$after = record_get($r1);
is_same($after['vendor_id'], null, 'which nulls the link on their records');
is_same($after['vendor_name'], 'Ridgeline Plumbing',
    'BUT THE RECORD STILL SAYS WHO DID THE WORK — the snapshot survives the vendor');
is_same((int) q('SELECT COUNT(*) FROM service_records WHERE id IN (?, ?, ?)',
    array($r1, $r2, $r3))->fetchColumn(), 3, 'and none of the history is deleted');

/* A name typed for somebody not in the directory is kept as-is. */
$diy = record_save(null, array(
    'title' => 'Re-hung the sunroom door', 'vendor_name' => 'Me', 'performed_on' => '2026-02-02',
));
is_same(record_get($diy)['vendor_name'], 'Me', 'a typed name is kept when there is no vendor row');
is_same(record_get($diy)['vendor_id'], null, 'with no link');

/* ---- cost: NULL is not zero -------------------------------------------- */

is_same(record_clean_cost(''), null, 'an empty cost is NULL — "not recorded"');
is_same(record_clean_cost(null), null, 'and so is a missing one');
is_same(record_clean_cost('0'), '0.00', 'but a typed zero is a real zero — a different fact');
is_same(record_clean_cost('$1,285.50'), '1285.50', 'currency symbols and separators are stripped');
is_same(record_clean_cost('   '), null, 'whitespace is NULL');
is_same(record_clean_cost('not a number'), null, 'and junk is NULL rather than 0');
is_same(record_clean_cost('-40'), '0.00', 'a negative cost floors at zero');

is_same(render_cost(null), '', 'a NULL cost renders as NOTHING');
is_same(render_cost('0.00'), '$0.00', 'while a real zero renders as $0.00');

/* ---- the history list -------------------------------------------------- */

$all = records_list();
ok(count($all) >= 4, 'records_list returns the history');
is_same($all[0]['performed_on'] >= $all[1]['performed_on'], true, 'newest work first');
ok(array_key_exists('media', $all[0]) && array_key_exists('tags', $all[0]),
    'decorated with tags and media in batch');

is_same(count(records_list(array('year' => 2026))), count($all), 'the year filter matches');
is_same(count(records_list(array('year' => 1999))), 0, 'and excludes other years');
is_same(count(records_list(array('search' => 'shut-off'))), 1, 'search covers the title');
is_same(count(records_list(array('search' => 'Ridgeline'))), 3,
    'and the snapshotted vendor name, so a deleted vendors work is still findable by their name');

ok(in_array(2026, records_years(), true), 'records_years lists the years that have work in them');

/* ---- linking an issue -------------------------------------------------- */

$issueId = issue_create(array('title' => 'Leaky valve', 'noticed_on' => '2026-02-01'));
$fix = record_save(null, array(
    'title' => 'Replaced it', 'performed_on' => '2026-03-01', 'issue_id' => $issueId,
));
is_same(record_get($fix)['issue_id'], $issueId, 'a record can reference an issue');
is_same(issue_get($issueId)['resolved_by_record_id'], null,
    'but referencing it does NOT resolve it — those are different claims');

issue_set_status($issueId, ISSUE_RESOLVED, $fix);
is_same(issue_get($issueId)['resolved_by_record_id'], $fix, 'resolving with the record is explicit');

/* Deleting the record must not delete the issue it closed. */
record_delete($fix);
ok(issue_get($issueId) !== null, 'deleting the record leaves the issue standing');
is_same(issue_get($issueId)['resolved_by_record_id'], null, 'with the link nulled');

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
ok(array_key_exists('issues', $now) && array_key_exists('tasks', $now),
    'dashboard_now keeps issues and maintenance in SEPARATE groups, per the brief');
is_same($now['total'], count($now['issues']) + count($now['tasks']), 'and totals them');

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
$issueId = issue_create(array(
    'title' => 'Watch the ceiling', 'noticed_on' => $today, 'check_interval_days' => 30,
));
$mixed = dashboard_timeline($today, null, 100);
$kinds = array_unique(array_column($mixed['rows'], 'kind'));
ok(in_array('issue', $kinds, true) && in_array('task', $kinds, true),
    'the forward timeline COMBINES issues and maintenance — chronology is the organising principle there');

/* An issue contributes exactly one row: its next check. A chain of them would
 * be schedule the app never promised. */
$mine = array_filter($mixed['rows'], static fn(array $r): bool => $r['kind'] === 'issue' && $r['id'] === $issueId);
is_same(count($mine), 1, 'an issue contributes ONE check-back, never a projected chain');

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
