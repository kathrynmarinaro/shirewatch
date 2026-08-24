# Shirewatch — Delegation Plan

House maintenance: issues watched over time, scheduled maintenance, service
history, vendors. Fifth app in the suite, and the first one built with four
siblings to steal from instead of two.

This document is the plan. It is **not** the contract — Foundation's first job
is to turn §3 and §6 of this into `docs/CONTRACTS.md` and `schema.sql`, which is
what the module passes actually read.

---

## 1. Why this shape, and what changed after reading Personal CRM

The suite builds apps the same way every time: **one serial Foundation pass that
freezes the contracts, then modules against them, then integration.** That holds
here. What changed is how much of this app already exists somewhere else.

Before reading `personal-cms`, email reminders were the one piece of this brief
with no precedent anywhere in the suite — no `mail()`, no SMTP, no mailer in
Book Tracker, Grocery or Inspiration. It was the biggest open risk in the build.

It is now the most solved part of it. Personal CRM is the closest sibling
Shirewatch has, and it is close in exactly the places that matter:

| Personal CRM has | Shirewatch needs it for |
|---|---|
| `lib/mailer.php` + vendored PHPMailer (3 plain files, no Composer) | the reminder emails |
| `lib/dates.php` — all date arithmetic in PHP, never `DATE_ADD`/`INTERVAL` | the recurrence engine |
| `reminders.next_due_date` as a plain `DATE`, queried `WHERE next_due_date <= ?` | the due/overdue dashboard |
| `reminder_sends` keyed `PRIMARY KEY (reminder_id, due_date)` | send idempotency |
| `tools/cron-reminders.php` + `public/cron.php` (token-gated URL cron) | Hostinger plans without SSH |
| `assets/menu.js` — generic hamburger → `.sheet` | the nav you picked |
| Bottom tab bar **plus** hamburger overflow, already reconciled | the nav you picked |
| `tools/test-harness.php` — `schema.sql` → in-memory SQLite | testing any of it |

So the plan below ports rather than invents, and the email module drops from
"largest unknown" to "smallest module in the build."

### The one idea worth stealing outright

Personal CRM settled a question this brief also has, and settled it well:

> **The send ledger stops the duplicate email. The due date does not move until
> the thing is actually done.**

A reach-out reminder fires, emails you, and *stays overdue* — the dashboard
keeps showing it and it gets louder. What moves it is logging an actual contact.
Advancing it on send "would mean the app quietly forgives you for not calling
your sister."

Substitute *water heater* for *sister* and that is Shirewatch's maintenance
model, unchanged. §2.3 adopts it verbatim rather than re-deriving it.

---

## 2. Decisions — settled

Everything here changes the schema, which is why it is settled before Foundation
starts. §2.1–§2.4 are your answers; §2.5–§2.11 are calls I made — flag any you
disagree with and I will change them before Foundation writes a line.

### 2.1 Tags: seeded lists, multi-select — **settled**

One `tags` table with a `kind` column, not two taxonomy tables and six join
tables. Locations, categories and vendor work types are the same shape — a short
editable list of names you pick several of — so they are one table with
`kind ENUM('location','category','work_type')` and three join tables
(`issue_tags`, `task_tags`, `record_tags`, `vendor_tags`).

Four tables instead of eight, real foreign keys and real `ON DELETE CASCADE`
throughout, and **one tag-picker component** built once and reused on every
screen instead of two that drift apart.

Locations are property-scoped (`tags.property_id` non-NULL — rooms differ per
house). Categories and work types are not (plumbing is plumbing everywhere), so
their `property_id` is NULL. That is the one awkward nullable in the schema and
it is documented on the column.

Seeded and **fully deletable**, the same rule as Grocery's four stores: nothing
in the code may treat any seeded tag as special.

**Renaming a tag renames it everywhere, and that is the point of the foreign
key.** Issues, tasks, records and vendors link to `tags.id`, never to the text,
so `UPDATE tags SET name = ?` is the entire rename — every issue already filed
under "Emma Room" follows it to whatever you call it next, with nothing to
migrate and no window where some rows say one thing and some say another.

