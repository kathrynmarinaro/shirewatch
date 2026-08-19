# Interface Contracts

The module passes are built against these contracts. **Nothing here changes
without updating this file first** — the next pass is written against it. If you
need a change in someone else's territory, note it in your report rather than
editing across the line.

Foundation is done. Everything in §2–§6 already exists, is linted, and is
exercised by `php tools/run-tests.php` (104 assertions, green). You should not
need to write a line of CSS, a date calculation, or a query against `tags` or
`media`.

---

## 0. Ground rules for every module

- **No build step.** The deployed app is plain files uploaded by FTP to
  Hostinger. ES modules (`<script type="module">`), no bundler, no npm in
  `public/`.
- **PHP 8.4**, `declare(strict_types=1)`, PDO with prepared statements only.
  Never interpolate a variable into SQL — always `q($sql, $params)`.
- `array()` long syntax, matching the sibling apps.
- **Mobile-first.** Minimum tap target `--tap` (48px). Inputs are 16px or iOS
  zooms on focus and never zooms back.
- **Use the design system.** `public/assets/styles.css` is Foundation-owned and
  complete (§5). **Do not add a `<style>` block and do not edit `styles.css`** —
  if you genuinely need a new component, report it.
- **Use the shared JS modules** (§6). Import them; don't fork them.
- **Fail soft.** An issue with an unknown tag renders without it. A photo still
  in the queue renders as a placeholder that says so. A failed write shows a
  snackbar. One row degrades, never a screen.
- **Comment the "why", not the "what."** Match the density in `lib/auth.php` and
  `schema.sql`.
- Every entry point starts with `require_once __DIR__ . '/../lib/bootstrap.php';`
- Escape on output with `h()`. `<?= h($x) ?>`, never a bare echo of DB data.
- **Never call `date()` or `time()` for "today".** Call `sw_today()` once at the
  top of a request and pass it down. §4 explains why this is not a style rule.

---

## 1. File ownership

| Owner | Files |
|---|---|
| **Foundation** (done) | `schema.sql`, `config.example.php`, `.htaccess` ×4, `.gitignore`, `lib/bootstrap.php`, `lib/db.php`, `lib/auth.php`, `lib/dates.php`, `lib/layout.php`, `lib/tags.php`, `lib/media.php`, `lib/imageproc.php`, `lib/mailer.php`, `lib/vendor/`, `public/login.php`, `public/logout.php`, `public/assets/styles.css`, `public/assets/{api,swipe,inline-edit,reorder,menu,tagfield}.js`, `data/starter-tasks.php`, `tools/{test-harness,run-tests,make-hash,build-deploy,seed,install-starter-tasks,hosting-check,send-test-email}.php`, `docs/CONTRACTS.md`, `CLAUDE.md` |
| **M1 · Tags** *(done)* | `public/tags.php`, `public/api/tag-*.php`, `public/assets/tags.js`, `public/assets/tagfield.js` |
| **M2 · Media** *(done)* | `public/api/upload.php`, `public/api/worker.php`, `public/api/media-*.php`, `cron/process-queue.php`, `public/assets/upload.js`, `public/assets/lightbox.js` |
| **M3 · Issues** *(done)* | `public/issues.php`, `public/issue.php`, `lib/issues.php`, `lib/render.php`, `public/api/issue-*.php`, `public/assets/{issues,issue}.js` |
| **M4 · Maintenance** | `public/maintenance.php`, `public/task.php`, `lib/tasks.php`, `public/api/task-*.php`, `public/assets/maintenance.js` |
| **M5 · Service & vendors** | `public/history.php`, `public/record.php`, `public/vendors.php`, `public/vendor.php`, `lib/records.php`, `lib/vendors.php`, `public/api/record-*.php`, `public/api/vendor-*.php`, `public/assets/{history,vendors}.js` |
| **M6 · Dashboard & reminders** | `public/index.php`, `public/cron.php`, `lib/dashboard.php`, `tools/cron-reminders.php`, `public/api/timeline.php`, `public/assets/dashboard.js` |
| **M7 · Integration** | `DEPLOY.txt`, `README.md`, `public/api/export.php`, `public/component-test.html` |

Add your tests to `tools/run-tests.php` in a new `section()`. **Don't rewrite
the existing sections** — they are the regression net for the schema, the tag
layer and the recurrence engine, which everything else stands on.

---

## 2. The database

`schema.sql` is the contract — read it, it's commented. Fifteen tables:

