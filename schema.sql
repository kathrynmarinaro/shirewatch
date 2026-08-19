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
-- service_records.vendor_name below, which IS a string, deliberately, for the
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
-- ALL OF THESE ARE DELETABLE. Nothing in the code may treat any seeded tag as
-- special, the same rule as Grocery's four seeded stores.
INSERT IGNORE INTO tags (kind, property_id, name, sort_order) VALUES
  ('location', 1, 'Library',        10),
  ('location', 1, 'Dining Room',    20),
  ('location', 1, 'Entryway',       30),
  ('location', 1, 'Living Room',    40),
  ('location', 1, 'Emma Bathroom',  50),
  ('location', 1, 'Emma Room',      60),
  ('location', 1, 'Guest Room',     70),
  ('location', 1, 'Coat Closet',    80),
  ('location', 1, 'Main Bedroom',   90),
  ('location', 1, 'Main Bathroom', 100),
  ('location', 1, 'Sunroom',       110),
  ('location', 1, 'Breakfast Room',120),
  ('location', 1, 'Kitchen',       130),
  ('location', 1, 'Space Bathroom',140),
  ('location', 1, 'Laundry Room',  150),
  ('location', 1, 'Garage',        160),
  ('location', 1, 'Ext Studio',    170),
  ('location', 1, 'Yard',          180),
  ('location', 1, 'Roof',          190),
  ('location', 1, 'Septic Tank',   200),
  ('location', 1, 'Gutters',       210),
  ('location', 1, 'Chimney',       220),
  ('location', 1, 'Garage Attic',  230),
  ('location', 1, 'House Attic',   240);

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


-- ------------------------------------------------------------------ issues