This looks inconsistent with §2.10, where `service_records.vendor_name` is a
snapshot *string*. It isn't, and the difference is worth stating because the two
sit ten lines apart in the schema:

| | Rename | Delete |
|---|---|---|
| **Tags** (`tag_id` FK) | follows everywhere — the room is still the same room | cascades off the items |
| **Vendor** (`vendor_id` FK + `vendor_name` snapshot) | follows | record survives, still naming who did the work |

A room that gets renamed is the same room. A vendor that gets deleted is gone,
but the 2023 invoice still has to say who sent it. Different questions, so
different answers.

### 2.2 Recurrence: both anchors, declared per task — **settled**

```
recur_kind      ENUM('interval','months')
interval_count  SMALLINT NULL          -- 3
interval_unit   ENUM('day','week','month','year') NULL
interval_from   ENUM('completion','due') NOT NULL DEFAULT 'completion'
recur_months    VARCHAR(35) NULL       -- "3,10" — March and October
recur_day       TINYINT NULL           -- day of month, default 1
next_due_on     DATE NOT NULL
```

Wear-based tasks (HVAC filter, dryer lint, fridge coils) are
`interval` + `from completion` — do it late and the next one shifts later,
because the wear clock started when you last touched it.

Seasonal tasks (gutters, sprinkler blowout, storm-window swap) are `months`, so
they cannot drift out of their season over years the way a 180-day interval
would.

`next_due_on` is a stored `DATE`, computed in PHP and rewritten on completion —
never `DATE_ADD` in a query. Three reasons, all Personal CRM's: the due query
stays a sargable `WHERE next_due_on <= ?`, it behaves identically on MySQL and
on the SQLite the tests run against, and there is exactly one clock.

**The obvious question, answered:** if you skip March's gutters entirely, the
task does not silently roll to October. It stays overdue at March and says so —
"5 months overdue" is true and useful; two overdue gutter rows would not be.
One rule, no special cases (§2.3).

### 2.3 A task's due date moves on completion, not on send — **settled, ported**

Ported from Personal CRM §7.2 with the names changed:

- `task_reminder_sends`, `PRIMARY KEY (task_id, due_on)`, is what stops the
  cron emailing you about the same filter change eight times. Hostinger's
  scheduler can double-fire and SMTP can hang; a failed attempt leaves
  `sent_at` NULL so tomorrow retries, and a delivered one is never re-sent.
- `next_due_on` moves in exactly one place: `task_complete()`.

So an overdue task emails **once**, then lives on the dashboard getting louder.
It does not nag by email and it does not forgive you.

### 2.4 Scoping: real `properties` table, `user_id` column only — **settled**

`properties` with one seeded row, and `property_id INT UNSIGNED NOT NULL` with a
real FK on `issues`, `maintenance_tasks`, `service_records`, `vendors` and the
location tags. Adding a second house later is an INSERT, not a migration.

`user_id INT UNSIGNED NULL` on the same tables, **no `users` table, no FK, and
nothing reads it.** It is a reserved column, inert by design.

This is the deliberate exception to the siblings' "don't leave hooks" rule —
the brief asks for it in as many words, exactly as Grocery's
`grocery_purchase_log` is its explicitly-requested exception.

### 2.5 Issues: four states, linking optional — **settled**

```
watching ──→ active ──→ resolved
    └──────────┴──────→ dismissed
```

`watching` is the app's core idea (log it, photograph it, see whether it moves).
`active` is "this needs doing." `dismissed` is "watched it, it was nothing" —
kept out of the list without pretending it was repaired.

`resolved_by_record_id` is **nullable and never required.** Fix a thing
yourself and the issue resolves with no record attached. Separately,
`service_records.issue_id` links work to an issue — three visits can reference
one issue while only one of them resolved it, which is why both columns exist.