`properties` · `tags` · `issue_tags` · `task_tags` · `record_tags` ·
`vendor_tags` · `issues` · `issue_updates` · `maintenance_tasks` ·
`task_completions` · `task_reminder_sends` · `vendors` · `service_records` ·
`media` · `login_attempts`

### Nine things that will bite you if you miss them

1. **Every scheduling date is a stored `DATE` column, computed in PHP.**
   `maintenance_tasks.next_due_on` and `issues.next_check_on`. There is no
   `DATE_ADD`, no `INTERVAL` and no `NOW()` in any query against them. This
   keeps the due queries sargable, keeps them identical on MySQL and on the
   SQLite the tests run against, and keeps one clock in one place. **A query
   like `WHERE last_checked_on + INTERVAL check_interval_days DAY <= ?` will be
   rejected in review** — the answer is already in a column.

2. **`next_due_on` moves in exactly one place: when a task is completed.** It
   does **not** move when a reminder is sent. `task_reminder_sends` is what
   stops the duplicate email. So an overdue task emails once, then sits on the
   dashboard getting louder. This is the app not forgiving you, and it is
   deliberate.

3. **Skipping a seasonal task does not roll it forward.** Miss March's gutters
   and the task stays at March saying "5 months overdue" rather than quietly
   becoming October. That is the true statement. Two overdue gutter rows would
   not be.

4. **Renaming a tag renames it everywhere, by foreign key.** `UPDATE tags SET
   name = ?` is the whole rename. Do not add a denormalized tag-name column
   anywhere.

5. **`service_records.vendor_name` is a snapshot string and is the deliberate
   exception to (4).** Written on insert, never kept in sync. Deleting a vendor
   nulls `vendor_id` and the record still says who did the work.

6. **`NULL` means unset, everywhere, and renders as nothing.**
   `issues.severity`, `service_records.rating`, `service_records.cost`,
   `vendors.rating_override`. Zero is never a legal value for any of them.
   A `NULL` cost is "not recorded", which is not the same as free.

7. **`media` has three owner columns and exactly one is set.** `issue_id`,
   `issue_update_id`, `service_record_id`. Never write two.

8. **Tag names are stored exactly as typed.** No title-casing, no trimming
   beyond whitespace, no normalized lookup key. `Ext Studio` stays `Ext Studio`.

9. **`issues.resolved_by_record_id` and `service_records.issue_id` point
   opposite ways and are both correct.** The first is "this record fixed it";
   the second is "this work relates to it". Three visits, one fix.

---

## 3. Shared helpers you already have

| Helper | From | Does |
|---|---|---|
| `cfg('db.host', $default)` | `bootstrap.php` | dot-notation config read |
| `app_name()` | `bootstrap.php` | the app's name, from config. **Never hardcode "Shirewatch"** |
| `h($s)` | `bootstrap.php` | `htmlspecialchars` shorthand; `null` → `''` |
| `asset('assets/x.js')` | `bootstrap.php` | mtime cache-busted URL, already escaped |
| `json_out()` / `json_error()` / `json_body()` / `require_method()` | `bootstrap.php` | JSON endpoint plumbing |
| `fatal_error($code, $human)` | `bootstrap.php` | JSON under `/api/`, HTML elsewhere, STDERR on CLI |
| `db()` / `q($sql, $params)` | `db.php` | PDO handle / bound query |
| `require_login_page()` | `auth.php` | **the gate for every HTML screen.** Redirects to `login.php?next=…`, emits `noindex()` itself |
| `require_login_api()` | `auth.php` | the gate for every JSON endpoint; 401 `unauthorized` |
| `require_same_origin()` | `auth.php` | CSRF check on every mutating **JSON** endpoint |
| `csrf_field()` / `require_csrf_form()` | `auth.php` | CSRF for an ordinary `<form>` post — see below |
| `page_head()` / `screen_head()` / `page_foot()` / `page_menu()` | `layout.php` | page chrome, tab bar, hamburger |
| `nav_tabs()` / `menu_items()` | `layout.php` | the four tabs; the sheet's contents |
| `harness_pdo()` | `tools/test-harness.php` | in-memory SQLite from `schema.sql`. **CLI/tests only** |

Every screen is:

```php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_login_page();
page_head('Issues', 'issues');
screen_head('Issues', page_menu());
// markup
page_foot('issues');
```

Every JSON endpoint is:

```php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_login_api();
require_same_origin();
require_method('POST');
```

