<?php
/* Every piece of date arithmetic in this app, in one file, as pure functions.
 *
 * ---------------------------------------------------------------------------
 * THE RULE: sw_today() IS THE ONLY FUNCTION HERE THAT ASKS WHAT DAY IT IS.
 * ---------------------------------------------------------------------------
 *
 * Everything else takes a $today parameter. Nothing else in the app anywhere
 * may call date(), time(), 'now' or NOW() to decide a due date.
 *
 * Why it is worth being this strict. lib/auth.php carries a comment about a
 * sibling app whose lockout silently never fired: PHP ran in UTC while MySQL
 * ran in CDT, strtotime() read MySQL's local-time string as UTC, and the unlock
 * time came out five hours in the past. Grocery could contain that lesson
 * inside one throttling query. This app cannot — "due today", "seven days
 * a filter is due", "three months after I last did it" and "which day did the
 * cron run on" ARE the product. A one-day skew is not subtle here; it is
 * maintenance falling due on the wrong day, every time, with nothing to explain
 * it.
 *
 * One reading of "today", taken once per request or cron run, passed down, and
 * compared against DATE columns that carry no time component at all. That is
 * what makes every due query `WHERE next_due_date <= ?` — index-friendly,
 * portable, free of DATE_ADD and INTERVAL, and exercisable by the SQLite test
 * harness. It is also what makes this file testable at all: five of these six
 * functions need no clock, no database and no config.
 *
 * ---------------------------------------------------------------------------
 * ALL ARITHMETIC HAPPENS IN UTC, DELIBERATELY, AND THAT IS NOT A CONTRADICTION.
 * ---------------------------------------------------------------------------
 *
 * The strings these functions handle are plain calendar dates. They carry no
 * time of day and no zone, so there is nothing to convert — but doing the
 * arithmetic in a zone that observes DST breaks it anyway:
 *
 *     (new DateTimeImmutable('2026-03-08'))->diff(new DateTimeImmutable('2026-03-09'))->days
 *
 * is 0, not 1, in America/Chicago, because those two midnights are 23 hours
 * apart and ->days floors. That would make a reminder due "today" for two days
 * running, once a year, in March. Parsing in UTC makes every day exactly 86400
 * seconds long and the counting exact.
 *
 * sw_today() is where the configured zone is applied, and it is the only
 * place it needs to be.
 */

declare(strict_types=1);

/**
 * Today, as Y-m-d, in the app's configured timezone.
 *
 * THE ONLY IMPURE FUNCTION IN THIS FILE. Call it once at the top of a request
 * or a cron run and pass the string down; calling it twice in one run is how a
 * dashboard rendered at 23:59:59.9 ends up disagreeing with itself.
 *
 * It reads PHP's default timezone rather than cfg('timezone') again, on
 * purpose. lib/bootstrap.php sets that default from the config value as its
 * very first act, and lib/db.php pins MySQL's connection to the same zone.
 * Re-reading the config here would be a second, independent interpretation of
 * the same setting — one more place for the app's idea of "today" to fork.
 */
function sw_today(): string
{
    return date('Y-m-d');
}

/**
 * Parse a Y-m-d date into a UTC midnight. Internal to this file.
 *
 * Tolerates a trailing time, so a DATETIME straight out of `created_at`
 * (`2026-04-15 09:30:00`) can be handed to days_since() without every caller
 * remembering to slice it. Returns null for anything it cannot read, so the
 * callers can degrade one row rather than throwing a screen away.
 */
function sw_parse_date(?string $date): ?DateTimeImmutable
{
    if ($date === null || strlen($date) < 10) {
        return null;
    }

    /* '!' resets the time to 00:00:00 — without it, createFromFormat fills the
     * unspecified time from the CURRENT clock, which makes a "pure" function
     * return different answers depending on when you run it. */
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), new DateTimeZone('UTC'));

    /* createFromFormat happily rolls 2026-02-30 forward into March rather than
     * failing, so the round trip is the actual validity check. */
    if ($parsed === false || $parsed->format('Y-m-d') !== substr($date, 0, 10)) {
        return null;
    }

    return $parsed;
}