> **§2.15 merged issues and service records.** The four states survive
> unchanged and so does the optional link, which is now
> `log_entries.resolved_by_update_id` pointing at an update on the entry's own
> timeline. `service_records.issue_id` is gone: a visit no longer *references*
> an entry, it *is* one of that entry's updates.

### 2.6 Issues carry a check-back interval — **settled**

*Read "log entry" for "issue" throughout §2.5–§2.7; §2.15 renamed the thing,
not the design.*

No longer veto-able: §2.13's timeline is built on "issue follow-ups", which is
this column set. Cutting it now would empty half the stream.

`check_interval_days` and `last_checked_on` (both nullable), plus
**`next_check_on DATE NULL`** — the first two are the rule, the third is the
answer, stored rather than computed.

That third column is not redundant. It is what lets an issue follow-up sit in
the same `WHERE date <= ?` range scan as a maintenance task instead of forcing
the dashboard into `last_checked_on + INTERVAL check_interval_days DAY`, which
no index can help with and which the SQLite test harness cannot evaluate at all.
Written in PHP on every check-in, exactly the discipline §2.2 applies to
`next_due_on`. It is also what makes §2.13's unified timeline a two-branch union
of identically-shaped queries rather than a special case.

This is the column set that makes the core idea actually happen. An issue you
log and never revisit has one photo and no timeline, and the brief's whole
premise is the second photo.

### 2.7 Timeline updates carry severity — **my call**

Each `issue_updates` row has an optional `severity`, so "stable vs. worsening"
is *data* — renderable as a trend beside the photos — rather than prose buried
in a note. The issue row carries the latest value denormalized, because the
dashboard sorts on it and deriving it per row means a correlated subquery over
a table that only grows. Same reasoning as Grocery's denormalized `day_of_week`.

Severity is `1..4` (Watch · Minor · Major · Urgent), `NULL` = not set. Zero is
not a legal severity, following Book Tracker's ratings: `NULL` means *unset* and
renders as nothing.

### 2.8 Photos go through a queue; originals are kept — **my call**

You had no preference, so: **one upload path, the queue**, ported from
Inspiration (`lib/queue.php`, `api/worker.php`, the atomic claim-by-UPDATE).

Reasons it wins over resizing inline:
- A 10-file service-record batch resized inline is 10–20s and a real risk of
  hitting `max_execution_time` on shared hosting. The upload returning
  immediately removes that entirely.
- The cron infrastructure exists regardless, for reminders. The queue is
  reusing a scheduled job, not adding one.
- Even for single-photo issue capture it is *better*, not merely acceptable —
  the browser drains the queue right after upload, so the photo is ready in
  about a second and the UI never blocks waiting for it.

**Divergence from Inspiration, deliberate: originals are kept, not deleted.**
Inspiration reaps originals because its images are references. Shirewatch's are
evidence — an insurance claim or a contractor dispute is precisely the scenario
these photos exist for, and a re-encoded 1600px WebP is not what you want to
hand an adjuster. Storage cost is roughly 3 MB per photo — call it 1.5 GB after five years of
heavy use, against the 50–100 GB a Hostinger plan gives you. **Confirmed.**

### 2.9 Documents are a separate path from photos — **my call**

Invoices and warranties are PDFs. They get validation (type sniffed from bytes,
25 MB cap, generated slug filename) and storage under `uploads/docs/`, and
**no derivation** — no thumb, no WebP, no queue. The original filename is kept
in the database for display; it never touches the filesystem path.

Allowed: PDF, JPEG, PNG, HEIC, WebP. Everything else is rejected by name in the
response, not silently dropped.

### 2.10 Vendor rating: average of jobs, override is `NULL`-able — **settled from the brief**

`service_records.rating` is `1..5` `NULL`-able, rated per job. A vendor's
overall rating is `AVG()` across their rated records. `vendors.rating_override`
is `NULL` = "use the average", non-NULL = "I've decided." Same `NULL`-means-unset
rule as everywhere else in the suite.

