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

`issues.resolved_by_record_id` is **nullable and never required.** Fix a thing
yourself and the issue resolves with no record attached. Separately,
`service_records.issue_id` links work to an issue — three visits can reference
one issue while only one of them resolved it, which is why both columns exist.

### 2.6 Issues carry a check-back interval — **my call, veto-able**

`check_interval_days` (nullable) and `last_checked_on` on every issue. The
dashboard surfaces **stale** issues — "you haven't looked at the basement crack
in 4 months" — not just open ones.

This is the column that makes the core idea actually happen. An issue you log
and never revisit has one photo and no timeline, and the brief's whole premise
is the second photo. It is two columns and one dashboard query, and it reuses
the maintenance due-date machinery wholesale.

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

The eighteen `kind='location'` tags ship in `schema.sql` as:

> Library · Dining Room · Entryway · Living Room · Emma Bathroom · Emma Room ·
> Guest room · Coat Closet · Main Bedroom · Main Bathroom · Sunroom ·
> Breakfast Room · Kitchen · Space Bathroom · Laundry Room · Garage ·
> Ext Studio · Yard

**Seeded exactly as written, and never normalized.** Not "Emma's Bathroom", not
"Exterior Studio", and `Guest room` keeps its lowercase r. These are the names
the house is called by the person using the app, and a later pass "tidying" them
into title case breaks every filter the app has already been used with.

This is Grocery's `grocery_items.name` rule applied to a seeded list: stored as
typed, normalized never. It goes in `CLAUDE.md` under things that look like bugs
but are decisions, because `Ext Studio` is exactly the kind of thing a future
agent will helpfully expand.

Deletable and editable like every other tag (§2.1). Note there is no Roof,
Basement or Attic in the list — if the house has them, they are one tap to add,
but until then the seeded gutter-cleaning task has no location to point at.

---

## 3. Navigation — settled

**Bottom tabs:** Dashboard · Issues · Maintenance · Vendors
**Hamburger sheet:** Service History · Export data · Log out

Straight port of Personal CRM's `page_menu()` + `assets/menu.js`, which already
solved "four daily jobs in the bar, everything you do once in the sheet." Four
tabs is one more than any sibling ships and still comfortable at 48px on a
phone; five would truncate labels.

Service History lives in the sheet because it is almost always reached *from* an
issue or a vendor rather than browsed — but it remains a real URL, so nothing in
the menu is the only way to reach anything.

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
| **M1** | Tags & taxonomy | the picker UI, tag management screen, filter chips |
| **M2** | Media pipeline | `api/upload.php`, `api/worker.php`, `cron/process-queue.php`, docs path, the gallery/lightbox component |
| **M3** | Issues & timeline | `issues.php`, `issue.php`, the capture flow, timeline rendering, severity trend |
| **M4** | Maintenance & recurrence | `maintenance.php`, the recurrence engine, `task_complete()`, the seeded starter list |
| **M5** | Service history & vendors | `history.php`, `vendors.php`, `vendor.php`, cost/rating, auto-populated work history |
| **M6** | Dashboard & reminders | `index.php` (issues and maintenance as **separate sections**, per the brief), `tools/cron-reminders.php`, `public/cron.php`, the email template |
| **M7** | Integration & deploy | `DEPLOY.txt`, `README.md`, `CLAUDE.md`, export, full test pass |

M5 is one module and not two: a vendor's rating and work history are *derived
from* service records, so splitting them means building the same join twice.

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
                    check_interval_days NULL, last_checked_on DATE NULL,  -- §2.6
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
· export · the tag management screen (seeded lists are editable in SQL until
then) · `check_interval_days` (§2.6). **Nothing above the line** — the four
modules in the brief all ship.

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

Foundation can start.
