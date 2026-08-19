<?php
/* Drain the photo queue from cron.
 *
 *   php cron/process-queue.php [max]        default 20
 *
 * Suggested crontab (every few minutes; the batch cap keeps a slow run from
 * overlapping the next tick):
 *   every 5 minutes:
 *     /usr/bin/php /path/to/cron/process-queue.php >> /path/to/queue.log 2>&1
 *
 * ---------------------------------------------------------------------------
 * A SAFETY NET, NOT THE MAIN PATH.
 * ---------------------------------------------------------------------------
 *
 * Photos normally process while you are still in the app: api/upload.php
 * returns immediately and the browser drains the queue itself. This catches
 * what a closed tab, a locked phone or a dropped connection stranded — so on
 * most ticks it finds nothing and exits, which is the intended outcome.
 *
 * Safe to run alongside the browser's drain loop. Claims are a single
 * conditional UPDATE whose rowCount() decides the winner (lib/media.php), so
 * the two just take different rows.
 *
 * ---------------------------------------------------------------------------
 * THIS IS NOT THE REMINDER CRON.
 * ---------------------------------------------------------------------------
 *
 * That is tools/cron-reminders.php, once a day. This one runs every few
 * minutes and sends no email. Two jobs, two schedules — see DEPLOY.txt.
 *
 * AND IT DOES NOT REAP ORIGINALS. The Inspiration Gallery's equivalent sweeps
 * away originals once derivatives exist; Shirewatch keeps them forever
 * (CLAUDE.md). If you are porting a fix from that file, do not bring the
 * sweep with it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/media.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("process-queue.php is a CLI script. Use public/cron.php for a URL cron.\n");
}

$max = 20;
foreach (array_slice($argv, 1) as $arg) {
    if (ctype_digit($arg)) {
        $max = max(1, (int) $arg);
    }
}

$started = microtime(true);
$ready   = 0;
$retry   = 0;
$failed  = 0;

for ($i = 0; $i < $max; $i++) {
    $status = media_process_next();
    if ($status === null) {
        break;                              // queue drained
    }
    switch ($status) {
        case 'ready':  $ready++;  break;
        case 'failed': $failed++; break;
        default:       $retry++;  break;    // back to pending for another go
    }
}

$done = $ready + $retry + $failed;

/* SILENT WHEN THERE WAS NOTHING TO DO. This runs every few minutes; a line per
 * tick is a log file that grows forever and that nobody reads, which is the
 * same as having no log at all on the day something is actually wrong. */
if ($done === 0) {
    exit(0);
}

printf(
    "[%s] processed %d (ready %d, retry %d, failed %d), %d remaining, %.1fs\n",
    date('Y-m-d H:i:s'),
    $done,
    $ready,
    $retry,
    $failed,
    media_pending_count(),
    microtime(true) - $started
);