Deleting a vendor **must not** delete your repair history: `vendor_id` is
`ON DELETE SET NULL`, and `service_records.vendor_name` keeps a snapshot string
so a 2023 invoice still says who did the work. That is Grocery's
category-name-as-snapshot decision applied to a different column.

### 2.11 Branding: app name in config, tokens renamed — **my call**

`cfg('app_name', 'Shirewatch')` — the siblings hardcode `const APP_NAME`; this
one doesn't, per the brief.

The design tokens get **semantic names** in this app: `--accent`,
`--accent-dark`, `--accent-tint` instead of `--teal`, `--teal-dark`,
`--teal-tint`. The values stay the house teal `#41b7ab` for v1, so it still
looks like the suite — but re-skinning for release is three hex values in one
`:root` block rather than a find-and-replace across a stylesheet. Cheap now,
annoying later.

### 2.12 Seeded locations are Kathryn's rooms, spelled her way — **settled**

The twenty-four `kind='location'` tags ship in `schema.sql` as:

> Library · Dining Room · Entryway · Living Room · Emma Bathroom · Emma Room ·
> Guest Room · Coat Closet · Main Bedroom · Main Bathroom · Sunroom ·
> Breakfast Room · Kitchen · Space Bathroom · Laundry Room · Garage ·
> Ext Studio · Yard · Roof · Septic Tank · Gutters · Chimney · Garage Attic ·
> House Attic

**Seeded exactly as given, and never normalized.** Not "Emma's Bathroom", not
"Exterior Studio" — `Ext Studio` stays `Ext Studio`. These are the names the
house is called by the person using it, and a later pass "tidying" them breaks
the recognition that makes a filter list scannable.

This is Grocery's `grocery_items.name` rule applied to a seeded list: stored as
typed, normalized never. It goes in `CLAUDE.md` under things that look like bugs
but are decisions, because `Ext Studio` is exactly the thing a future agent
expands helpfully.

The one edit made on request: `Guest room` → **`Guest Room`**.

The last six are what a house issue actually attaches to even though none is a
room — a leak logs against `Roof`, not against the room whose ceiling it came
through. They also give the seeded gutter and chimney tasks somewhere to point,
which the original eighteen did not.

**One assumption, flagged rather than guessed at silently:** "Chimney Garage
Attic" is read as three tags — `Chimney`, `Garage Attic`, `House Attic` — on the
grounds that a chimney-garage-attic is not a place. Say so if it was two.

### 2.13 The dashboard is an action accordion over a forward timeline — **settled, and it revises the brief**

Two stacked components, per your description:

**1 · Needs action now** — a `<details class="accordion">`, open by default,
holding everything already due: overdue and due-today maintenance, issues past
their check-back date, and `active` issues. **Inside the accordion it stays
split into an issues group and a maintenance group**, which is the brief's
"separate sections for issues vs. maintenance (not combined)" honoured where it
matters — when you are deciding what to do this morning, "call a plumber" and
"change a filter" are different kinds of thing.

**2 · The forward timeline** — one chronological stream, **deliberately
combined**, scrolling from tomorrow into the future. Here the brief's
separation is dropped on purpose: the organizing principle is *when*, and two
parallel columns of dates is a calendar nobody can read. Each row still carries
its type, so it is obvious what you are looking at.

This is a real change to the brief and it is the right one, but it is a change,
so it is written down rather than absorbed.

#### How the timeline is actually built

Both sources are stored `DATE` columns — `maintenance_tasks.next_due_on` (§2.2)
and `issues.next_check_on` (§2.6). That is what makes this a `UNION ALL` of two
identically-shaped, index-backed range scans instead of the differently-shaped
union Personal CRM's `schema.sql` warns about:

```sql
SELECT next_due_on AS on_date, 'task'  AS kind, id, title FROM maintenance_tasks WHERE ...
UNION ALL
SELECT next_check_on,          'issue',         id, title FROM issues            WHERE ...
ORDER BY on_date, kind, id
```

