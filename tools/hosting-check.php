<?php
/* What this Hostinger plan can actually do.
 *
 *   php tools/hosting-check.php          (over SSH, if you have it)
 *   or upload it and fetch it once, then DELETE IT
 *
 * ---------------------------------------------------------------------------
 * RUN THIS BEFORE THE MODULE PASSES, NOT AFTER.
 * ---------------------------------------------------------------------------
 *
 * Book Tracker's equivalent is what cut Google Books from that build: the
 * hosting turned out not to be able to reach it, and finding that out during
 * integration would have meant rewriting a finished module. Three things here
 * can change this app's plan the same way, and all three are cheaper to learn
 * now:
 *
 *   1. NO IMAGICK. GD cannot read HEIC, and HEIC is what an iPhone produces by
 *      default. Without Imagick, either the phone's camera settings change to
 *      "Most Compatible" or the app has to tell people why their photo was
 *      rejected. That is a decision, and it belongs to the photo module.
 *   2. OUTBOUND SMTP BLOCKED. Some shared plans refuse port 587 outright. The
 *      whole reminder module assumes it is open.
 *   3. NO COMMAND CRON, only a URL fetch. That decides whether
 *      public/cron.php is the primary entry point or the fallback, which
 *      changes what DEPLOY.txt tells you to paste into hPanel.
 *
 * It is READ-ONLY except for one temp file it writes and deletes, and it
 * prints no credentials — the SMTP check reports whether the socket opened,
 * never what was sent over it.
 *
 * DELETE IT FROM THE SERVER AFTERWARDS. It reports PHP limits and extension
 * versions, which is reconnaissance you have no reason to publish. */

declare(strict_types=1);

$viaHttp = PHP_SAPI !== 'cli';
if ($viaHttp) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
}

$root = dirname(__DIR__);

function line(string $label, string $value, ?bool $ok = null): void
{
    $mark = $ok === null ? ' ' : ($ok ? '+' : '!');
    printf("%s %-26s %s\n", $mark, $label, $value);
}
function head(string $title): void
{
    printf("\n%s\n%s\n", $title, str_repeat('-', strlen($title)));
}

printf("Shirewatch — hosting check\n%s\n", date('Y-m-d H:i:s T'));

/* ------------------------------------------------------------------- PHP */

head('PHP');
$phpOk = version_compare(PHP_VERSION, '8.4', '>=');
line('version', PHP_VERSION, $phpOk);
if (!$phpOk) {
    line('', 'This app declares strict_types and uses 8.4 syntax. 8.4+ required.');
}
line('SAPI', PHP_SAPI);

head('Extensions');
foreach (array(
    'pdo_mysql' => 'REQUIRED — no database without it',
    'mbstring'  => 'REQUIRED — string truncation is mb_* throughout',
    'openssl'   => 'REQUIRED — SMTP over TLS',
    'gd'        => 'photo resizing, fallback path',
    'imagick'   => 'photo resizing, PREFERRED — the only HEIC path',
    'curl'      => 'not used today; nice to have',
    'zip'       => 'export',
) as $ext => $why) {
    $has = extension_loaded($ext);
    line($ext, ($has ? 'present' : 'MISSING') . '  — ' . $why, $has);
}

/* HEIC is the one that decides a feature, so it gets its own check: the coder
 * can be registered with no delegate behind it, in which case queryFormats()
 * lists it and reading a real file still fails. */
if (extension_loaded('imagick')) {
    $formats = @Imagick::queryFormats('HEI*');
    $heic    = is_array($formats) && $formats !== array();
    line('imagick HEIC coder', $heic ? implode(', ', $formats) : 'NOT REGISTERED', $heic);
    if (!$heic) {
        line('', 'iPhone photos will be rejected unless the camera is set to "Most Compatible".');
    }
}

/* ------------------------------------------------------------ upload limits */

