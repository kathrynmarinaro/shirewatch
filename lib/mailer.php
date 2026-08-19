<?php
/* Outgoing email: the one thing this app does that you are not looking at.
 *
 * ---------------------------------------------------------------------------
 * ONE FUNCTION IS THE CONTRACT (PLAN.md §7.1).
 * ---------------------------------------------------------------------------
 *
 *   send_task_reminder(array $tasks, string $today): bool
 *
 * ONE EMAIL PER RUN, CARRYING EVERY DUE TASK — not one email per task. Five
 * separate messages on a Saturday morning is five notifications you swipe away
 * without reading; one list is a thing you act on. The send LEDGER is still
 * per-task (schema.sql, task_reminder_sends), so a task that has already been
 * emailed about is simply absent from the next digest.
 *
 * Everything else in this file exists to build that message or to explain why
 * it did not go. Nothing outside this file knows that PHPMailer exists, which
 * is the point: swapping the transport later — for a hand-rolled SMTP client,
 * for an API — touches this file and nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHY SMTP AND NOT mail().
 * ---------------------------------------------------------------------------
 *
 * A message pushed through mail() from a shared host arrives in spam often
 * enough that you would stop trusting the reminders, and a reminder you do not
 * trust is worse than no reminder: you still get the mail, you just stop
 * reading it. The brief asks for SMTP for exactly this reason.
 *
 * PHPMailer is VENDORED AS THREE PLAIN FILES in lib/vendor/PHPMailer/, required
 * directly below. No Composer, no autoloader, no build step — they FTP up with
 * everything else, which is what the suite's no-build-step rule is actually
 * about (not needing a toolchain, rather than not using anyone else's code).
 * Those three files are third-party and are kept byte-identical to the upstream
 * release; do not restyle them to match the house rules, and do not "fix" them.
 *
 * ---------------------------------------------------------------------------
 * THE FAILURE THIS FILE IS MOST AFRAID OF.
 * ---------------------------------------------------------------------------
 *
 * FROM_EMAIL THAT DOES NOT MATCH USER. Gmail rewrites or outright rejects a
 * From address it has not authorized, and the bounce is silent from this app's
 * point of view: the send "succeeds", the ledger records a delivery, and the
 * mail never arrives. Nothing on any screen would ever say so. It is checked
 * before the connection is opened (mailer_config_problem()), it is asserted by
 * tools/send-test-email.php, and it is in DEPLOY.txt in capitals.
 *
 * ---------------------------------------------------------------------------
 * THE CLOCK.
 * ---------------------------------------------------------------------------
 *
 * Every function here takes $today, exactly as lib/reminders.php does and for
 * the same reason — "next Tuesday" and "62 days ago" are what the subject line
 * SAYS, so a one-day skew is a lie printed on a lock screen.
 *
 * send_reminder_email() takes it as an OPTIONAL fourth argument, so that the
 * three-argument contract above still holds and the one sw_today() in this
 * file is the fallback nobody should reach. The cron passes its own $today, and
 * the tests pass a fixed one. */

declare(strict_types=1);

require_once __DIR__ . '/dates.php';

/* Order matters and there is no autoloader: PHPMailer.php and SMTP.php both
 * refer to Exception, and SMTP.php is instantiated from inside PHPMailer.php. */
require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';

/* How many tasks are named in the body before it summarises. The email is a
 * nudge, not the screen: past this many, "and 6 more" plus a link is what fits
 * on a lock-screen preview and still gets you to open the dashboard. */
const MAILER_TASK_LIMIT = 8;

/* Placeholders config.example.php ships with. Sending with either of these in
 * place is a guaranteed authentication failure, and catching it here turns a
 * mystery SMTP error into a sentence naming the key. */
const MAILER_PLACEHOLDER = 'CHANGE_ME';

/**
 * Why the last send failed, for the caller's ledger row. '' when nothing has.
 *
 * A module-level static rather than an exception or a richer return type,
 * because the contracted signature is `: bool` and the cron needs the reason
 * for reminders_mark_failed(). Read it immediately after a false; it is
 * overwritten by the next send.
 */
function mailer_last_error(?string $set = null): string
{
    static $last = '';
    if ($set !== null) {
        $last = $set;
    }
    return $last;
}