**Paged by keyset, never `OFFSET`.** The cursor is the last row's
`(on_date, kind, id)`. Infinite scroll with `OFFSET` silently repeats or skips a
row when anything is inserted mid-scroll, and on this screen the thing being
inserted is usually the row you just completed.

#### Recurring tasks are projected forward; issue check-backs are not

A task stores only its *next* occurrence, so a quarterly filter change would
appear once and the timeline would run dry after a month. So the renderer
projects each recurring task forward over a horizon
(`cfg('dashboard.horizon_months')`, default 24) using **the same
`recur_next_after()` the completion path calls** — one implementation, so the
timeline can never predict a date the app would not actually produce.

Issue check-backs show **only the next one**. A maintenance task genuinely
recurs forever; an issue's next look-at depends entirely on what you see when
you look, so projecting a chain of them would be inventing schedule that does
not exist.

Projected occurrences are predictions, not rows: nothing is written, and doing a
task late reshuffles everything after it. At a few dozen tasks over 24 months
that is a few hundred rows built in PHP per page — cheaper than the round trip
that fetched them.

### 2.14 The other two seeded lists — **my call**

Locations got specified; these did not, so they are named here rather than left
to Foundation's judgement.

**Categories** (systems, `property_id` NULL): Plumbing · Electrical · HVAC ·
Roofing · Structural · Exterior · Interior · Appliances · Landscaping · Pest ·
Safety · Septic

**Work types** (vendor directory): Plumber · Electrician · HVAC · Roofer ·
General Contractor · Handyman · Landscaper · Pest Control · Appliance Repair ·
Septic Service · Chimney Sweep · Painter

Note `Roofing` the category and `Roof` the location are not duplicates and both
earn their place: the category answers *what kind of problem*, the location
answers *where*. A soffit issue is `Exterior` + `Roof`.

---

### 2.15 Issues and Service History are one thing — **settled, and it revises §2.5 and §3**

*Added after the app had been in use for a few weeks.*

The brief asked for issues and a service history, and the build delivered two
tables, two screens and two link columns between them. Then the author's AC
stopped working, and she used the app for it:

> They're the same thing, just sometimes I have it fixed (service) and other
> times it's open and not fixed (issues).

She is right, and the two-table shape had been quietly making that case
expensive the whole time. One AC failure produced a log entry, a service record
and a link — three rows for one event — and neither screen told the whole
story. The Issues screen showed a problem with no sign that somebody had
already been out; the History screen showed a visit with no sign of what it was
for.

**The merge.** One kind of thing, at three levels of naming, which the author
chose in these words — *"I'd prefer thinking about adding an 'update to a log
entry' rather than an 'entry to a log item'"*:

    Log tab  >  log entries  >  updates

An **update** carries a `kind`: `note` (something you observed) or `service`
(somebody was paid). Both sit on one timeline in the order they happened, which
is the point — "wider than the pencil mark now" and "Blue Ridge HVAC, $412,
replaced the capacitor" are the same story.

**Routine paid maintenance does not become a log entry.** The author's own
correction, and a better model than the one I proposed:

> Wouldn't that be maintenance? a paid maintenance moment would have a
> 'service' tag to it

Filing the annual HVAC service in the log would mean inventing a fault that
never existed. So `task_completions` grew the same four money columns
(`vendor_id`, `vendor_name`, `cost`, `rating`) and `task.php` grew a form
behind a `<details>` — the one-tap "Mark done today" is still one tap, because
most completions are you, in ten minutes.

**"Service" is therefore a view, not a table.** `lib/service.php` unions the
two sources and is the only file allowed to know they are two. Everywhere else
they stay apart, because they genuinely are different — one is a story, the
other is a schedule. The Service screen is the one place the question is "what
did we pay, and to whom", and there the distinction stops mattering.

Consequences worth naming:

- **Vendor ratings are summed across both sources, not averaged as two
  averages.** One 5-star repair and four 3-star services is 3.4, not 4.0.
- **A completion with neither a cost nor a vendor is not a service event.** You
  did it yourself; that belongs on the task, not in a list of what the house
  cost.