head('Upload limits  (PHP ships 2M, which rejects every real photo)');
function as_bytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}
$want = array(
    'upload_max_filesize' => 25 * 1024 ** 2,
    'post_max_size'       => 128 * 1024 ** 2,
    'memory_limit'        => 256 * 1024 ** 2,
    'max_file_uploads'    => 40,
    'max_execution_time'  => 30,
);
foreach ($want as $key => $min) {
    $raw = trim((string) ini_get($key));

    /* -1 and 0 mean NO LIMIT for these settings, which is a pass, not a
     * failure. Comparing them numerically reports the most generous possible
     * configuration as the most restrictive one — and the whole value of this
     * script is that you believe what it prints. */
    $unlimited = ($raw === '-1' || $raw === '0');

    $ok = $unlimited || (in_array($key, array('max_file_uploads', 'max_execution_time'), true)
        ? (int) $raw >= $min
        : as_bytes($raw) >= $min);

    $shown = $unlimited ? $raw . ' (no limit)' : $raw;
    line($key, $shown . '   (want ' . ($min >= 1024 ? (int) ($min / 1024 ** 2) . 'M' : $min) . '+)', $ok);
}

/* ------------------------------------------------------------------ writes */

head('Writable directories');
foreach (array('public/uploads/original', 'public/uploads/thumb', 'public/uploads/detail', 'public/uploads/docs') as $rel) {
    $path = $root . '/' . $rel;
    if (!is_dir($path)) {
        line($rel, 'MISSING — create it', false);
        continue;
    }
    $probe = $path . '/.hosting-check-' . bin2hex(random_bytes(4));
    $ok    = @file_put_contents($probe, 'x') !== false;
    @unlink($probe);
    line($rel, $ok ? 'writable' : 'NOT WRITABLE — chmod 755', $ok);
}

$htaccess = $root . '/public/uploads/.htaccess';
line('uploads/.htaccess', is_file($htaccess) ? 'present' : 'MISSING — uploads are executable!', is_file($htaccess));

/* -------------------------------------------------------------------- SMTP */

head('Outbound SMTP');
printf("  (A sandbox or CI box blocks these by default. This result only means
"
     . "   something when the script is run ON THE HOST that will send the mail.)
");
$host = 'smtp.gmail.com';
foreach (array(587, 465) as $port) {
    $started = microtime(true);
    $sock    = @fsockopen($host, $port, $errno, $errstr, 8);
    $ms      = (int) round((microtime(true) - $started) * 1000);

    if ($sock === false) {
        line("$host:$port", "BLOCKED — $errstr ($errno)", false);
        continue;
    }
    /* Read the greeting: a socket that opens and then says nothing is a
     * transparent proxy, which fails later and more confusingly. */
    stream_set_timeout($sock, 5);
    $greeting = (string) fgets($sock, 512);
    fclose($sock);
    $ok = str_starts_with($greeting, '220');
    line("$host:$port", ($ok ? 'open' : 'opened but no 220 greeting') . " ({$ms}ms)", $ok);
}

/* -------------------------------------------------------------------- cron */

head('Cron');
$phpBin = PHP_BINARY;
line('php binary', $phpBin !== '' ? $phpBin : 'unknown');
line('command cron', 'CHECK BY HAND in hPanel -> Advanced -> Cron Jobs.');
printf("  If it offers a command, use:\n    %s %s/tools/cron-reminders.php\n", $phpBin, $root);
printf("  If it only offers a URL, set cron_token in config.php and use:\n    https://YOUR-DOMAIN/cron.php?token=THE_TOKEN\n");

/* ---------------------------------------------------------------- database */

head('Database');
if (!is_file($root . '/config.php')) {
    line('config.php', 'not present yet — skipping the connection test');
} else {
    try {
        require_once $root . '/lib/bootstrap.php';
        $pdo = db();
        line('connection', 'OK', true);
        $server = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        line('server', $server);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        line('tables', count($tables) . ' present' . (count($tables) === 0 ? '  — load schema.sql' : ''), count($tables) > 0);
    } catch (Throwable $e) {
        line('connection', 'FAILED — ' . $e->getMessage(), false);
    }
}

printf("\nDone. If you ran this over the web, DELETE IT FROM THE SERVER NOW.\n");