Mutating `fetch()` calls **must** send `X-Requested-With: Shirewatch` or
`require_same_origin()` 403s them. `assets/api.js` does this for you.

**A `<form>` post cannot set a header, so it needs a token instead.** Any
screen that posts to itself (for a no-JS path, or because a form is simply the
right control) must:

```php
require_csrf_form();          // at the top, before acting on $_POST
…
<form method="post"><?= csrf_field() ?> …</form>
```

`require_csrf_form()` is a no-op on GET and fails open when the gate is
unconfigured, matching `require_login_page()`. `csrf_token()` needs a session,
and **the gate does not start one when it fails open** — so call
`auth_start_session()` yourself on a screen that touches `$_SESSION`.

`auth_start_session()` is a no-op on CLI. `$_SESSION` stays a plain array there,
so session-touching code is testable and a cron run emits no warnings.

---

## 4. Dates and recurrence — `lib/dates.php`

**Not one function in this app asks what day it is except `sw_today()`.**
Everything else takes `$today` as a parameter, from a single call at the top of
a request or a cron run.

This is not a style preference. "Due tomorrow", "34 days ago" and "check this
again in April" are what the screen *says*, so a one-day skew is a lie printed
on a lock screen — and taking `$today` as a parameter is the only reason any of
it can be tested.

| Function | Does |
|---|---|
| `sw_today()` | `Y-m-d`. **The only clock.** |
| `days_since($date, $today)` | integer, negative for the future, `null` for junk |
| `fmt_relative_due($due, $today)` | `Today` · `Tomorrow` · `Friday` · `34 days ago` · `October 1` |
| `recur_next_after($task, $after)` | **the next occurrence.** `null` for a broken rule |
| `recur_describe($task)` | `Every 3 months` · `March and October` · `''` |
| `recur_parse_months($raw)` | `"10,3,3"` → `[3, 10]` |

### `recur_next_after()` is the only thing allowed to compute a due date

`task_complete()` calls it, the task editor calls it, the starter-task installer
calls it, and the dashboard's forward projection calls it to draw dates two
years out. **Do not write a second implementation for the projection.** One that
drifts from the real one will eventually show a date the app would never
produce, and nobody finds out until the day it does not arrive.

It takes the date to advance *from* — the completion date when
`interval_from = 'completion'`, otherwise the old due date. It never looks at
the clock itself.

Two behaviours worth knowing:

- **Month arithmetic clamps, it does not overflow.** Jan 31 + 1 month is Feb 28
  (or Feb 29), not March 3. PHP's own `modify('+1 month')` gives you March 3, and
  a task set up on the 31st drifts a few days later every short month until it
  lands in the wrong month entirely.
- **A corrupt rule returns `null`, never a default.** A task with no usable
  recurrence shows as having no schedule, which is visible. Defaulting would
  quietly acquire January.

---

## 5. Component vocabulary — `public/assets/styles.css`

Foundation-owned and complete. Write markup against these; don't edit the file.

### Ported from the siblings

| Class | Use |
|---|---|
| `.wrap` | the centred column; clears the tab bar and the home indicator |
| `.screen-head` + `h1`, `.head-actions` | title with the accent rule; right-hand slot for `page_menu()` |
| `.tabbar` | rendered by `page_foot()`; you never write this |
| `.card`, `.pill`, `.empty`, `.hint`, `.muted`, `.sr-only`, `.hidden` | the usual |
| `.field` + `<span>` + input, `.input`, `.field-err` | forms |
| `.btn-primary` / `.btn-secondary` / `.btn-ghost` / `.btn-danger` | buttons; work on `<a>` too |
| `.link-btn` / `.tap-text` / `.tap-text.danger` | underlined text actions, 48px tall |
| `.icon-btn` (+ `.is-danger`) | 48×48 square; put an inline `<svg viewBox="0 0 24 24">` in it |
| `.sheet` / `.sheet-panel` / `.sheet-cancel` | bottom sheet on a phone, centred dialog above 620px. **This is what the tag picker uses.** |
| `.list` / `.list-row` / `.row-slide` / `.row-text` / `.row-sub` / `.row-body` | the swipeable row primitive. `data-id` on the `<li>` is **required** |
| `.accordion` / `.accordion-head` / `.accordion-body` / `.accordion-count` | `<details>`, not a div plus a click handler |
| `.cat-group` / `.cat-head` / `.cat-count` | a grouped section |
| `.snackbar` + friends | **created and removed by `swipe.js`.** Don't hand-write one |
| `.login-*` | login only |

