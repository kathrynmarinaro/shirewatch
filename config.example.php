<?php
/* =====================================================================
 * Shirewatch — configuration
 * ---------------------------------------------------------------------
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored and must never be committed.
 *
 * On Hostinger this file lives ONE LEVEL ABOVE your web root, next to
 * lib/ — so it can never be served over the web even if PHP is off.
 * The root .htaccess denies it a second time, belt and braces.
 * ===================================================================== */

return array(

    /* ---- branding ---------------------------------------------------
     * THE APP'S NAME IS A CONFIG VALUE, unlike every sibling app, which
     * hardcodes it. The brief asks for this one to be renameable in a
     * single line for a possible public release, so <title>, the login
     * card, the page chrome and the reminder emails all read it from
     * here via app_name().
     *
     * Changing it is safe at any time. Nothing is stored under it and
     * no URL contains it.
     */
    'app_name' => 'Shirewatch',

    /* ---- database ---------------------------------------------------
     * Create the DB in hPanel, then paste those values here.
     * Host is usually 'localhost'.
     */
    'db' => array(
        'host'    => 'localhost',
        'name'    => 'shirewatch',
        'user'    => 'CHANGE_ME',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ),

    /* ---- the clock --------------------------------------------------
     * READ THIS ONE. It is the highest-consequence value in the file.
     *
     * EXPLICIT, NOT INHERITED FROM THE SERVER. Hostinger sets its PHP
     * default and its MySQL default independently, and neither is
     * guaranteed to be the zone you live in. This app is made of dates:
     * "due today", "3 months after I last did it", "check that crack
     * again in April", "which day did the cron run on". A one-day skew
     * is not subtle here — it is a reminder arriving on the wrong day,
     * every time, with nothing on screen to explain it.
     *
     * Set once, used twice: lib/bootstrap.php calls
     * date_default_timezone_set() with it the instant config loads, and
     * lib/db.php pins the MySQL connection to the same offset at connect
     * time. Nothing else in the app asks what day it is except
     * sw_today() in lib/dates.php.
     *
     * A named zone, NOT an offset: DST is handled correctly, and
     * lib/db.php converts it to the numeric offset MySQL needs.
     */
    'timezone' => 'America/Chicago',

    /* ---- the gate ---------------------------------------------------
     * Single user, password only. Store the HASH, never the password.
     *
     *   php tools/make-hash.php
     *
     * THE GATE IS ON. Every screen is private, and this app gets its own
     * session cookie ('shirewatch'), so signing in or out here never
     * disturbs a session for one of the sibling apps on the same host.
     *
     * The ONE exception is public/cron.php, which cannot be behind a
     * session because the thing fetching it is a scheduler. It has its
     * own token, below.
     *
     * Leaving this empty or as CHANGE_ME does NOT lock the app: the gate
     * fails open, so a deploy with no hash is reachable by anyone who
     * finds the URL rather than by nobody including you. Set it before
     * pointing a domain at this.
     */
    'password_hash' => 'CHANGE_ME',

    // How long a login lasts on a device, in days.
    'session_days'  => 90,

    /* ---- web root ---------------------------------------------------
     * Name of the directory this app's public files live in, relative to
     * the folder holding lib/ and this file. Leave empty to auto-detect
     * "public" (a local checkout) or "public_html" (Hostinger).
     *
     * Getting it wrong doesn't error — it silently stops asset() from
     * cache-busting the CSS and JS, so a deploy appears not to have
     * taken effect.
     */
    'public_dir' => '',

    /* ---- email ------------------------------------------------------
     * SMTP, not mail(). A message pushed through mail() from a shared
     * host lands in spam often enough that you stop trusting the
     * reminders — and a reminder you don't trust is worse than none,
     * because you still get the mail and just stop reading it.
     *
     * >> from_email MUST EQUAL user. <<
     *
     * Gmail rewrites or rejects a From address it has not authorized,
     * and the bounce is SILENT from this app's point of view: the send
     * "succeeds", the ledger records a delivery, and the mail never
     * arrives. Nothing on any screen would ever say so. This is the
     * single most common way the setup fails.
     *
     * 'pass' is a Gmail APP PASSWORD, not the account password. Regular
     * passwords have not worked for SMTP since 2022.
     *
     * Verify with `php tools/send-test-email.php` BEFORE trusting a cron
     * you cannot watch run.
     */
    'smtp' => array(
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'secure'     => 'tls',        // 'tls' (587, STARTTLS) or 'ssl' (465)
        'user'       => 'kathrynmarinaro@gmail.com',
        'pass'       => 'CHANGE_ME',  // Gmail APP PASSWORD
        'from_email' => 'kathrynmarinaro@gmail.com',  // MUST equal 'user'
        'from_name'  => 'Shirewatch',

        /* Single user: every reminder goes to this one address. Make it
         * the one you actually read on your phone.
         */
        'to'         => 'kathrynmarinaro@gmail.com',
    ),

    /* ---- reminders --------------------------------------------------- */
    'reminders' => array(
        /* Days of warning before a maintenance task falls due. The email
         * goes out this many days early; the dashboard shows the task as
         * due on the day itself.
         *
         * Read in ONE place so the cron and the dashboard cannot
         * disagree about it.
         */
        'lead_days' => 7,

        /* Cap on emails per cron run. A first run against a freshly
         * seeded database could otherwise try to send twenty at once,
         * hit Gmail's rate limit partway through, and leave you unable
         * to tell which ones actually went.
         *
         * Anything not sent this run is still due next run: the send
         * ledger only records deliveries, never skips.
         */
        'max_per_run' => 10,
    ),

    /* ---- dashboard --------------------------------------------------- */
    'dashboard' => array(
        /* How far the forward timeline projects recurring tasks, in
         * months. Purely a rendering horizon — nothing is stored, and
         * changing it changes only how far you can scroll.
         *
         * 24 is enough to show an annual task twice, which is what makes
         * it read as recurring rather than as a one-off.
         */
        'horizon_months' => 24,

        // Rows per page of the forward timeline (infinite scroll).
        'page_size'      => 40,
    ),

    /* ---- photos and documents ---------------------------------------
     * >> RAISE PHP'S UPLOAD LIMITS OR NOTHING HERE MATTERS. <<
     *
     * PHP ships with upload_max_filesize = 2M and a single iPhone photo
     * is bigger than that, so with the defaults EVERY real upload is
     * rejected before the app ever sees it. In hPanel -> Advanced ->
     * PHP Configuration set:
     *
     *     upload_max_filesize   25M
     *     post_max_size        128M      (a whole batch at once)
     *     max_file_uploads      40
     *     memory_limit         256M
     */
    'media' => array(
        // Longest edge, in pixels, of the two derived copies.
        'thumb_max'    => 400,
        'detail_max'   => 1600,
        'webp_quality' => 82,

        /* Per-file ceiling, in megabytes. Applies to photos AND to
         * uploaded documents.
         */
        'max_upload_mb' => 25,

        /* How many queued items the browser drains per worker call, and
         * how many the cron sweep takes per tick. The cap keeps a slow
         * run from overlapping the next one.
         */
        'batch'         => 5,
    ),

    /* ---- cron -------------------------------------------------------
     * Hostinger plans differ. Some give a real command cron:
     *
     *   php /home/uXXXX/domains/.../tools/cron-reminders.php
     *
     * Others only offer a URL fetch, and tools/ is denied over HTTP —
     * so public/cron.php exists as a thin wrapper around the same code,
     * gated by this token compared with hash_equals().
     *
     * Generate one and paste it in:
     *
     *   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
     *
     * Leave it EMPTY if you have a command cron. public/cron.php refuses
     * to run with an empty token rather than running unauthenticated —
     * the opposite of the login gate's fail-open, because failing open
     * here locks nobody out of anything, it just puts a job that sends
     * email on the open internet.
     */
    'cron_token' => '',
);
