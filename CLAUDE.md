# Shirewatch — working notes

**Read `docs/CONTRACTS.md` before writing any code.** It holds the
file-ownership table that keeps the module passes from colliding, the full
catalogue of CSS classes and JS module APIs the Foundation layer already built,
and the ten schema facts that will bite you.
`docs/DELEGATION-PLAN.md` has the reasoning behind every decision, if you need
to know *why* rather than *what*.

A house maintenance app: log a problem, photograph it, and watch over time
whether it is stable or getting worse — including the visit that fixed it.
Plus the scheduled maintenance you keep meaning to do, the record of what you
paid whom, and who to call.

One property, one person, one password — **architected so both of those can
grow without a rebuild**, because this app is a candidate for public release.

## Stack, non-negotiable

- PHP 8.4, `declare(strict_types=1)` at the top of every file.
- MySQL/MariaDB via PDO. **Prepared statements only** — never interpolate into
  SQL. Use `q($sql, $params)` from `lib/db.php`.
- **No build step, ever.** Deployment is an FTP upload of plain files to
  Hostinger. No npm, no bundler, no transpiler, no CSS preprocessor. Browser JS
  is hand-written ES modules.
- Mobile-first. The phone is the only device that matters. 48px minimum tap
  target (`--tap`), 16px minimum font on inputs — below 16px iOS zooms on focus
  and never zooms back.
- `array()` long syntax, matching the sibling apps.

PHPMailer is vendored as three plain files in `lib/vendor/PHPMailer/`. That is
not a violation of the no-build-step rule — the rule is about not needing a
toolchain, not about never using anyone else's code. **Keep those three files
byte-identical to upstream.** Don't restyle them to match the house rules and
don't "fix" them.

## House style

- Comments explain **why**, not what. Look at `lib/auth.php` and `schema.sql`
  for the register: they document the decisions a future reader can't
  reconstruct, not the syntax they can already see.
- Escape on output with `h()`. Templates use `<?= h($x) ?>`, never bare echo of
  DB data.
- Entry points start with `require_once __DIR__ . '/../lib/bootstrap.php';`
- **Never hardcode the string "Shirewatch".** Call `app_name()`. The name is a
  config value so it can be changed in one line for a release.
- Fail soft: an unknown tag, a photo still in the queue, a malformed row —
  each degrades that one row. It never 500s a screen.

**`public/assets/styles.css` is Foundation-owned and complete.** Write markup
against the existing classes (catalogued in `docs/CONTRACTS.md` §5). Don't add
`<style>` blocks, don't add inline `style=` for anything structural, don't edit
the stylesheet. Need a new component? Report it instead.

The same goes for `assets/api.js`, `swipe.js`, `inline-edit.js`, `reorder.js`,
`menu.js` and `tagfield.js` — import them, don't fork them. They are generic on
purpose and they take callbacks.

**Adding a shared JS module means adding it to `SHARED_MODULES` in
`lib/layout.php`.** That constant drives the import map that cache-busts them.
Skip it and the new module is served from cache forever, because the specifier
inside the importing `.js` file is a literal no PHP ever touches. This class of
bug shipped in a sibling app: a button appeared, wired to a cached module that
had never heard of it, with nothing in any log.

## Shared with the other apps

`lib/bootstrap.php`, `lib/db.php`, `lib/auth.php`, `lib/dates.php`,
`lib/layout.php` and `lib/mailer.php` are ports from the Personal CRM, which
ports most of them from Grocery, Book Tracker and the Workout Generator.
`lib/imageproc.php` is a port from the Inspiration Gallery. The design tokens in
`public/assets/styles.css` are the house system.

**If you fix a bug in any of these, it should land in the siblings too — say so
in your report.**

Deliberate local divergences, all documented at their site:

- `db()` has a **CLI-only PDO override** so `tools/test-harness.php` can point
  it at an in-memory SQLite database. Without it nothing that calls `q()` is
  testable, because there is no MySQL in the build environment.
- The design tokens are named `--accent` / `--accent-dark` / `--accent-tint`
  rather than `--teal`. The values are still the house teal.
- **`lib/media.php` keeps originals; the Gallery's `queue.php` reaps them.**
  See below.

## Things that look like bugs but are decisions