### New in this app

| Class | Use |
|---|---|
| `.sev` + `.sev-1` … `.sev-4` | severity pill. **There is no `.sev-0`** — `NULL` renders nothing |
| `.is-overdue` | a date that has passed |
| `.stars` / `.star` / `.star.is-on` / `.star.is-half` / `.stars-num` / `.stars-note` | ratings. Half stars are for a vendor *average* only, never a per-job rating |
| `.chips` / `.chip` / `.chip.is-on` / `.chip.is-location` / `.chip.is-static` / `.chip-x` | tag chips, read-only and filtering |
| `.tagfield` / `.tagfield-add` / `.sheet-group` | the tag picker |
| `.timeline` / `.timeline-head` / `.timeline-item` / `.timeline-title` / `.timeline-sub` / `.timeline-more` | the dashboard's forward stream |
| `.timeline-item.is-issue` | hollow node — an issue follow-up rather than a task |
| `.timeline-item.is-projected` | dimmed — a **calculated** future occurrence, not a stored row |
| `.action-count` / `.action-count.is-clear` / `.action-group` | the "needs action now" accordion |
| `.gallery` / `.gallery.is-strip` / `.gallery-item` / `.gallery-date` | photo grids. `.is-pending` / `.is-failed` on an item render their own label |
| `.doclist` / `.doc` / `.doc-name` / `.doc-size` | invoices and warranties |
| `.meta` (a `<dl>`) | label/value pairs on a detail screen |
| `.filterbar` | horizontally scrolling chip row under a heading |
| `.toast` / `.toast.is-visible` | transient statement. **Not** `.snackbar` — that one carries an Undo |

---

## 6. Shared JS modules

Hand-written ES modules, no dependencies. All **generic**: they take a root
element or selector plus callbacks and know nothing about any screen. All
**delegate** their listeners to the root, so a list you re-render needs no
re-attach. All **return a detach function** and **degrade cleanly**.

`api.js` · `swipe.js` · `inline-edit.js` · `reorder.js` · `menu.js` ·
`tagfield.js`

```js
import { apiGet, apiPost, apiTry, ApiError } from './api.js';
```

- Sends `X-Requested-With: Shirewatch` on every non-GET.
- Throws `ApiError` on any non-2xx, carrying the server's `{"error": code}`.
  **Branch on `err.code`**, never on the message. Codes: `unauthorized` (401),
  `csrf_check_failed` (403), `method_not_allowed` (405), `invalid_json` (400),
  `network_unreachable` (synthesised client-side, status 0).
- `apiTry()` never rejects — `{ok: true, data}` or `{ok: false, error}`.

**Adding a shared module means adding it to `SHARED_MODULES` in
`lib/layout.php`.** That constant drives the import map that cache-busts them.
An ES module imported by a literal specifier inside another `.js` file has no
version on its URL, so the browser serves whatever copy it already has —
forever. A test asserts every name in that list is a real file; nothing can
assert that a module you forgot to add is stale.

---

## 7. Media — `lib/media.php`

**Nothing outside `lib/media.php` writes to `media` or into
`public/uploads/`.** M2 builds the endpoints; the storage, validation and queue
are already written.

| Function | Does |
|---|---|
| `media_store($file, $ownerType, $ownerId)` | validate, save, insert. Throws with a machine-readable reason |
| `media_normalize_files($_FILES['files'])` | flattens PHP's transposed array; tolerates a single upload |
| `media_reject_reason($file)` | `too_large` · `empty_file` · `upload_failed` · … or `null` |
| `media_display_name($raw)` | a client filename made safe to print |
| `media_process_next()` | claim and derive one; `null` when drained |
| `media_pending_count()` | for the progress pill |
| `media_for($ownerType, $id)` / `media_for_many($ownerType, $ids)` | reads. **Use the batched one on list screens** |
| `media_delete($id)` | row and files |

`$ownerType` is `'issue'`, `'issue_update'` or `'record'`. Anything else returns
empty rather than throwing.

**The upload response shape**, which M3 and M5 both consume:

```json
{ "created":  [{ "id": 91, "name": "IMG_4021.HEIC" }],
  "rejected": [{ "name": "notes.txt", "reason": "unsupported_type" }] }
```

A batch **never fails as a whole** because one file in it was wrong.

### The endpoints M3 and M5 call

