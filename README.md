# Shirewatch

A house maintenance app. Log a potential problem, photograph it, and watch
over time whether it's stable or getting worse — plus the scheduled
maintenance you keep meaning to do, a record of what you paid whom, and who
to call.

PHP + MySQL, mobile-first, no build step, no app-store install. Fifth app in
the suite, sharing its auth, design tokens and page chrome with the
Inspiration Gallery, Book Tracker, Grocery and the Personal CRM.

---

## The four things it does

**The log** — the original idea. Each entry carries a timeline, and every
update on it adds a photo, a note and a date. Nothing is ever overwritten, so
the progression is visible.

An update is one of two kinds. A **note** is something you observed ("wider
than the pencil mark now"). A **service** is somebody being paid ("Blue Ridge
HVAC, $412, replaced the capacitor"). They sit on the same timeline in the
order they happened, because a problem and the visit that fixed it are one
story, not two records to cross-reference.

An entry can carry a check-back interval, which is what puts "look at the
basement crack again" on the dashboard instead of leaving it to memory.

**Maintenance** — recurring tasks in two shapes: every N days/weeks/months
for wear-based work, and specific months of the year for seasonal work that
must not drift. Reminders by email and on the dashboard.

A completion can carry a vendor and a cost, because a paid routine service —
the annual HVAC visit, the septic pump-out — is maintenance that cost money,
not a problem you had to log.

**Service** (in the menu) — every visit somebody was paid for, from both
places one can happen, with a total per year. It is a view rather than a
table: nothing is filed here, it is assembled from the log and from
maintenance.

**Vendors** — contacts with work-type tags, rated per job, with a work
history that fills itself in from both sides.

Everything is tagged by location and by system, and filterable by either.

---

## Files

```
config.php            Your secrets. Gitignored. Copy from config.example.php.
schema.sql            The 13-table contract. Read the comments.

lib/                  Server code, NOT web-accessible.
  bootstrap.php         Config, JSON helpers, app_name()
  db.php                PDO connection
  auth.php              Password session auth + login throttling
  dates.php             The one clock, and the recurrence engine
  layout.php            Page chrome, tab bar, hamburger
  tags.php              Locations, systems, work types
  log.php               Log entries and their updates
  tasks.php             Maintenance and the schedule
  service.php           The union of the two places a paid visit lives
  vendors.php           The directory and the computed ratings
  dashboard.php         Needs-action and the forward timeline
  render.php            Shared output helpers, all escaping
  media.php             Photo/document storage and the processing queue
  imageproc.php         Sniffing, HEIC, EXIF, WebP derivation
  mailer.php            Reminder email over SMTP
  vendor/PHPMailer/     Three plain files. Keep them byte-identical.

public/               The web root.
  index.php             Dashboard
  log.php  entry.php     The log, and one entry with its timeline
  maintenance.php  task.php
  service.php           Every paid visit, from both sources
  vendors.php  vendor.php
  tags.php              Rooms, systems and work types
  login.php  logout.php
  api/                  JSON endpoints
  assets/               styles.css + hand-written ES modules
  uploads/              original/ thumb/ detail/ docs/

cron/process-queue.php  Photo queue safety net
tools/                  Setup, tests and dev scripts — see DEPLOY.txt
data/starter-tasks.php  The generic starter maintenance list
docs/CONTRACTS.md       Module interface contracts — read before coding
docs/DELEGATION-PLAN.md Why every decision was made
```

---

## Deploying

See **DEPLOY.txt**. The three steps people skip, in order of how much they
cost:

1. **Raise PHP's upload limits.** The 2 MB default rejects every real phone
   photo before the app sees it.
2. **`php tools/send-test-email.php`** before trusting a cron you cannot
   watch run. A misconfigured From address fails *silently*.
3. **Upload all five `.htaccess` files.** File managers hide dotfiles.

---

## Running locally

```bash
brew install mariadb && brew services start mariadb
mariadb -e "CREATE DATABASE shirewatch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER 'shirewatch'@'localhost' IDENTIFIED BY 'localdev';
            GRANT ALL ON shirewatch.* TO 'shirewatch'@'localhost';"
mariadb -u shirewatch -plocaldev shirewatch < schema.sql
cp config.example.php config.php   # then fill it in

php -S 127.0.0.1:8788 -t public \
  -d upload_max_filesize=25M -d post_max_size=128M -d max_file_uploads=40
```

The `-d` flags matter: PHP's 2 MB default would reject any real photo.

Seed a plausible house — log entries in all four states, a three-year
timeline on one of them, service visits interleaved with notes, tasks overdue
by varying amounts, paid and unpaid completions, vendors with and without
ratings:

```bash
php tools/seed.php --reset
```

Run the tests. They need no database and no config of their own:

```bash
php tools/run-tests.php
```

**Run the recurrence section first when a date looks wrong.**
`recur_next_after()` is the only function in the app that can be wrong about
a due date, and a schedule that drifts is invisible until a season has
already been missed.

---

## Backups

**Menu → Export data** downloads the database. Do it periodically.

Separately, back up `public/uploads/original/`. Those full-resolution files
are kept deliberately and are regenerated from nothing — they are the record
of what the house looked like on the day you photographed it, which is the
entire point of the app. Losing that directory is not recoverable.