- **A task's due date does not move when its reminder is sent. It moves when
  you complete the task.** `task_reminder_sends` — keyed `(task_id, due_on)` —
  is what stops the same email going out eight times. So an overdue task emails
  once and then sits on the dashboard getting louder. Advancing it on send
  would mean the app quietly forgives you for not flushing the water heater,
  which is the one thing it exists not to do. Ported from the Personal CRM,
  where the same rule applies to not calling your sister.

- **Skipping a seasonal task does not roll it to the next season.** Miss
  March's gutters and the task stays at March saying "5 months overdue" rather
  than silently becoming October. That is the true statement about the world.
  Two overdue gutter rows would not be, and a silent roll-forward means you
  skipped a season without ever being told.

- **Month arithmetic clamps rather than overflowing.** Jan 31 plus one month is
  Feb 28, not March 3. PHP's own `modify('+1 month')` gives you March 3, and a
  task set up on the 31st then drifts a few days later every short month until
  it lands in the wrong month entirely — which nobody would trace back to the
  date library.

- **`recur_next_after()` is the only function allowed to compute a due date.**
  The completion path, the editor, the starter-task installer and the
  dashboard's two-year forward projection all call it. A second implementation
  for the projection would eventually draw a date the app would never actually
  produce, and you'd find out on the day it didn't arrive.

- **Renaming a room follows every item already tagged with it; deleting a
  vendor does not rewrite history.** These look inconsistent and sit ten tables
  apart in `schema.sql`. Tags are a foreign key, so a rename is one `UPDATE` and
  everything follows — a room that gets renamed is the same room. But
  `vendor_name` is a snapshot *string* on both `log_updates` and
  `task_completions`, so deleting a vendor nulls the link and the 2023 invoice
  still says who sent it. Different questions, different answers. **Do not
  "make them consistent."**

- **Tag names are stored exactly as typed.** `Ext Studio` is not expanded to
  `Exterior Studio`. These are the names the house is called by, and tidying
  them breaks the recognition that makes a filter list scannable — plus every
  filter the app has already been used with. A test asserts the seeded list
  verbatim, and it exists to fail loudly the day someone helpfully tidies one.

- **`NULL` means unset and renders as *nothing*, everywhere.**
  `log_entries.severity`, the `rating` and `cost` on both `log_updates` and
  `task_completions`, `vendors.rating_override`. Zero is not a legal value for
  any of them. A `NULL` cost is "not recorded", which is not the same as free
  — `service_total()` counts those rows separately rather than summing them as
  zero — and rendering unset severities as "Watch" would be a grey pill on
  every entry that looked like data and wasn't.

- **`log_entries.next_check_on` is stored, not derived, and so is
  `maintenance_tasks.next_due_on`.** Both look redundant beside the rule that
  produced them. They are what let the dashboard range-scan an index instead of
  evaluating `last_checked_on + INTERVAL check_interval_days DAY` per row —
  which no index can help with and which the SQLite test harness cannot
  evaluate at all. Every scheduling date in this app is computed in PHP and
  written to a column. There is no `DATE_ADD`, no `INTERVAL` and no `NOW()` in
  any query against them.

- **Originals are kept forever, and there is no reaping sweep.** The
  Inspiration Gallery deletes originals once it has derived thumbnails, and
  Shirewatch deliberately does not. Its images are visual references; these are
  evidence. The reason a photo of a crack exists is to be put beside a photo of
  the same crack eight months later, possibly in front of an insurance adjuster
  — and a re-encoded 1600px WebP is not what you want to be holding then.

- **Uploading does no image work.** It saves the original, writes a `pending`
  row and returns; the browser drains the queue afterwards and a cron sweeps
  what a closed tab stranded. Resizing inline would be simpler and would put a
  ten-file batch of receipts 10–20 seconds into a request, which on shared
  hosting is a live risk of `max_execution_time` and a half-uploaded batch with
  no error anyone can act on.

- **Claiming a queue row is a single conditional `UPDATE` whose `rowCount()`
  decides the winner.** Two workers race here by design. **Never split it into a
  `SELECT` and then an `UPDATE`** — the window between them is one where both
  workers believe they own the row, and the symptom is a set of orphaned
  derivatives on disk.

- **A batch upload never fails as a whole.** One unreadable file comes back in
  a `rejected` list with a reason, beside the ones that worked.

- **`media` has three nullable owner columns rather than a polymorphic
  `owner_type` + `owner_id` pair.** `entry_id`, `update_id`, `completion_id`.
  Polymorphic ownership cannot be a foreign key, so deleting a log entry would
  leave its photos as rows pointing at nothing and files nothing would ever
  reap. Two spare columns per row buys a real `ON DELETE CASCADE`. The three
  tag join tables are three tables for the same reason.