- **`service_total()` counts unpriced rows rather than summing them as zero.**
  A total that quietly treats "not recorded" as "free" is a number you would
  trust and shouldn't.
- **`issues.resolved_by_record_id` became `log_entries.resolved_by_update_id`**
  and now points at an update on the entry's own timeline. §2.5's rule survives
  intact: linking is always optional, and several visits can sit on one entry
  while only one of them resolved it.

**There was live data**, so this shipped with `tools/migrate-to-log.php` rather
than a fresh `schema.sql`. It folds every `service_record` into an update, a
completion, or a new already-resolved entry, and **drops nothing until the
before and after row counts agree** — the old tables are the safety net until
the arithmetic proves they are not needed.

---

## 3. Navigation — settled

*Revised by §2.15: the Issues tab is now the Log tab, and Service History is
now Service.*

**Bottom tabs:** Dashboard · Log · Maintenance · Vendors
**Hamburger sheet:** Service · **Rooms & Tags** · Export data · Log out

**Rooms & Tags** is the editing screen for the three seeded lists (§2.1, §2.12,
§2.14) — rename, reorder, add, delete, per `kind`. It is in the sheet rather
than the tab bar because it is a thing you do twice a year, which is exactly the
rule `menu.js` was ported to enforce.

Straight port of Personal CRM's `page_menu()` + `assets/menu.js`, which already
solved "four daily jobs in the bar, everything you do once in the sheet." Four
tabs is one more than any sibling ships and still comfortable at 48px on a
phone; five would truncate labels.

Service lives in the sheet because it is almost always reached *from* a log
entry or a vendor rather than browsed — but it remains a real URL, so nothing in
the menu is the only way to reach anything. Keeping it out of the tab bar is
also what makes the merge legible: the tab bar names the four things you *do*,
and reviewing what the house cost is not one of them.

---

## 4. Phase 0 — Foundation (serial, blocking)

Nothing else starts until this lands. Everything after it is written against
what it freezes.

| File | Notes |
|---|---|
| `schema.sql` | The contract. Heavily commented, frozen on merge |
| `lib/bootstrap.php`, `lib/db.php`, `lib/auth.php` | Ports. **Gate ON**, like Grocery — one password, no public surface except `cron.php` (§5) |
| `lib/dates.php` | Port from Personal CRM, plus the recurrence arithmetic (§2.2) |
| `lib/layout.php` | Chrome, 4-tab bar, `page_menu()` |
| `lib/tags.php` | Tag read/write + the picker's server side. Every module touches tags; if this isn't Foundation-owned there will be four versions of the same query |
| `lib/media.php`, `lib/queue.php` | Ports from Inspiration, vision call removed, documents path added |
| `lib/mailer.php`, `lib/vendor/PHPMailer/` | Port from Personal CRM, byte-identical vendored files |
| `public/assets/styles.css` | House tokens renamed semantic (§2.11) + the new components |
| `public/assets/api.js`, `swipe.js`, `inline-edit.js`, `reorder.js`, `menu.js`, `tagfield.js` | Ports. `tagfield.js` from Inspiration, adapted to the seeded-list picker |
| `public/component-test.html` | Every component on one static page, no auth, no data. Book Tracker's pattern |
| `public/login.php`, `logout.php`, `.htaccess` ×4, `config.example.php` | Ports |
| `tools/test-harness.php`, `run-tests.php`, `make-hash.php`, `build-deploy.php` | Ports from Grocery / Personal CRM |
| `tools/seed.php` | **The unblocking move** |
| `docs/CONTRACTS.md` | What every module pass actually reads |

`tools/seed.php` generates a plausible house: ~25 issues across the four states
with multi-entry timelines and photos, ~20 maintenance tasks in both recurrence
shapes with some overdue, ~15 service records with costs and ratings, 8 vendors.
Without it every screen is built against an empty database and nothing can be
judged by eye.