/* ================================================================== config ==*/

/**
 * The first thing wrong with the smtp config block, or null if nothing is.
 *
 * Checked BEFORE a connection is opened, so the common misconfigurations report
 * themselves as a sentence instead of as a timeout or a 535. Every one of these
 * has exactly one cause and exactly one fix, so each message names both.
 */
function mailer_config_problem(): ?string
{
    $user = trim((string) cfg('smtp.user', ''));
    $from = trim((string) cfg('smtp.from_email', ''));
    $to   = trim((string) cfg('smtp.to', ''));
    $pass = (string) cfg('smtp.pass', '');
    $host = trim((string) cfg('smtp.host', ''));

    if ($host === '') {
        return 'smtp.host is empty in config.php';
    }
    if ($user === '' || $user === MAILER_PLACEHOLDER) {
        return 'smtp.user is not set in config.php';
    }
    if ($pass === '' || $pass === MAILER_PLACEHOLDER) {
        return 'smtp.pass is not set in config.php — it must be a Gmail APP PASSWORD,'
            . ' not the account password';
    }
    if ($to === '' || $to === MAILER_PLACEHOLDER) {
        return 'smtp.to is not set in config.php — there is nobody to send reminders to';
    }

    /* THE ONE THAT FAILS SILENTLY. Everything above produces a visible error;
     * this produces a successful-looking send and no email. */
    if (strcasecmp($from, $user) !== 0) {
        return 'smtp.from_email (' . $from . ') does not match smtp.user (' . $user . ').'
            . ' Gmail rewrites or rejects a From it has not authorized and the bounce is'
            . ' silent — the send appears to succeed and the mail never arrives.';
    }

    return null;
}

/**
 * Where a profile lives on the public internet, for the link in the email.
 *
 * There is no base-URL key in config.example.php, so this works three ways and
 * degrades rather than refusing to send:
 *
 *   1. cfg('app_url') if somebody has added one — the only reliable answer for
 *      a command cron, which has no request and therefore no host name.
 *   2. The current request's own scheme and host, which is exactly right for
 *      the public/cron.php path: the URL the cron fetched IS the app's URL.
 *   3. A bare relative path, so the email still names the screen even when it
 *      cannot link to it. An email with no link is worth more than no email.
 *
 * DEPLOY.txt asks for (1). Adding 'app_url' to config.example.php is a
 * Foundation change and is reported rather than made here.
 */
function mailer_dashboard_url(): string
{
    $path = 'index.php';

    $base = trim((string) cfg('app_url', ''));
    if ($base !== '') {
        return rtrim($base, '/') . '/' . $path;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && PHP_SAPI !== 'cli') {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            ? 'https'
            : 'http';
        $dir = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $scheme . '://' . $host . $dir . '/' . $path;
    }

    return $path;
}

/* ================================================================ phrasing ==*/

/**
 * When something falls, said the way a person says it, for a SUBJECT LINE.
 *
 *   "today" · "tomorrow" · "next Wednesday" · "in 12 days" · "9 days ago"
 *
 * Deliberately NOT fmt_relative_due(), which is the on-screen phrasing and
 * capitalises for a list row ("Today", "Friday", "April 15"). A subject line
 * reads as a sentence and lands mid-phrase — "Birthday: Alex Chen — April 15
 * (April 15)" is what fmt_relative_due() produces at the default seven-day
 * lead, because seven days out is where it stops naming weekdays. This is the
 * one place in the app that needs a different register, so it gets its own
 * four-line function rather than a flag on the shared one.
 */
function mailer_when_phrase(string $date, string $today): string
{
    $days = days_since($today, $date);   // positive = in the future
    if ($days === null) {
        return $date;
    }

    if ($days === 0) {
        return 'today';
    }
    if ($days === 1) {
        return 'tomorrow';
    }
    if ($days === -1) {
        return 'yesterday';
    }
    if ($days < 0) {
        return abs($days) . ' days ago';
    }
    if ($days <= 7) {
        $parsed = sw_parse_date($date);
        return $parsed === null ? 'in ' . $days . ' days' : 'next ' . $parsed->format('l');
    }

    return 'in ' . $days . ' days';
}