-- The original core idea: log a potential problem and watch whether it moves.
CREATE TABLE IF NOT EXISTS issues (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id           INT UNSIGNED NOT NULL DEFAULT 1,

  -- RESERVED AND INERT (§2.4). No users table, no foreign key, nothing reads
  -- it. It exists so that adding shared household access later is a backfill
  -- of one column rather than an ALTER across six tables. This is the
  -- deliberate exception to the siblings' "don't leave hooks" rule, and the
  -- brief asks for it in as many words.
  user_id               INT UNSIGNED NULL,

  title                 VARCHAR(200) NOT NULL,
  description           TEXT         NULL,

  -- watching  : logged, being observed, no action decided (the core idea)
  -- active    : this needs doing
  -- resolved  : fixed
  -- dismissed : watched it, it was nothing — out of the list without
  --             pretending it was repaired (§2.5)
  status                ENUM('watching','active','resolved','dismissed')
                        NOT NULL DEFAULT 'watching',

  -- 1..4 = Watch / Minor / Major / Urgent. NULL MEANS NOT SET and renders as
  -- nothing. Zero is not a legal severity — same call Book Tracker makes for
  -- ratings, and for the same reason: unset and lowest are different facts,
  -- cheap to distinguish now and impossible to reconstruct later.
  --
  -- DENORMALIZED from the newest issue_updates row that carries one (§2.7).
  -- The dashboard sorts on it, and deriving it per row means a correlated
  -- subquery over a table that only grows.
  severity              TINYINT UNSIGNED NULL,

  noticed_on            DATE         NOT NULL,

  -- ---- the check-back loop (§2.6) --------------------------------------
  -- The rule, the last event, and the answer. The third is stored rather than
  -- computed BECAUSE it is what the dashboard queries: `last_checked_on +
  -- INTERVAL check_interval_days DAY` is a predicate no index can help with
  -- and one the SQLite harness cannot evaluate at all. Written in PHP on every
  -- check-in — the same discipline maintenance_tasks.next_due_on follows.
  --
  -- All three NULL together = an issue nobody has asked to be reminded about.
  check_interval_days   SMALLINT UNSIGNED NULL,
  last_checked_on       DATE         NULL,
  next_check_on         DATE         NULL,

  resolved_on           DATE         NULL,

  -- Which service record FIXED it. Optional and never required (§2.5): fix
  -- something yourself and the issue resolves with nothing attached.
  --
  -- SEPARATE FROM service_records.issue_id, which says "this work relates to
  -- this issue". Three visits can reference one issue while only one of them
  -- resolved it.
  --
  -- THIS ONE HAS NO FOREIGN KEY, AND THAT IS NOT AN OVERSIGHT. It would have
  -- to point forward at service_records, which is created further down this
  -- file, so the constraint could only be added by a trailing ALTER TABLE —
  -- and SQLite cannot add a foreign key by ALTER at all, so the test harness
  -- would be exercising a different schema from production. A rule enforced
  -- in MySQL and absent in the tests is worse than one enforced in PHP and
  -- tested, because only the second kind fails loudly when it breaks.
  --
  -- So record_delete() in lib/records.php clears this column, and a test
  -- covers it. Nothing else may delete a service_records row.
  resolved_by_record_id INT UNSIGNED NULL,

  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_status (status),
  -- Carries the dashboard's overdue-check branch AND §2.13's forward timeline.
  -- Do not drop this as redundant with idx_status; the timeline query does not
  -- filter on status first.
  KEY idx_next_check (next_check_on),
  CONSTRAINT fk_issues_property FOREIGN KEY (property_id) REFERENCES properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------- issue_updates

-- The timeline. NOTHING HERE IS EVER OVERWRITTEN — that is the whole feature.
-- Each check-in is a new row with its own date, note and photos, so the
-- progression is visible instead of being replaced by its latest state.
CREATE TABLE IF NOT EXISTS issue_updates (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id   INT UNSIGNED NOT NULL,

  noted_on   DATE         NOT NULL,
  note       TEXT         NULL,

  -- Optional. Set it and "stable vs. worsening" becomes data you can render as
  -- a trend beside the photos, instead of prose buried in a note (§2.7).
  -- Writing this is what updates issues.severity.
  severity   TINYINT UNSIGNED NULL,

  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_issue_date (issue_id, noted_on),
  CONSTRAINT fk_updates_issue FOREIGN KEY (issue_id)
    REFERENCES issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------- maintenance_tasks

-- Recurring work, with two recurrence anchors because one is wrong for half
-- the list (§2.2).
CREATE TABLE IF NOT EXISTS maintenance_tasks (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id       INT UNSIGNED NOT NULL DEFAULT 1,
  user_id           INT UNSIGNED NULL,          -- reserved, see issues.user_id

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
CREATE TABLE IF NOT EXISTS task_completions (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id           INT UNSIGNED NOT NULL,
  completed_on      DATE         NOT NULL,
  note              TEXT         NULL,

  -- Set when the completion had a bill attached. SET NULL, not CASCADE:
  -- deleting the invoice must not delete the fact that you did the job.
  service_record_id INT UNSIGNED NULL,

  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_task_date (task_id, completed_on),
  CONSTRAINT fk_completions_task FOREIGN KEY (task_id)
    REFERENCES maintenance_tasks(id) ON DELETE CASCADE
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


-- --------------------------------------------------------- service_records

CREATE TABLE IF NOT EXISTS service_records (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id  INT UNSIGNED NOT NULL DEFAULT 1,
  user_id      INT UNSIGNED NULL,              -- reserved, see issues.user_id

  -- SET NULL, never CASCADE. Deleting a vendor MUST NOT delete your repair
  -- history — that history is the thing the app is for.
  vendor_id    INT UNSIGNED NULL,

  -- A SNAPSHOT STRING, and the one place in this schema where a name is
  -- stored rather than joined (§2.10). It is the deliberate opposite of the
  -- tags table's foreign key, ten tables up: a room that gets renamed is the
  -- same room and should follow, but a vendor that gets deleted is gone and
  -- the 2023 invoice still has to say who sent it. Written on insert from
  -- vendors.name; not kept in sync afterwards, because a rename there means
  -- "they changed their name", not "this invoice came from someone else".
  vendor_name  VARCHAR(160) NULL,

  title        VARCHAR(200) NOT NULL,
  description  TEXT         NULL,
  performed_on DATE         NOT NULL,

  -- NULL = not recorded, which is different from free. DECIMAL, never FLOAT:
  -- money in binary floating point does not add up.
  cost         DECIMAL(10,2) NULL,

  -- 1..5 for THIS JOB. NULL = unrated. vendors' overall rating is the average
  -- of these. Same NULL-means-unset rule as issues.severity.
  rating       TINYINT UNSIGNED NULL,

  -- "This work relates to that issue/task." Both optional, both SET NULL.
  -- See issues.resolved_by_record_id for why the issue link is two columns
  -- pointing opposite ways rather than one.
  issue_id     INT UNSIGNED NULL,
  task_id      INT UNSIGNED NULL,

  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_performed (performed_on),
  KEY idx_vendor (vendor_id),
  KEY idx_issue (issue_id),
  CONSTRAINT fk_records_property FOREIGN KEY (property_id) REFERENCES properties(id),
  CONSTRAINT fk_records_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
  CONSTRAINT fk_records_issue    FOREIGN KEY (issue_id)  REFERENCES issues(id)  ON DELETE SET NULL,
  CONSTRAINT fk_records_task     FOREIGN KEY (task_id)   REFERENCES maintenance_tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------- tag linkage

-- Four join tables, one per taggable thing (§2.1). All the same shape.
--
-- NOT one polymorphic taggables(item_type, item_id, tag_id) table. That form
-- cannot express a foreign key — the database could not cascade a deleted
-- issue's tag rows away, so orphan rows would accumulate silently and every
-- read would need a defensive join. Four small tables buy real cascades in
-- both directions: delete an issue and its tag links go; delete a tag and it
-- lifts off every item cleanly.

CREATE TABLE IF NOT EXISTS issue_tags (
  issue_id INT UNSIGNED NOT NULL,
  tag_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (issue_id, tag_id),
  KEY idx_tag (tag_id),
  CONSTRAINT fk_it_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  CONSTRAINT fk_it_tag   FOREIGN KEY (tag_id)   REFERENCES tags(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_tags (
  task_id INT UNSIGNED NOT NULL,
  tag_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (task_id, tag_id),
  KEY idx_tag (tag_id),
  CONSTRAINT fk_tt_task FOREIGN KEY (task_id) REFERENCES maintenance_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_tag  FOREIGN KEY (tag_id)  REFERENCES tags(id)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS record_tags (
  record_id INT UNSIGNED NOT NULL,
  tag_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (record_id, tag_id),
  KEY idx_tag (tag_id),
  CONSTRAINT fk_rt_record FOREIGN KEY (record_id) REFERENCES service_records(id) ON DELETE CASCADE,
  CONSTRAINT fk_rt_tag    FOREIGN KEY (tag_id)    REFERENCES tags(id)            ON DELETE CASCADE
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
  -- deleting an issue would leave its photos behind as rows pointing at
  -- nothing, and the files behind them would never be reaped. Two spare
  -- columns per row buys a real ON DELETE CASCADE.
  issue_id          INT UNSIGNED NULL,   -- the issue's opening photo
  issue_update_id   INT UNSIGNED NULL,   -- one check-in's photo
  service_record_id INT UNSIGNED NULL,   -- batch: photos AND documents

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
  KEY idx_issue (issue_id),
  KEY idx_update (issue_update_id),
  KEY idx_record (service_record_id),
  KEY idx_status (status, locked_at),
  CONSTRAINT fk_media_issue  FOREIGN KEY (issue_id)          REFERENCES issues(id)          ON DELETE CASCADE,
  CONSTRAINT fk_media_update FOREIGN KEY (issue_update_id)   REFERENCES issue_updates(id)   ON DELETE CASCADE,
  CONSTRAINT fk_media_record FOREIGN KEY (service_record_id) REFERENCES service_records(id) ON DELETE CASCADE
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