| Endpoint | Takes | Notes |
|---|---|---|
| `POST api/upload.php` | multipart: `files[]`, `owner_type`, `owner_id` | **The owner row must already exist** — save the issue/record first, then attach photos. Returns `owner_not_found` otherwise |
| `POST api/worker.php` | — | drains `cfg('media.batch')` items. `{processed, remaining, results}` |
| `POST api/media-delete.php` | `{id}` | row and files. No undo |
| `POST api/media-caption.php` | `{id, caption}` | empty string stores `NULL` |
| `POST api/media-reorder.php` | `{ids: [...]}` | new top-to-bottom order |

### Wiring the browser side

```js
import { attachUpload }   from './upload.js';
import { attachLightbox } from './lightbox.js';

attachUpload('#add-photo', {
  ownerType: 'issue_update', ownerId: 41,
  capture: 'single',                       // or 'batch'
  onDone: (created) => refreshGallery(),
  onProgress: (text) => setPill(text),     // null when finished
});

attachLightbox('#photos');                 // reads the gallery's own DOM
```

`attachUpload` handles the drain loop itself. **`onProgress` is called with a
string while working and `null` when done** — render it into `.queue-pill`.

`attachLightbox` needs no data: it reads `href`, `data-original`,
`data-caption` and `data-date` off each gallery link. So render the gallery
items as real `<a>` elements pointing at the detail image, and the viewer is a
progressive enhancement rather than the only route to the photo.

**`upload.js` does not go through `api.js`** — `FormData` needs the browser to
set `Content-Type` so it can include the multipart boundary. It therefore sends
the `X-Requested-With` header itself, and a test asserts both files agree on
the value.

Three things the queue guarantees:

- **Upload does no image work.** It saves the original, writes a `pending` row
  and returns. A ten-file batch would otherwise be 10–20s of Imagick and a real
  risk of hitting `max_execution_time`.
- **Claiming is a single conditional `UPDATE` whose `rowCount()` decides the
  winner.** The browser's drain loop and the cron sweep race by design. **Never
  split it into a `SELECT` then an `UPDATE`.**
- **Originals are kept forever.** Do not add a reaping sweep. These photos are
  evidence for an insurance claim or a contractor dispute, not visual
  references.

---

## 8. Tags — `lib/tags.php`

**Nothing outside `lib/tags.php` writes SQL against `tags` or the four join
tables.**

| Function | Does |
|---|---|
| `tags_of_kind($kind)` | every tag of one kind, in sort order |
| `tags_for($type, $id)` / `tags_for_many($type, $ids)` | reads. **Use the batched one on list screens** |
| `tags_set($type, $id, $tagIds)` | replace an item's tags; returns what was really stored |
| `tag_add($kind, $name)` | idempotent on the name |
| `tag_find($kind, $name)` / `tag_by_id($id)` | lookups |
| `tag_rename($id, $name)` | one `UPDATE`; every tagged item follows. `false` on a collision |
| `tag_delete($id)` | cascades off every item |
| `tags_reorder($orderedIds)` | spaced by tens |
| `tag_usage($id)` | `{issue, task, record, vendor}` counts, for a delete confirmation |
| `tag_names($tags, $kind?)` | just the strings |

`$type` is `'issue'`, `'task'`, `'record'` or `'vendor'`. `$kind` is
`TAG_LOCATION`, `TAG_CATEGORY` or `TAG_WORK_TYPE`.

### The picker — `assets/tagfield.js`

**Render a real `<select multiple>` and let the module drive it.** The select
is the form control; the chips are a rendering of its options. So the form
posts correctly with JS off, and there is no parallel state to keep in sync.

```html
<div class="tagfield" id="issue-tags">
  <select multiple name="tags[]" class="sr-only">
    <option value="3" data-kind="location" selected>Kitchen</option>
    <option value="9" data-kind="category">Plumbing</option>
  </select>
</div>
```

```js
const field = attachTagField('#issue-tags', { onChange: (ids) => {} });
field.value();      // [3]
field.set([3, 9]);
field.detach();
```

`data-kind` on each option is what groups the sheet. The picker **stays open**
across taps — picking three rooms is the normal case.

**New tags are not created from the picker.** That is the Rooms & Tags screen's
job, deliberately: a tag created in passing while filling in a form is how you
end up with both `Kitchen ` and `kitchen`.

`tags_set()` **silently drops ids that don't exist** rather than rejecting the
save. A picker showing a tag someone deleted in another tab should cost you that
one chip, not the whole form.