/* ================================================================== bodies ==*/




/* ================================================================ delivery ==*/

/**
 * Hand one message to the SMTP server. The only place PHPMailer is touched.
 *
 * Returns false and records mailer_last_error() rather than throwing: a cron
 * run sends several emails and one refused connection must not cost the rest of
 * them (CLAUDE.md, fail soft). The caller writes the reason beside the ledger
 * row, so tomorrow's retry has something to read.
 *
 * SMTPDebug stays off. Its output goes to stdout, which for the URL-fetch cron
 * is the HTTP response body — the whole SMTP conversation, app password
 * included, served to whoever fetched the URL.
 */
function mailer_send(string $subject, string $textBody, string $htmlBody): bool
{
    $problem = mailer_config_problem();
    if ($problem !== null) {
        mailer_last_error($problem);
        error_log('mailer: ' . $problem);
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = (string) cfg('smtp.host', 'smtp.gmail.com');
        $mail->Port       = (int) cfg('smtp.port', 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) cfg('smtp.user', '');
        $mail->Password   = (string) cfg('smtp.pass', '');

        /* 'tls' is STARTTLS on 587; 'ssl' is implicit TLS on 465. Anything else
         * in config becomes STARTTLS rather than plaintext — a typo in this key
         * must not quietly send the app password in the clear. */
        $mail->SMTPSecure = ((string) cfg('smtp.secure', 'tls')) === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        /* A cron that hangs on a dead SMTP host holds the run open until PHP's
         * own limit kills it, which on the URL-fetch path means a request that
         * never returns and a "did it run?" nobody can answer. Fail in a
         * quarter of a minute and let tomorrow retry — the ledger row is what
         * makes that safe. */
        $mail->Timeout = 15;

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;

        $mail->setFrom((string) cfg('smtp.from_email', ''), (string) cfg('smtp.from_name', app_name()));
        $mail->addAddress((string) cfg('smtp.to', ''));

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        mailer_last_error('');
        return true;
    } catch (Throwable $e) {
        /* PHPMailer's ErrorInfo is the useful half — it carries the server's
         * own refusal ("Username and Password not accepted") where the
         * exception message is often just "SMTP Error: Could not authenticate". */
        $why = trim($mail->ErrorInfo) !== '' ? $mail->ErrorInfo : $e->getMessage();
        mailer_last_error($why);
        error_log('mailer: send failed: ' . $why);
        return false;
    }
}


/* ================================================================== body ==*/

/**
 * The subject line.
 *
 * Leads with the count, because that is the part visible in a notification
 * before anything else: "3 things due at the house" tells you whether to open
 * it. The single-task case names the task instead, since a count of one is
 * less informative than the thing itself.
 */
function mailer_subject(array $tasks, string $today): string
{
    $count = count($tasks);
    if ($count === 1) {
        $task = $tasks[0];
        return $task['title'] . ' — due ' . mailer_when_phrase((string) $task['next_due_on'], $today);
    }

    $overdue = 0;
    foreach ($tasks as $task) {
        if ((string) $task['next_due_on'] < $today) {
            $overdue++;
        }
    }

    $subject = $count . ' things due at the house';
    if ($overdue > 0) {
        /* The overdue count is the half that makes this urgent, and it is the
         * half that gets truncated if it goes at the end. */
        $subject .= ' (' . $overdue . ' overdue)';
    }
    return $subject;
}

/**
 * One task as a single line: "Furnace filter — due tomorrow · Every 3 months".
 *
 * Shared by the text and HTML bodies so the two cannot drift into saying
 * different things about the same task.
 */
function mailer_task_line(array $task, string $today): string
{
    $line = $task['title'] . ' — due ' . mailer_when_phrase((string) $task['next_due_on'], $today);

    $every = recur_describe($task);
    if ($every !== '') {
        $line .= ' · ' . $every;
    }
    return $line;
}

/**
 * The plain-text body.
 *
 * NOT AN AFTERTHOUGHT. It is what a watch, a lock-screen preview and a text-only
 * client actually show, and it is the AltBody on every message — so it has to
 * carry the same information as the HTML rather than saying "view this in a
 * modern client".
 */