**`tools/hosting-check.php` runs in this phase, not later.** Book Tracker's
equivalent changed its plan (it is what cut Google Books). Shirewatch's needs to
confirm: Imagick present, `uploads/` writable, outbound SMTP to
`smtp.gmail.com:587` open, and whether the plan gives a command cron or only a
URL fetch. That last answer decides whether `public/cron.php` is the primary
entry point or the fallback.

---

## 5. Phase 1 — Modules, in dependency order

Serial, per your call. Each pass sees the previous one's real code rather than a
written promise, which matters in an app this interlinked — nearly everything
here links to something else.

| # | Module | Owns |
|---|---|---|
| **M1** | Tags & taxonomy | the picker UI, **the Rooms & Tags editing screen**, filter chips |
| **M2** | Media pipeline | `api/upload.php`, `api/worker.php`, `cron/process-queue.php`, docs path, the gallery/lightbox component |
| **M3** | Issues & timeline | `issues.php`, `issue.php`, the capture flow, timeline rendering, severity trend |
| **M4** | Maintenance & recurrence | `maintenance.php`, the recurrence engine, `task_complete()`, the seeded starter list |
| **M5** | Service history & vendors | `history.php`, `vendors.php`, `vendor.php`, cost/rating, auto-populated work history |
| **M6** | Dashboard & reminders | `index.php` — the action accordion over the forward timeline (§2.13), keyset paging, recurrence projection — plus `tools/cron-reminders.php`, `public/cron.php`, the email template |
| **M7** | Integration & deploy | `DEPLOY.txt`, `README.md`, `CLAUDE.md`, export, full test pass |

M5 is one module and not two: a vendor's rating and work history are *derived
from* service records, so splitting them means building the same join twice.

**M6 grew.** §2.13's timeline — keyset paging, forward projection of
recurrences, a merged stream — is more than the dashboard the brief described,
and it is now the second-largest module after M2. The projection reuses M4's
`recur_next_after()` rather than reimplementing it, which is the only reason it
is not larger still.

**There is no import pass and no importer.** Confirmed: there is no existing
repair log or vendor list to bring in, and first-run data gets typed in as it
happens. So M7 stays integration-and-deploy — unlike Book Tracker, where the
importer was the longest pole in the build. First run is the seeded starter
maintenance list (below) and the eighteen seeded locations (§2.12), on top of
otherwise empty tables.

**The starter maintenance list (M4)** ships as `INSERT IGNORE` rows in
`schema.sql`, not a wizard — house-wide (HVAC filters, gutters, water heater
flush, smoke detector batteries, dryer vent, sump pump test, caulking,
sprinkler blowout) and appliance-specific (fridge coils, washer hoses, dryer
lint trap, dishwasher filter, range hood filter, garbage disposal). All fully
editable and deletable, none special-cased anywhere in the code.

---

## 6. Schema sketch

Foundation writes the real, commented version. Fifteen tables.