---

## 8b. Issues — `lib/issues.php`, and `lib/render.php`

**Nothing outside `lib/issues.php` writes SQL against `issues` or
`issue_updates`.** The dashboard reads through `issues_needing_action()` and
`issues_upcoming_checks()` so there is one definition of "needs attention".

| Function | Does |
|---|---|
| `issues_list($filters)` | `status`, `tag_ids`, `search`. Rows come back decorated with `tags` and `cover` |
| `issue_get($id)` / `issue_updates($id)` | one issue / its timeline, **oldest first** |
| `issue_create($data)` / `issue_save($id, $data)` | header fields + `tag_ids` |
| `issue_add_update($id, $data)` | one check-in. Returns the id photos attach to |
| `issue_delete_update($id)` / `issue_delete($id)` | remove, files included |
| `issue_set_status($id, $status, $recordId?)` | the record link is **always optional** |
| `issue_trend($updates)` | `worse` · `better` · `stable` · `''` |
| `issues_needing_action($today)` / `issues_upcoming_checks($after)` | the dashboard's two reads |

**`issue_recompute()` is the only writer of `severity`, `last_checked_on` and
`next_check_on`.** Never set them yourself — every path that can change them
already calls it. Three behaviours that follow, and that a later module must
not break:

- **A check-in with no severity does not wipe the rating.** "No change" is a
  legitimate entry and is not a severity.
- **A closed issue has `next_check_on = NULL`**, or a resolved crack sits on
  the dashboard forever.
- **An issue with an interval and no check-ins is due from `noticed_on`**, so
  the one you logged and forgot still surfaces.

**Tags AND rather than OR** in `issues_list()`. Kitchen + Plumbing means the
kitchen plumbing problem, not everything in the kitchen plus everything
plumbing.

### `lib/render.php` — shared output

`render_gallery($photos, ['strip'=>bool, 'id'=>string, 'date'=>string])` ·
`render_photo()` · `render_documents($media)` · `render_severity(?int)` ·
`render_stars(?float, ['number'=>bool])` · `render_tags($tags)` ·
`render_cost(?string)`

Every one **escapes its own output and returns a string**. Every one returns
`''` for the unset case — that is how `NULL means nothing` is enforced in one
place rather than in each template.

Gallery tiles are real `<a>` elements to the detail image, so the photo is
reachable with no JS; `lightbox.js` upgrades them.

---

## 9. Email — `lib/mailer.php`

One function is the contract:

```php
send_task_reminder(array $tasks, string $today): bool
```

Nothing outside that file knows PHPMailer exists. An empty `$tasks` sends
nothing and returns `true` — "there was nothing due" is not a failure.

**One digest per run, not one email per task.** The send ledger is still
per-task, so a task already emailed about is simply absent from the next digest.

`mailer_last_error()` carries the reason after a `false`, for the ledger row.
Read it immediately; the next send overwrites it.

**M6 must claim before it sends:**

```php
// INSERT ... ON DUPLICATE KEY UPDATE attempts = attempts + 1,
// then refuse if sent_at is already set.
```

`(task_id, due_on)` is the primary key of `task_reminder_sends`, so the database
makes a second row impossible. A *failed* attempt leaves `sent_at` NULL so
tomorrow retries — a hung SMTP connection must not cost you the reminder.

---

## 10. The dashboard's forward timeline (M6)

Two sources, both stored `DATE` columns, so this is a union of two
identically-shaped index-backed range scans:

```sql
SELECT next_due_on AS on_date, 'task' AS kind, id, title
  FROM maintenance_tasks WHERE is_active = 1 AND next_due_on > ?
UNION ALL
SELECT next_check_on, 'issue', id, title
  FROM issues WHERE status IN ('watching','active') AND next_check_on > ?
ORDER BY on_date, kind, id
```

**Page by keyset, never `OFFSET`.** The cursor is the last row's
`(on_date, kind, id)`. With `OFFSET`, inserting a row mid-scroll silently
repeats or skips one — and on this screen the row being inserted is usually the
one you just completed.

**Recurring tasks are projected forward** over `cfg('dashboard.horizon_months')`
using `recur_next_after()`, so the stream doesn't run dry after a month.
Projected rows get `.is-projected` and are **not** stored.

**Issue check-backs show only the next one.** A task genuinely recurs forever;
an issue's next look-at depends on what you see when you look, so a chain of
them would be invented schedule.
