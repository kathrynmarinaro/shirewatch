<?php
/* The URL-fetch cron, for a plan with no SSH.
 *
 *   https://your-domain/cron.php?token=<the long random string>
 *
 * ---------------------------------------------------------------------------
 * THE ONLY UNAUTHENTICATED SURFACE IN THIS APP.
 * ---------------------------------------------------------------------------
 *
 * Everything else is behind require_login_page() or require_login_api(). This
 * one cannot be: the thing fetching it is a scheduler with no session and no
 * way to acquire one. So it is gated on a long random token instead, and that
 * gate is the only thing standing between the open internet and a job that
 * sends email.
 *
 * THREE THINGS ABOUT THE GATE, ALL DELIBERATE:
 *
 *   1. hash_equals(), not ===. String comparison short-circuits on the first
 *      differing byte, which leaks the token one character at a time to
 *      anybody patient enough to time the responses.
 *   2. 404, NOT 403. A 403 confirms the endpoint is there and worth guessing
 *      at; a 404 says what every other non-existent path says.
 *   3. AN EMPTY TOKEN REFUSES rather than running unauthenticated. This is the
 *      opposite of the login gate's fail-open, and the difference is that
 *      failing open here locks nobody out of anything — the command cron and
 *      the app both still work. config.example.php ships the token empty
 *      precisely so a deploy that never sets one has no reachable cron rather
 *      than a public one.
 *
 * NOT ONE LINE OF LOGIC LIVES HERE. tools/cron-reminders.php declares
 * cron_reminders_run() and only runs itself when it is the entry point, so
 * requiring it below gets the identical code path the command cron takes.
 *
 * tools/ is denied over HTTP by tools/.htaccess, which is what makes this
 * wrapper necessary. That deny is about REQUESTS, not about PHP reading a file
 * from disk, so the require below is unaffected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../tools/cron-reminders.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$expected = trim((string) cfg('cron_token', ''));
$given    = (string) ($_GET['token'] ?? '');

if ($expected === '' || $expected === 'CHANGE_ME' || !hash_equals($expected, $given)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    /* As boring as Apache's own. Nothing here confirms the file exists. */
    exit("<!doctype html>\n<html><head><title>404 Not Found</title></head>\n"
        . "<body><h1>Not Found</h1></body></html>\n");
}

$today  = sw_today();
$result = cron_reminders_run($today);

header('Content-Type: text/plain; charset=utf-8');
printf(
    "[%s] %d due, %d to send, %d sent, %d already handled, %d failed%s\n",
    $today,
    $result['due'],
    $result['to_send'],
    $result['sent'],
    $result['skipped'],
    $result['failed'],
    $result['error'] === '' ? '' : ' — ' . $result['error']
);

/* A 500 on failure, so a scheduler that reports non-2xx tells you something
 * went wrong without your having to read the body. */
if ($result['failed'] > 0) {
    http_response_code(500);
}