```
properties          id, name, address, created_at            -- one seeded row

tags                id, kind ENUM('location','category','work_type'),
                    name, property_id NULL, sort_order
                    UNIQUE (kind, property_id, name)

issue_tags          issue_id,  tag_id        -- all four: PK pair, both FKs cascade
task_tags           task_id,   tag_id
record_tags         record_id, tag_id
vendor_tags         vendor_id, tag_id

issues              id, property_id, user_id NULL,
                    title, description,
                    status ENUM('watching','active','resolved','dismissed'),
                    severity TINYINT NULL,          -- 1..4, NULL = unset, §2.7
                    noticed_on DATE,
                    check_interval_days NULL, last_checked_on DATE NULL,
                    next_check_on DATE NULL,        -- stored, not derived, §2.6
                    resolved_on DATE NULL,
                    resolved_by_record_id NULL,     -- SET NULL, §2.5
                    created_at

issue_updates       id, issue_id, noted_on DATE, note,
                    severity TINYINT NULL, created_at        -- cascade

maintenance_tasks   id, property_id, user_id NULL,
                    title, instructions,
                    recur_kind, interval_count, interval_unit, interval_from,
                    recur_months, recur_day,                 -- §2.2
                    next_due_on DATE, last_completed_on DATE NULL,
                    is_active, created_at

task_completions    id, task_id, completed_on DATE, note NULL,
                    service_record_id NULL, created_at       -- cascade

task_reminder_sends task_id, due_on, attempts, sent_at NULL, last_error NULL
                    PRIMARY KEY (task_id, due_on)            -- §2.3

service_records     id, property_id, user_id NULL,
                    vendor_id NULL,          -- SET NULL, §2.10
                    vendor_name,             -- snapshot string, §2.10
                    title, description, performed_on DATE,
                    cost DECIMAL(10,2) NULL, rating TINYINT NULL,
                    issue_id NULL, task_id NULL,             -- both SET NULL
                    created_at

vendors             id, property_id, name, phone, email, notes,
                    rating_override TINYINT NULL, created_at -- §2.10

media               id, kind ENUM('photo','document'),
                    issue_id NULL, issue_update_id NULL, service_record_id NULL,
                    slug, ext, original_path, thumb_path, detail_path,
                    original_filename, mime, bytes, width, height,
                    caption, sort_order,
                    status, attempts, last_error, locked_at, -- queue, §2.8
                    created_at

login_attempts      -- ported unchanged
```

Two indexes carry the dashboard and must not be dropped as redundant:
`maintenance_tasks (next_due_on)` and `issues (next_check_on)`. They are what
make §2.13's timeline two range scans, and the cron's due query a third.

**`media` uses three nullable FK columns, not a polymorphic `owner_type`.**
Exactly one is non-NULL. It costs two spare columns per row and buys real
`ON DELETE CASCADE` — deleting an issue takes its photos with it, enforced by
the database rather than by remembering to.

---

## 7. Phase 2 — Integration and deploy

Full `tools/run-tests.php` pass; recurrence arithmetic is the section that
matters most, because a schedule that drifts is invisible until a season is
missed. `DEPLOY.txt` in Grocery's shape, plus the two things unique to this app:
the PHP upload limits Inspiration's README documents (defaults reject every
iPhone photo), and the SMTP verification step —
**`php tools/send-test-email.php` before trusting a cron you cannot watch run.**

---

## 8. What I'd cut if it runs long

In order: the severity trend rendering (§2.7 — keep the column, drop the chart)
· export · **the timeline's forward projection** (§2.13 — show each task's next
occurrence only; the stream thins out but every date in it is real, and the
projection can be added later without a schema change).

**Off the cut list, where it used to be:** the Rooms & Tags screen. You asked
for it by name, so it ships.

**Nothing else above the line** — the four modules in the brief all ship, and so
does the dashboard in §2.13.

---

## 9. Answered — nothing is blocked

1. **Email.** `personal-cms` is the code. Its `lib/mailer.php`, vendored
   PHPMailer, `lib/dates.php`, `tools/cron-reminders.php`, `public/cron.php` and
   `tools/send-test-email.php` all port in Phase 0.
2. **"Art Scan" is the Inspiration Gallery.** There is no sixth app; the brief
   names one thing twice. Every capture-flow convention in this plan comes from
   `inspiration`.
3. **No import.** See §5.
4. **Rooms.** §2.12, seeded verbatim.
5. **Originals are kept.** §2.8, confirmed.

Round two, folded in:

6. **`Guest Room`** capitalized (§2.12).
7. **Six locations added** — Roof, Septic Tank, Gutters, Chimney, Garage Attic,
   House Attic (§2.12). One reading assumption flagged there.
8. **Rooms & Tags editing screen**, in the hamburger, owned by M1 (§3, §5). Off
   the cut list.
9. **Renaming a room follows every item already tagged with it** (§2.1) — it is
   what the `tag_id` foreign key was for, so this needed documenting, not
   building.
10. **The dashboard is respecified** (§2.13): action accordion over a combined
    forward timeline. This one revises the brief; the reasoning is in place.

Foundation can start.