- **ISSUES AND SERVICE HISTORY ARE ONE THING.** They were two tables and two
  screens, and they were the same event seen at two moments: a problem you have
  not fixed yet, and a problem somebody was paid to fix. Now there is one:

      Log tab  >  log entries  >  updates

  An update is a `note` (something you observed) or a `service` (somebody was
  paid), on one timeline, in the order it happened. **Do not re-grow a
  `service_records` table.**

- **A paid ROUTINE service is a maintenance completion that cost money, not a
  log entry.** The annual HVAC visit was never a problem, so filing it in the
  log would mean inventing a fault that never existed. `task_completions`
  carries the same four money columns, and `task.php` has a form for them.

- **"Service" is therefore a VIEW, not a table.** `lib/service.php` is the
  *only* code allowed to know there are two sources; it unions them, and it
  never writes. A screen that queries `log_updates` or `task_completions`
  directly for money is a bug — the two halves drift and the total stops
  matching the rows above it. Vendor ratings are **summed** across both
  sources, never averaged as two averages.

- **`log_entries.resolved_by_update_id` is optional and is not a foreign key.**
  It names the update on the entry's own timeline that fixed it — usually the
  service visit. Three visits can sit on one entry while only one of them
  resolved it, and fixing something yourself resolves the entry with nothing
  attached. There is no constraint because it points forward at a table
  created later in `schema.sql` and SQLite cannot add one by `ALTER`; a stale
  id renders as no link, which is a better failure than refusing to resolve.

- **The dashboard combines the log and maintenance in the forward timeline but
  separates them in "needs action now".** The brief asked for them kept
  separate. That is honoured where it earns its place: deciding what to do this
  morning, "call a plumber" and "change a filter" are different kinds of thing.
  In the forward stream the organizing principle is *when*, and two parallel
  columns of dates is a calendar nobody can read.

- **A projected timeline row is a prediction, not a stored row.** It is dimmed
  (`.is-projected`) so the difference is visible. Completing a task early moves
  everything after it, and the app has not promised you those dates.

- **`user_id` exists on four tables, has no `users` table behind it, and
  nothing reads it.** So does a real `properties` table with exactly one row.
  Both are the brief's explicitly-requested exception to the siblings'
  "don't leave hooks" rule: multi-property and shared household access are
  named as future work, and a real foreign key now means adding a second house
  later is an `INSERT` rather than a migration that backfills a column and adds
  constraints to six tables at once, on live data.

- **`public/cron.php` is the only unauthenticated surface in the app.** It
  cannot be behind a session, because the thing fetching it is a scheduler. It
  is gated on a long random token compared with `hash_equals()`, returns **404**
  rather than 403 so it doesn't confirm it exists, and **refuses to run with an
  empty token** — the opposite of the login gate's fail-open, because failing
  open here locks nobody out of anything, it just puts a job that sends email on
  the open internet.

- **The login gate fails OPEN when no `password_hash` is configured.** Locking
  someone out of their own app with no way back in is worse than an unlisted
  URL. Setting the hash is what arms it.

- **`tools/test-harness.php` translates `schema.sql` into SQLite.** MySQL is the
  production target and the only one. That file exists solely because the build
  environment has no MySQL and no Docker; a green test run says the schema is
  coherent, not that MySQL accepts it. **Never edit `schema.sql` to make the
  translator's job easier — teach the translator.**

- **The starter maintenance list is a data file plus an installer, not
  `INSERT`s in `schema.sql`.** `next_due_on` is `NOT NULL` and has no sensible
  literal: a fixed date in the schema means every fresh install opens on twenty
  tasks that are already overdue, which is the impression that gets an app
  deleted in its first minute. The dates are computed forward from the day of
  install by the real recurrence engine. The installer is idempotent on title
  and **never resurrects a task you deleted.**

## Out of scope for v1

Budgeting and cost analytics across the log · smart-home integration · shared
household access (schema-aware, not built) · a vendor database beyond the basic
directory · offline mode · a service worker · push notifications · importing
existing repair history.

Don't build these, and don't leave hooks for them.

The two deliberate forward-looking exceptions, both explicitly asked for: the
`properties` table with its one row, and the inert `user_id` columns.