function mailer_body_text(array $tasks, string $today): string
{
    $lines = array();
    $shown = array_slice($tasks, 0, MAILER_TASK_LIMIT);

    foreach ($shown as $task) {
        $lines[] = '· ' . mailer_task_line($task, $today);

        /* Instructions are the reason a task is worth an email rather than a
         * calendar entry: "the filter is 16x25x1, they are on the shelf above
         * the dryer" is what stops the job being deferred. First line only —
         * the rest is on the screen the link goes to. */
        $note = trim((string) ($task['instructions'] ?? ''));
        if ($note !== '') {
            $first = strtok($note, "\n");
            $lines[] = '    ' . mb_substr((string) $first, 0, 120, 'UTF-8');
        }
    }

    $extra = count($tasks) - count($shown);
    if ($extra > 0) {
        $lines[] = '· and ' . $extra . ' more';
    }

    return implode("\n", $lines) . "\n\n" . mailer_dashboard_url() . "\n";
}

/**
 * The HTML body.
 *
 * INLINE STYLES AND A TABLE-FREE SINGLE COLUMN, deliberately. Mail clients
 * strip <style> blocks, ignore external stylesheets and disagree about
 * flexbox; the house design system does not survive the trip, so this does not
 * try to bring it. It borrows the accent colour and nothing else.
 *
 * Everything is escaped with h(). A task title is user-entered text going into
 * a document rendered by someone else's client.
 */
function mailer_body_html(array $tasks, string $today): string
{
    $accent = '#41b7ab';
    $url    = h(mailer_dashboard_url());
    $shown  = array_slice($tasks, 0, MAILER_TASK_LIMIT);

    $rows = '';
    foreach ($shown as $task) {
        $overdue = (string) $task['next_due_on'] < $today;
        $rows .= '<li style="margin:0 0 14px 0;">'
            . '<span style="font-weight:600;' . ($overdue ? 'color:#c05252;' : '') . '">'
            . h(mailer_task_line($task, $today))
            . '</span>';

        $note = trim((string) ($task['instructions'] ?? ''));
        if ($note !== '') {
            $first = strtok($note, "\n");
            $rows .= '<br><span style="color:#515a60;font-size:14px;">'
                . h(mb_substr((string) $first, 0, 160, 'UTF-8'))
                . '</span>';
        }
        $rows .= '</li>';
    }

    $extra = count($tasks) - count($shown);
    if ($extra > 0) {
        $rows .= '<li style="color:#515a60;">and ' . (int) $extra . ' more</li>';
    }

    return '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;'
        . 'font-size:16px;line-height:1.5;color:#222;max-width:520px;">'
        . '<h1 style="font-size:19px;margin:0 0 4px 0;color:' . $accent . ';">' . h(app_name()) . '</h1>'
        . '<p style="margin:0 0 18px 0;color:#515a60;">Due at the house:</p>'
        . '<ul style="padding-left:20px;margin:0 0 22px 0;">' . $rows . '</ul>'
        . '<p style="margin:0;"><a href="' . $url . '" style="color:' . $accent . ';">Open ' . h(app_name()) . '</a></p>'
        . '</div>';
}

/**
 * THE CONTRACT. Send one digest covering every due task. Returns success.
 *
 * $today is a PARAMETER, not a call to sw_today(), for the reason this file's
 * header gives: "due tomorrow" is what the subject line SAYS, so a one-day
 * skew is a lie printed on a lock screen. The cron passes its own single
 * $today and the tests pass a fixed one. The default exists so the
 * two-argument contract holds; nothing should be reaching it.
 *
 * An EMPTY task list sends nothing and reports success. "There was nothing to
 * do" is not a failure, and a cron that treats it as one would log an error
 * every day the house was fine.
 *
 * @param list<array> $tasks Rows from maintenance_tasks, each with at least
 *                           title, next_due_on and the recurrence columns.
 */
function send_task_reminder(array $tasks, ?string $today = null): bool
{
    $today = $today ?? sw_today();

    if ($tasks === array()) {
        mailer_last_error('');
        return true;
    }

    return mailer_send(
        mailer_subject($tasks, $today),
        mailer_body_text($tasks, $today),
        mailer_body_html($tasks, $today)
    );
}
