-- Shirewatch — schema
-- Load with:  mysql -u root shirewatch < schema.sql
--
-- FROZEN CONTRACT. The module passes are built against this; see
-- docs/DELEGATION-PLAN.md §2 before changing anything here. Every decision
-- below was made deliberately and the reasoning is recorded in the plan — the
-- section references in these comments point at it.
--
-- TWO RULES THAT SHAPE HALF THIS FILE, both ported from the siblings:
--
--   1. EVERY DATE THIS APP SCHEDULES ON IS A STORED `DATE` COLUMN, computed in
--      PHP and written on change. There is no DATE_ADD, no INTERVAL and no
--      NOW() in any query against them. That keeps every due query a sargable
--      `WHERE col <= ?`, keeps it identical on MySQL and on the SQLite that
--      tools/test-harness.php runs the tests against, and keeps one clock in
--      one place (Personal CRM's lib/dates.php header argues this at length).
--
--   2. FAIL SOFT. A malformed row, an unknown tag or a missing photo degrades
--      that one row and never 500s a screen.
--
-- THE LOG ABSORBED SERVICE RECORDS. `issues` and `issue_updates` are now
-- `log_entries` and `log_updates`, and the old `service_records` table is gone
-- — a service call is an update of kind 'service' on a log entry, and routine
-- paid work is a task_completion that carries a cost. tools/migrate-to-log.php
-- moves an existing install; docs/DELEGATION-PLAN.md §2.15 has the reasoning.
--
-- MySQL/MariaDB is the production target and the only one.
-- tools/test-harness.php translates this file into SQLite because the build
-- environment has no MySQL; a green run says the schema is COHERENT, not that
-- MySQL accepts it. Never edit this file to make the translator's job easier —
-- teach the translator.


-- -------------------------------------------------------------- properties

-- ONE ROW TODAY, AND THAT IS THE POINT (§2.4).
--
-- The brief asks for multi-property to be anticipated but not built. A real
-- table with a real foreign key from everything else means adding a second
-- house later is an INSERT — not a migration that has to backfill a column and
-- add constraints to six tables at once, on live data, by hand.
--
-- Nothing in the UI offers to create a second one. lib/bootstrap.php resolves
-- the single current property once per request.
CREATE TABLE IF NOT EXISTS properties (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  address    VARCHAR(255) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO properties (id, name) VALUES (1, 'Home');


-- -------------------------------------------------------------------- tags

-- ONE TABLE FOR THREE VOCABULARIES (§2.1), not three tables and six joins.
--
-- Locations ("Kitchen", "Roof"), categories ("Plumbing") and vendor work types
-- ("Plumber") are the same shape: a short editable list of names you pick
-- several of. One table means one picker component, built once, instead of
-- three that drift apart.
--
-- RENAMING IS THE WHOLE REASON THIS IS A FOREIGN KEY AND NOT A STRING.
-- Everything links to tags.id, so `UPDATE tags SET name = ?` is the entire
-- rename and every issue already filed under the old name follows it. Compare
-- log_updates.vendor_name below, which IS a string, deliberately, for the
-- opposite reason. A room that gets renamed is the same room; a vendor that
-- gets deleted is gone and the invoice still has to say who sent it.
--
-- property_id IS NULL FOR CATEGORIES AND WORK TYPES, and that asymmetry is the
-- one awkward thing in this table. Rooms differ per house, so locations are
-- scoped. Plumbing is plumbing everywhere, so systems and trades are not. The
-- alternative — scoping all three — would duplicate the same twelve category
-- rows for every property added.
CREATE TABLE IF NOT EXISTS tags (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  kind        ENUM('location','category','work_type') NOT NULL,

  -- Stored EXACTLY as typed, and never normalized (§2.12). "Ext Studio" is not
  -- expanded to "Exterior Studio"; nothing title-cases, trims to a canonical
  -- form, or de-duplicates on a normalized key. These are the names the house
  -- is called by, and a later pass tidying them destroys the recognition that
  -- makes a filter list scannable. Same rule as Grocery's grocery_items.name.
  name        VARCHAR(80)  NOT NULL,

  -- NULL for category and work_type. See the note above.
  property_id INT UNSIGNED NULL,

  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 100,

  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- What makes the seed INSERTs below re-runnable, and what stops two
  -- "Kitchen" rows appearing from a double-tapped Add button.
  UNIQUE KEY uniq_kind_prop_name (kind, property_id, name),
  KEY idx_kind_sort (kind, sort_order),

  CONSTRAINT fk_tags_property FOREIGN KEY (property_id)
    REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kathryn's actual rooms, in the order she listed them, spelled her way
-- (§2.12). The last six are not rooms — a roof leak logs against Roof, not
-- against the room whose ceiling it came through — and they are what give the
-- seeded gutter and chimney tasks somewhere to point.
--
-- ALPHABETICAL, not the order they were dictated in. That order was off the
-- top of the author's head rather than a considered sequence, and scanning two
-- dozen rooms for one name is much easier alphabetically. sort_order still
-- drives the list everywhere, so the drag handles on the Rooms & Tags screen
-- keep working — this just makes alphabetical the starting point.
-- tools/sort-tags-alphabetically.php restores it on an existing install.
--
-- ALL OF THESE ARE DELETABLE. Nothing in the code may treat any seeded tag as
-- special, the same rule as Grocery's four seeded stores.
INSERT IGNORE INTO tags (kind, property_id, name, sort_order) VALUES
  ('location', 1, 'Breakfast Room',  10),
  ('location', 1, 'Chimney',        20),
  ('location', 1, 'Coat Closet',    30),
  ('location', 1, 'Dining Room',    40),
  ('location', 1, 'Emma Bathroom',  50),
  ('location', 1, 'Emma Room',      60),
  ('location', 1, 'Entryway',       70),
  ('location', 1, 'Ext Studio',     80),
  ('location', 1, 'Garage',         90),
  ('location', 1, 'Garage Attic',  100),
  ('location', 1, 'Guest Room',    110),
  ('location', 1, 'Gutters',       120),
  ('location', 1, 'House Attic',   130),
  ('location', 1, 'Kitchen',       140),
  ('location', 1, 'Laundry Room',  150),
  ('location', 1, 'Library',       160),
  ('location', 1, 'Living Room',   170),
  ('location', 1, 'Main Bathroom', 180),
  ('location', 1, 'Main Bedroom',  190),
  ('location', 1, 'Roof',          200),
  ('location', 1, 'Septic Tank',   210),
  ('location', 1, 'Space Bathroom', 220),
  ('location', 1, 'Sunroom',       230),
  ('location', 1, 'Yard',          240);

-- Systems (§2.14). "Roofing" the category and "Roof" the location are not
-- duplicates: the category answers what kind of problem, the location answers
-- where. A soffit issue is Exterior + Roof.
INSERT IGNORE INTO tags (kind, property_id, name, sort_order) VALUES
  ('category', NULL, 'Plumbing',    10),
  ('category', NULL, 'Electrical',  20),
  ('category', NULL, 'HVAC',        30),
  ('category', NULL, 'Roofing',     40),
  ('category', NULL, 'Structural',  50),
  ('category', NULL, 'Exterior',    60),
  ('category', NULL, 'Interior',    70),
  ('category', NULL, 'Appliances',  80),
  ('category', NULL, 'Landscaping', 90),
  ('category', NULL, 'Pest',       100),
  ('category', NULL, 'Safety',     110),
  ('category', NULL, 'Septic',     120);

INSERT IGNORE INTO tags (kind, property_id, name, sort_order) VALUES
  ('work_type', NULL, 'Plumber',            10),
  ('work_type', NULL, 'Electrician',        20),
  ('work_type', NULL, 'HVAC',               30),
  ('work_type', NULL, 'Roofer',             40),
  ('work_type', NULL, 'General Contractor', 50),
  ('work_type', NULL, 'Handyman',           60),
  ('work_type', NULL, 'Landscaper',         70),
  ('work_type', NULL, 'Pest Control',       80),
  ('work_type', NULL, 'Appliance Repair',   90),
  ('work_type', NULL, 'Septic Service',    100),
  ('work_type', NULL, 'Chimney Sweep',     110),
  ('work_type', NULL, 'Painter',           120);

-- NOTE ON ORDERING: vendors is created BEFORE log_updates because that
-- table has a foreign key to it, and MySQL will not accept a forward
-- reference. Moving it back down breaks a fresh install, loudly.

-- ----------------------------------------------------------------- vendors

CREATE TABLE IF NOT EXISTS vendors (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id     INT UNSIGNED NOT NULL DEFAULT 1,

  name            VARCHAR(160) NOT NULL,
  phone           VARCHAR(40)  NULL,
  email           VARCHAR(255) NULL,
  notes           TEXT         NULL,

  -- NULL = use the average of this vendor's rated service records.
  -- Non-NULL = "I've decided", and the average is shown beside it rather than
  -- replaced, so the override never hides what it is overriding.
  --
  -- There is no stored average. It is AVG() at read time over a handful of
  -- rows per vendor; caching it would be a denormalization with a sync bug
  -- attached and nothing to buy with it.
  rating_override TINYINT UNSIGNED NULL,

  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_name (name),
  CONSTRAINT fk_vendors_property FOREIGN KEY (property_id) REFERENCES properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ------------------------------------------------------------- log_entries

-- THE LOG. One table for everything that happens to the house that was not
-- scheduled: a problem you are watching, a problem you are fixing, and the
-- record of it once it is fixed.
--
-- THIS USED TO BE TWO TABLES — `issues` and `service_records` — and merging
-- them is the single biggest correction in this schema's history. They were
-- siblings, and they are not: an issue and the service call that resolves it
-- are ONE STORY at two moments. The author found this out when her AC failed.
-- It was not "an issue" and then, separately, "a service record". It was:
-- noticed it was not cooling, called somebody, they came and replaced a
-- capacitor for $340, it worked again. Four entries in one timeline.
--
-- So a service call is now a KIND OF UPDATE (see log_updates), not a kind of
-- row up here. And routine paid work — the annual HVAC service, the septic
-- pump-out — is not a log entry at all: it is a maintenance completion that
-- cost money, which is why task_completions carries the same vendor/cost/
-- rating columns.
CREATE TABLE IF NOT EXISTS log_entries (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id           INT UNSIGNED NOT NULL DEFAULT 1,

  -- RESERVED AND INERT. No users table, no foreign key, nothing reads it. It
  -- exists so shared household access later is a backfill of one column
  -- rather than an ALTER across six tables.
  user_id               INT UNSIGNED NULL,

  title                 VARCHAR(200) NOT NULL,
  description           TEXT         NULL,

  -- watching  : logged, being observed, no action decided
  -- active    : this needs doing
  -- resolved  : fixed
  -- dismissed : watched it, it was nothing — out of the list without
  --             pretending it was repaired
  status                ENUM('watching','active','resolved','dismissed')
                        NOT NULL DEFAULT 'watching',

  -- 1..4 = Watch / Minor / Major / Urgent. NULL MEANS NOT SET and renders as
  -- nothing. Zero is not a legal severity. Denormalized from the newest
  -- update that carried one; lib/log.php is the only writer.
  severity              TINYINT UNSIGNED NULL,

  noticed_on            DATE         NOT NULL,

  -- ---- the check-back loop ---------------------------------------------
  -- The rule, the last event, and the answer. The third is STORED because it
  -- is what the dashboard queries: `last_checked_on + INTERVAL n DAY` is a
  -- predicate no index can help with and one the SQLite harness cannot
  -- evaluate at all. Written in PHP on every check-in.
  check_interval_days   SMALLINT UNSIGNED NULL,
  last_checked_on       DATE         NULL,
  next_check_on         DATE         NULL,

  resolved_on           DATE         NULL,

  -- WHICH UPDATE FIXED IT. Optional and never required — fix something
  -- yourself and it resolves with nothing attached.
  --
  -- No foreign key, deliberately: it points forward at log_updates, created
  -- below, and SQLite cannot add one by ALTER, so a database-enforced version
  -- would be absent from the tests. lib/log.php clears it on delete instead,
  -- and a test covers that.
  resolved_by_update_id INT UNSIGNED NULL,

  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_status (status),
  -- Carries the dashboard's overdue-check branch AND the forward timeline.
  KEY idx_next_check (next_check_on),
  CONSTRAINT fk_entries_property FOREIGN KEY (property_id) REFERENCES properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------- log_updates

-- The timeline. NOTHING HERE IS EVER OVERWRITTEN — that is the whole feature.
-- Each check-in is a new row with its own date, note and photos, so the
-- progression is visible instead of being replaced by its latest state.
--
-- TWO KINDS, AND THAT IS WHAT ABSORBED THE OLD service_records TABLE:
--
--   note     something you observed. date, text, optionally a new severity.
--   service  somebody was paid to do something. The same date and text, plus
--            a vendor, a cost and a rating for that visit.
--
-- A service is an EVENT IN THE STORY rather than a separate record of one.
-- Three visits can appear on one entry while only one of them resolved it —
-- which is exactly what log_entries.resolved_by_update_id points at.
CREATE TABLE IF NOT EXISTS log_updates (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id     INT UNSIGNED NOT NULL,

  kind         ENUM('note','service') NOT NULL DEFAULT 'note',

  noted_on     DATE         NOT NULL,
  note         TEXT         NULL,

  -- Optional, and only meaningful on a note. Set it and "stable vs. worsening"
  -- becomes data you can render as a trend. Writing this is what updates
  -- log_entries.severity.
  severity     TINYINT UNSIGNED NULL,

  -- ---- service columns, all NULL on a note -----------------------------

  -- SET NULL, never CASCADE. Deleting a vendor MUST NOT delete the record of
  -- work they did — that history is the thing the app is for.
  vendor_id    INT UNSIGNED NULL,

  -- A SNAPSHOT STRING, and the one place a name is stored rather than joined.
  -- The deliberate opposite of the tags table's foreign key: a room that gets
  -- renamed is the same room, but a vendor that gets deleted is gone and the
  -- 2023 invoice still has to say who sent it. Written on insert; never
  -- synced afterwards.
  vendor_name  VARCHAR(160) NULL,

  -- NULL = not recorded, which is different from free. DECIMAL, never FLOAT:
  -- money in binary floating point does not add up.
  cost         DECIMAL(10,2) NULL,

  -- 1..5 for THIS VISIT. NULL = unrated. A vendor's overall rating is the
  -- average of these and of task_completions.rating.
  rating       TINYINT UNSIGNED NULL,

  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_entry_date (entry_id, noted_on),
  -- The Service view range-scans this: every paid visit, newest first.
  KEY idx_service (kind, noted_on),
  KEY idx_vendor (vendor_id),
  CONSTRAINT fk_updates_entry  FOREIGN KEY (entry_id)  REFERENCES log_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_updates_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- ------------------------------------------------------- maintenance_tasks

-- Recurring work, with two recurrence anchors because one is wrong for half
-- the list (§2.2).
CREATE TABLE IF NOT EXISTS maintenance_tasks (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id       INT UNSIGNED NOT NULL DEFAULT 1,
  user_id           INT UNSIGNED NULL,          -- reserved, see log_entries.user_id

  title             VARCHAR(200) NOT NULL,
  instructions      TEXT         NULL,

  -- 'interval' : every N days/weeks/months/years. Wear-based.
  -- 'months'   : specific months of the year. Seasonal.
  recur_kind        ENUM('interval','months') NOT NULL DEFAULT 'interval',

  interval_count    SMALLINT UNSIGNED NULL,
  interval_unit     ENUM('day','week','month','year') NULL,

  -- 'completion' : next due = when you actually did it + the interval. Right
  --                for wear — the filter's clock started when you changed it.
  -- 'due'        : next due = the scheduled date + the interval, regardless.
  interval_from     ENUM('completion','due') NOT NULL DEFAULT 'completion',

  -- recur_kind='months': a comma-separated month list, "3,10" = March and
  -- October. A VARCHAR rather than MySQL's SET type because SET does not
  -- survive the SQLite translation, and this column is only ever parsed in
  -- PHP — no query filters on it.
  recur_months      VARCHAR(35)  NULL,
  recur_day         TINYINT UNSIGNED NULL,      -- day of month, default 1

  -- THE SCHEDULING COLUMN. Computed in PHP, written on completion and on edit,
  -- never derived in a query.
  --
  -- IT DOES NOT MOVE WHEN A REMINDER IS SENT (§2.3, ported from Personal CRM).
  -- task_reminder_sends is what stops the duplicate email; this moves only in
  -- task_complete(). So an overdue task emails once, then sits on the
  -- dashboard getting louder, and the app does not quietly forgive you.
  --
  -- The consequence, which looks like a bug and is not: skip March's gutters
  -- entirely and this stays at March saying "5 months overdue" rather than
  -- rolling silently to October. That is the true statement, and two overdue
  -- gutter rows would not be.
  next_due_on       DATE         NOT NULL,

  last_completed_on DATE         NULL,

  -- Soft-off, so a task you don't want this year keeps its completion history
  -- instead of being deleted for it.
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,

  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- Carries the cron's due query and §2.13's forward timeline. Load-bearing.
  KEY idx_due (next_due_on),
  KEY idx_active (is_active),
  CONSTRAINT fk_tasks_property FOREIGN KEY (property_id) REFERENCES properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- task_completions

-- "When did I last flush the water heater" — answerable, and it survives the
-- task being edited. next_due_on only ever holds ONE date; this is the record.
--
-- IT CARRIES THE SERVICE COLUMNS TOO, and that is the other half of merging
-- away service_records. Routine paid work — the annual HVAC service, the
-- septic pump-out — is NOT a problem that got fixed. It is a scheduled job
-- that happened and cost money. Filing it as a log entry would mean inventing
-- a problem that never existed.
--
-- So "service" is not a kind of thing in this schema. It is something that can
-- happen in two places: to a log entry (unplanned) or to a maintenance
-- completion (planned). The Service view is the union of the two, and it is
-- the only code that needs to know there are two.
CREATE TABLE IF NOT EXISTS task_completions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id      INT UNSIGNED NOT NULL,
  completed_on DATE         NOT NULL,
  note         TEXT         NULL,

  -- ---- service columns, all NULL when you did it yourself --------------
  -- Same shapes and same rules as log_updates: SET NULL on the vendor, a
  -- snapshot name that outlives them, NULL cost meaning "not recorded"
  -- rather than free, and 1..5 with NULL for unrated.
  vendor_id    INT UNSIGNED NULL,
  vendor_name  VARCHAR(160) NULL,
  cost         DECIMAL(10,2) NULL,
  rating       TINYINT UNSIGNED NULL,

  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_task_date (task_id, completed_on),
  -- The Service view's second source: paid completions, newest first.
  KEY idx_paid (completed_on),
  KEY idx_vendor (vendor_id),
  CONSTRAINT fk_completions_task   FOREIGN KEY (task_id)   REFERENCES maintenance_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_completions_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- ---------------------------------------------------- task_reminder_sends

-- SEND IDEMPOTENCY, and a send history for free. Ported wholesale from
-- Personal CRM's reminder_sends, whose header is the argument for it.
--
-- WHY IT EXISTS: nothing in the brief says what happens when the cron runs
-- twice, or when it sends and then dies. Both are ordinary — Hostinger's
-- scheduler can double-fire and SMTP can hang. Without this table the failure
-- mode is the same filter-change email eight times, which is how you stop
-- reading the reminders, at which point the app has no purpose.
--
-- (task_id, due_on) IS THE PRIMARY KEY, not a unique index beside a surrogate
-- one. The claim is an INSERT ... ON DUPLICATE KEY UPDATE, so the database
-- itself makes a second row impossible rather than the application promising
-- not to write one.
--
-- A FAILED ATTEMPT LEAVES sent_at NULL SO TOMORROW RETRIES. A hung SMTP
-- connection must not cost you the reminder. Only a delivered row is refused.
CREATE TABLE IF NOT EXISTS task_reminder_sends (
  task_id    INT UNSIGNED NOT NULL,

  -- The next_due_on value this send was FOR — not the date it went out. That
  -- is what makes an overdue task, whose due date does not move, claim the
  -- same pair tomorrow and stay quiet (§2.3).
  due_on     DATE         NOT NULL,

  attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at    DATETIME     NULL,

  -- Truncated diagnostic beside the row. The full text goes to error_log.
  last_error VARCHAR(255) NULL,

  PRIMARY KEY (task_id, due_on),
  CONSTRAINT fk_sends_task FOREIGN KEY (task_id)
    REFERENCES maintenance_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS entry_tags (
  entry_id INT UNSIGNED NOT NULL,
  tag_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (entry_id, tag_id),
  KEY idx_tag (tag_id),
  CONSTRAINT fk_et_entry FOREIGN KEY (entry_id) REFERENCES log_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_et_tag   FOREIGN KEY (tag_id)   REFERENCES tags(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS task_tags (
  task_id INT UNSIGNED NOT NULL,
  tag_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (task_id, tag_id),
  KEY idx_tag (tag_id),
  CONSTRAINT fk_tt_task FOREIGN KEY (task_id) REFERENCES maintenance_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_tag  FOREIGN KEY (tag_id)  REFERENCES tags(id)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_tags (
  vendor_id INT UNSIGNED NOT NULL,
  tag_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (vendor_id, tag_id),
  KEY idx_tag (tag_id),
  CONSTRAINT fk_vt_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  CONSTRAINT fk_vt_tag    FOREIGN KEY (tag_id)    REFERENCES tags(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------------- media

-- Photos and documents, plus the processing queue they move through (§2.8).
CREATE TABLE IF NOT EXISTS media (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- 'photo'    : sniffed as an image, derived into thumb + detail WebP
  -- 'document' : a PDF. Stored and served, NEVER derived (§2.9)
  kind              ENUM('photo','document') NOT NULL DEFAULT 'photo',

  -- ---- ownership: THREE NULLABLE FOREIGN KEYS, EXACTLY ONE SET ----------
  -- Not a polymorphic (owner_type, owner_id) pair, for the same reason the
  -- tag joins are not: polymorphic ownership cannot be a foreign key, so
  -- deleting a log entry would leave its photos behind as rows pointing at
  -- nothing, and the files behind them would never be reaped. Two spare
  -- columns per row buys a real ON DELETE CASCADE.
  --
  -- completion_id is what lets an INVOICE hang off a maintenance completion.
  -- The receipt for the annual HVAC service belongs beside the completion it
  -- paid for, not beside a log entry that never existed.
  entry_id          INT UNSIGNED NULL,   -- the log entry's opening photo
  update_id         INT UNSIGNED NULL,   -- one update's photos and invoice
  completion_id     INT UNSIGNED NULL,   -- a maintenance completion's receipt

  -- Generated, name-independent basename (16 hex chars). The uploaded
  -- filename never becomes a path — see lib/imageproc.php.
  slug              VARCHAR(32)  NOT NULL,
  ext               VARCHAR(5)   NOT NULL,

  -- All public-relative, e.g. "uploads/thumb/ab12.webp". NULL until the queue
  -- has produced them; a document has only original_path.
  --
  -- ORIGINALS ARE KEPT, NOT REAPED — the deliberate divergence from the
  -- Inspiration Gallery, which deletes them after deriving (§2.8). Its images
  -- are references; these are evidence. A re-encoded 1600px WebP is not what
  -- you hand an insurance adjuster to show that a crack got wider.
  original_path     VARCHAR(255) NULL,
  thumb_path        VARCHAR(255) NULL,
  detail_path       VARCHAR(255) NULL,

  -- What the phone called it. For DISPLAY ONLY — it is never a path, never
  -- trusted, and never used to decide a type.
  original_filename VARCHAR(255) NULL,
  mime              VARCHAR(80)  NULL,
  bytes             INT UNSIGNED NULL,
  width             SMALLINT UNSIGNED NULL,
  height            SMALLINT UNSIGNED NULL,

  caption           VARCHAR(255) NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 100,

  -- ---- processing queue, ported from the Gallery -----------------------
  -- pending -> processing -> ready, or failed after MAX_ATTEMPTS. The row and
  -- its original are kept on failure so a retry is possible and nothing is
  -- lost. Documents are inserted straight to 'ready'; there is nothing to do.
  status            ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error        TEXT         NULL,
  locked_at         DATETIME     NULL,

  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_entry (entry_id),
  KEY idx_update (update_id),
  KEY idx_completion (completion_id),
  KEY idx_status (status, locked_at),
  CONSTRAINT fk_media_entry      FOREIGN KEY (entry_id)      REFERENCES log_entries(id)      ON DELETE CASCADE,
  CONSTRAINT fk_media_update     FOREIGN KEY (update_id)     REFERENCES log_updates(id)      ON DELETE CASCADE,
  CONSTRAINT fk_media_completion FOREIGN KEY (completion_id) REFERENCES task_completions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------- login_attempts

-- Login throttling. One row per attempt, pruned opportunistically.
-- Ported unchanged from the siblings; lib/auth.php is the argument for it.
--
-- Session-based counting would be useless: an attacker discards the cookie.
-- This has to be keyed to the client address, server-side.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)  NOT NULL,          -- 45 chars covers IPv6
  succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