/**
 * Whole days from $from to $to. Negative when $to is earlier. Internal.
 *
 * Both sides are UTC midnights, so this is exact integer division and no DST
 * transition can round it to the wrong side. See the file header.
 */
function sw_days_between(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    return (int) (($to->getTimestamp() - $from->getTimestamp()) / 86400);
}


/**
 * A calendar date with the day clamped to the end of the month. Internal.
 * 2027-02-29 becomes 2027-02-28; 2026-06-31 becomes 2026-06-30.
 */
function sw_clamped_date(int $year, int $month, int $day): string
{
    $first = DateTimeImmutable::createFromFormat(
        '!Y-n-j',
        $year . '-' . $month . '-1',
        new DateTimeZone('UTC')
    );
    if ($first === false) {
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    $lastDay = (int) $first->format('t');
    return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
}



/**
 * Whole days from $date to $today. NULL in, null out.
 *
 * The People list's secondary line: "last contacted 34 days ago", or "never"
 * when this returns null. Null is a real answer and the caller must render it
 * as one — a 0 here would read as "contacted today", which is the opposite of
 * the truth about somebody you have never spoken to.
 *
 * A future date gives a NEGATIVE number rather than 0 or an absolute value.
 * Nothing in the app should be producing one, so making it visible beats
 * quietly rounding it into something that looks reasonable.
 */
function days_since(?string $date, string $today): ?int
{
    $then = sw_parse_date($date);
    $now  = sw_parse_date($today);
    if ($then === null || $now === null) {
        return null;
    }

    return sw_days_between($then, $now);
}

/**
 * A due date phrased the way a person would say it.
 *
 *   Today · Tomorrow · Yesterday · 3 days ago · Friday · April 15
 *
 * Overdue dates stay in days ("34 days ago") however far back they go, rather
 * than switching to a calendar date like the future side does. That asymmetry
 * is deliberate: how overdue something is, is the information. A task's due
 * date deliberately does not advance until you actually complete it (see
 * CLAUDE.md), so the dashboard is allowed to accumulate, and "34 days ago"
 * carries the weight that "March 2" does not.
 *
 * Inside the coming week the weekday name is more useful than a date — you
 * know whether you can call somebody on Friday without counting. Beyond that a
 * weekday name is ambiguous, so it becomes a date.
 */
function fmt_relative_due(string $due, string $today): string
{
    $dueDate   = sw_parse_date($due);
    $todayDate = sw_parse_date($today);
    if ($dueDate === null || $todayDate === null) {
        return $due;
    }

    $diff = sw_days_between($todayDate, $dueDate);

    if ($diff === 0) {
        return 'Today';
    }
    if ($diff === 1) {
        return 'Tomorrow';
    }
    if ($diff === -1) {
        return 'Yesterday';
    }
    if ($diff < -1) {
        return abs($diff) . ' days ago';
    }
    if ($diff <= 6) {
        return $dueDate->format('l');
    }

    /* No year. Within a rolling year "April 15" is unambiguous, and the year
     * on a due date reads as an error rather than as information. */
    return $dueDate->format('F j');
}


/* ==================================================================
 * RECURRENCE
 * ------------------------------------------------------------------
 * Two shapes, because one is wrong for half the starter list
 * (DELEGATION-PLAN.md §2.2):
 *
 *   'interval'  every N days/weeks/months/years. WEAR-BASED — the HVAC
 *               filter's clock started when you last changed it, so doing it
 *               late moves the next one late too.
 *   'months'    specific months of the year. SEASONAL — gutters happen in
 *               March and October or they are not gutter cleaning, and a
 *               "180 days" approximation walks them out of their season over
 *               a few years.
 *
 * ONE FUNCTION COMPUTES EVERY NEXT DATE IN THIS APP. task_complete() calls it,
 * the task editor calls it, and the dashboard's forward projection (§2.13)
 * calls it to draw dates months ahead. That is deliberate: a projection drawn
 * by a second implementation would eventually show you a date the app would
 * never actually produce, and you would not find out until the day it did not
 * arrive.
 * ================================================================== */

/**
 * The next occurrence of $task strictly AFTER $after.
 *
 * @param array  $task  Row (or row-shaped array) with recur_kind and its
 *                      partner columns. A malformed one returns null rather
 *                      than throwing — fail soft, one row, never a screen.
 * @param string $after Y-m-d. The date to advance from: the completion date
 *                      for interval_from='completion', otherwise the old due
 *                      date. The caller decides which; this function does not
 *                      look at the clock.
 * @return string|null  Y-m-d, or null when the task has no usable rule.
 */
function recur_next_after(array $task, string $after): ?string
{
    $from = sw_parse_date($after);
    if ($from === null) {
        return null;
    }

    $kind = (string) ($task['recur_kind'] ?? 'interval');

    if ($kind === 'months') {
        return recur_next_month_anchored(
            recur_parse_months($task['recur_months'] ?? null),
            max(1, (int) ($task['recur_day'] ?? 1)),
            $from
        );
    }

    $count = (int) ($task['interval_count'] ?? 0);
    $unit  = (string) ($task['interval_unit'] ?? '');
    if ($count < 1 || !in_array($unit, array('day', 'week', 'month', 'year'), true)) {
        return null;
    }

    /* Months and years go through the clamp; days and weeks cannot need it.
     *
     * PHP's own date arithmetic OVERFLOWS instead of clamping: January 31 plus
     * one month is March 3, not February 28. For a task set up on the 31st that
     * means it drifts a few days later every short month and eventually lands
     * in the wrong month entirely, which nobody would ever trace back to the
     * date library. */
    if ($unit === 'month' || $unit === 'year') {
        $months = $unit === 'year' ? $count * 12 : $count;
        $y      = (int) $from->format('Y');
        $m      = (int) $from->format('n');
        $d      = (int) $from->format('j');

        $total = ($y * 12) + ($m - 1) + $months;
        return sw_clamped_date(intdiv($total, 12), ($total % 12) + 1, $d);
    }

    $days = $unit === 'week' ? $count * 7 : $count;
    return $from->modify('+' . $days . ' day')->format('Y-m-d');
}

/**
 * Parse a "3,10" month list into a sorted list of 1-12 ints.
 *
 * Anything unparseable is DROPPED rather than defaulted, and an empty result
 * makes recur_next_after() return null — a task whose rule is corrupt shows as
 * having no schedule, which is visible, instead of quietly acquiring January.
 *
 * @return list<int>
 */
function recur_parse_months($raw): array
{
    $out = array();
    foreach (explode(',', (string) $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $m = (int) $part;
        if ($m >= 1 && $m <= 12 && !in_array($m, $out, true)) {
            $out[] = $m;
        }
    }
    sort($out);
    return $out;
}

/**
 * The next month-anchored occurrence strictly after $from. Internal.
 *
 * Walks this year's months first, then wraps to the earliest month next year.
 * Two years of candidates is always enough: with at least one legal month in
 * the list, one of them must fall in the twelve months after any given date.
 */
function recur_next_month_anchored(array $months, int $day, DateTimeImmutable $from): ?string
{
    if ($months === array()) {
        return null;
    }

    $year = (int) $from->format('Y');
    $cut  = $from->format('Y-m-d');

    foreach (array($year, $year + 1) as $y) {
        foreach ($months as $m) {
            /* Clamped, so "the 31st of every February" is the 28th (or the
             * 29th) rather than March. */
            $candidate = sw_clamped_date($y, $m, $day);
            if ($candidate > $cut) {
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * The rule in words, for a task row: "Every 3 months", "March and October".
 *
 * Returns '' for a task with no usable rule, so a caller can render nothing
 * rather than "Every 0 s".
 */
function recur_describe(array $task): string
{
    $kind = (string) ($task['recur_kind'] ?? 'interval');

    if ($kind === 'months') {
        $months = recur_parse_months($task['recur_months'] ?? null);
        if ($months === array()) {
            return '';
        }
        $names = array_map(
            static fn(int $m): string => date('F', mktime(0, 0, 0, $m, 1, 2001)),
            $months
        );
        if (count($names) === 1) {
            return 'Every ' . $names[0];
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    $count = (int) ($task['interval_count'] ?? 0);
    $unit  = (string) ($task['interval_unit'] ?? '');
    if ($count < 1 || $unit === '') {
        return '';
    }

    return $count === 1
        ? 'Every ' . $unit
        : 'Every ' . $count . ' ' . $unit . 's';
}
