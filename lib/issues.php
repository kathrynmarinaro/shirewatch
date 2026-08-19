<?php
/* Issues and their timelines.
 *
 * ---------------------------------------------------------------------------
 * NOTHING OUTSIDE THIS FILE WRITES SQL AGAINST issues OR issue_updates.
 * ---------------------------------------------------------------------------
 *
 * The dashboard (M6) reads through issues_needing_action() and
 * issues_upcoming_checks() rather than writing its own queries, so there is
 * one definition of "needs attention" and it cannot drift from what the Issues
 * screen shows.
 *
 * ---------------------------------------------------------------------------
 * THE TIMELINE IS THE FEATURE. NOTHING IN IT IS EVER OVERWRITTEN.
 * ---------------------------------------------------------------------------
 *
 * Every check-in is a new issue_updates row with its own date, note, severity
 * and photos. issue_add_update() is the only way one is created and it never
 * edits a previous one, because the whole premise of this app is putting a
 * photo of a crack beside a photo of the same crack eight months later. An
 * "edit the last update" path would eventually be used to correct a
 * measurement, and the correction is the observation.
 *
 * ---------------------------------------------------------------------------
 * THREE DERIVED COLUMNS, ALL WRITTEN HERE AND NOWHERE ELSE.
 * ---------------------------------------------------------------------------
 *
 *   issues.severity        the newest update that carried one
 *   issues.last_checked_on the newest update's date
 *   issues.next_check_on   that date plus check_interval_days
 *
 * All three are denormalized on purpose (schema.sql). The dashboard sorts on
 * severity and range-scans next_check_on, and deriving either per row means a
 * correlated subquery or an unindexable date expression. issue_recompute()
 * below is the single writer; every path that could change them calls it.
 *
 * PORTABILITY. MySQL is the production target; tools/test-harness.php runs
 * these functions against SQLite. Every statement here is in the intersection.
 */

declare(strict_types=1);

require_once __DIR__ . '/tags.php';
require_once __DIR__ . '/media.php';

/* The four states, spelled once. They are an ENUM in the schema, so a typo is
 * a database error rather than a silent no-op. */
const ISSUE_WATCHING  = 'watching';
const ISSUE_ACTIVE    = 'active';
const ISSUE_RESOLVED  = 'resolved';
const ISSUE_DISMISSED = 'dismissed';

/** @return list<string> */
function issue_statuses(): array
{
    return array(ISSUE_WATCHING, ISSUE_ACTIVE, ISSUE_RESOLVED, ISSUE_DISMISSED);
}

/** The two states that are still live — what the list shows by default. */
function issue_open_statuses(): array
{
    return array(ISSUE_WATCHING, ISSUE_ACTIVE);
}

function issue_status_label(string $status): string
{
    $labels = array(
        ISSUE_WATCHING  => 'Watching',
        ISSUE_ACTIVE    => 'Needs doing',
        ISSUE_RESOLVED  => 'Resolved',
        ISSUE_DISMISSED => 'Dismissed',
    );
    return $labels[$status] ?? $status;
}

/**
 * Severity 1-4 as a word.
 *
 * NULL RETURNS AN EMPTY STRING, and callers render nothing at all — not a
 * grey "Watch" pill, which would put something that looks like data on every
 * issue where none was recorded (schema.sql). Zero is not a legal value and
 * is treated the same as NULL rather than being mapped to level 1, so a
 * corrupt row degrades to "unset" rather than to "lowest".
 */
function issue_severity_label(?int $severity): string
{
    $labels = array(1 => 'Watch', 2 => 'Minor', 3 => 'Major', 4 => 'Urgent');
    return $labels[(int) $severity] ?? '';
}

/** Normalize a database row into the shape every caller expects. */
function issue_row(array $row): array
{
    return array(
        'id'                    => (int) $row['id'],
        'title'                 => (string) $row['title'],
        'description'           => $row['description'] !== null ? (string) $row['description'] : '',
        'status'                => (string) $row['status'],
        'severity'              => $row['severity'] !== null ? (int) $row['severity'] : null,
        'noticed_on'            => (string) $row['noticed_on'],
        'check_interval_days'   => $row['check_interval_days'] !== null ? (int) $row['check_interval_days'] : null,
        'last_checked_on'       => $row['last_checked_on'] ?: null,
        'next_check_on'         => $row['next_check_on'] ?: null,
        'resolved_on'           => $row['resolved_on'] ?: null,
        'resolved_by_record_id' => $row['resolved_by_record_id'] !== null ? (int) $row['resolved_by_record_id'] : null,
        'created_at'            => (string) $row['created_at'],
    );
}

/* ------------------------------------------------------------------ reads */

/** One issue, or null. */
function issue_get(int $id): ?array
{
    $row = q('SELECT * FROM issues WHERE id = ?', array($id))->fetch();
    return $row === false ? null : issue_row($row);
}

/**
 * The list, filtered.
 *
 * @param array $filters {
 *   status?:   list<string>  default: the two open states
 *   tag_ids?:  list<int>     AND-ed, so two tags narrow rather than widen
 *   search?:   string        title and description
 * }
 * @return list<array>  each with 'tags' and 'cover' already attached
 */
function issues_list(array $filters = array()): array
{
    $where  = array();
    $params = array();

    $statuses = $filters['status'] ?? issue_open_statuses();
    $statuses = array_values(array_intersect($statuses, issue_statuses()));
    if ($statuses === array()) {
        return array();
    }
    $where[]  = 'i.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
    $params   = array_merge($params, $statuses);

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[]  = '(i.title LIKE ? OR i.description LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    /* TAGS ARE AND-ED, NOT OR-ED. Picking Kitchen and Plumbing means "the
     * kitchen plumbing problem", not "everything in the kitchen plus
     * everything plumbing" — a filter that widens as you add to it is a filter
     * nobody can aim.
     *
     * Expressed as a join counted in HAVING rather than one self-join per tag,
     * so the query shape does not change with the number of tags picked. The
     * join's placeholders come BEFORE the WHERE clause's in the statement, so
     * they are prepended to the bound array rather than appended. */
    $tagIds = array_values(array_filter(array_map('intval', $filters['tag_ids'] ?? array())));

    $join   = '';
    $group  = '';
    if ($tagIds !== array()) {
        $holes = implode(', ', array_fill(0, count($tagIds), '?'));
        $join  = " JOIN issue_tags it ON it.issue_id = i.id AND it.tag_id IN ($holes)";
        $group = ' GROUP BY i.id HAVING COUNT(DISTINCT it.tag_id) = ' . count($tagIds);
        $params = array_merge($tagIds, $params);
    }

    /* Severity descending with NULLs last, then oldest first. Severity alone
     * would bury a six-month-old unset issue under everything; this puts the
     * urgent things on top and, within a level, the ones that have been
     * waiting longest. */
    $sql = 'SELECT i.* FROM issues i'
        . $join
        . ' WHERE ' . implode(' AND ', $where)
        . $group
        . ' ORDER BY (i.severity IS NULL), i.severity DESC, i.noticed_on';

    $rows = q($sql, $params)->fetchAll() ?: array();
    return issues_decorate(array_map('issue_row', $rows));
}

/**
 * Attach tags and a cover photo to a list of issues, in two queries total.
 *
 * NOT a loop of per-issue lookups. Forty issues on a list screen would be
 * eighty round trips, which is the single easiest way to make this app feel
 * slow on a phone.
 */
function issues_decorate(array $issues): array
{
    if ($issues === array()) {
        return array();
    }

    $ids   = array_column($issues, 'id');
    $tags  = tags_for_many('issue', $ids);
    $media = media_for_many('issue', $ids);

    foreach ($issues as $index => $issue) {
        $issues[$index]['tags']  = $tags[$issue['id']] ?? array();
        $photos                  = $media[$issue['id']] ?? array();
        /* The first READY photo, not simply the first: a card showing a
         * "Processing…" placeholder when a usable photo exists two positions
         * along looks broken rather than busy. */
        $issues[$index]['cover'] = issue_first_ready($photos);
        $issues[$index]['photo_count'] = count($photos);
    }
    return $issues;
}

/** @return array|null */
function issue_first_ready(array $photos): ?array
{
    foreach ($photos as $photo) {
        if ($photo['kind'] === 'photo' && $photo['status'] === 'ready' && $photo['thumb'] !== null) {
            return $photo;
        }
    }
    return $photos[0] ?? null;
}

/**
 * The timeline, oldest first.
 *
 * OLDEST FIRST, deliberately, unlike almost every other list in the suite.
 * This is a progression — "hairline", "reaches the cornice", "4cm past the
 * pencil mark" — and reading it newest-first tells the story backwards.
 *
 * @return list<array>  each with 'photos' attached
 */
function issue_updates(int $issueId): array
{
    $rows = q(
        'SELECT * FROM issue_updates WHERE issue_id = ? ORDER BY noted_on, id',
        array($issueId)
    )->fetchAll() ?: array();

    $updates = array();
    foreach ($rows as $row) {
        $updates[] = array(
            'id'       => (int) $row['id'],
            'noted_on' => (string) $row['noted_on'],
            'note'     => $row['note'] !== null ? (string) $row['note'] : '',
            'severity' => $row['severity'] !== null ? (int) $row['severity'] : null,
        );
    }

    if ($updates === array()) {
        return array();
    }

    $media = media_for_many('issue_update', array_column($updates, 'id'));
    foreach ($updates as $index => $update) {
        $updates[$index]['photos'] = $media[$update['id']] ?? array();
    }
    return $updates;
}

/**
 * Which way the severity has moved, across the whole timeline.
 *
 * Returns 'worse', 'better', 'stable', or '' when there is not enough recorded
 * severity to say anything. THE EMPTY CASE IS COMMON AND IS NOT A FAILURE —
 * severity is optional, and an issue logged with two photos and no severity
 * has a real timeline and no trend. Rendering "stable" for it would be
 * inventing a judgement nobody made.
 */
function issue_trend(array $updates): string
{
    $rated = array();
    foreach ($updates as $update) {
        if ($update['severity'] !== null) {
            $rated[] = (int) $update['severity'];
        }
    }
    if (count($rated) < 2) {
        return '';
    }

    $first = $rated[0];
    $last  = $rated[count($rated) - 1];

    if ($last > $first) { return 'worse'; }
    if ($last < $first) { return 'better'; }
    return 'stable';
}

/* ----------------------------------------------------------------- writes */

/**
 * Create an issue. Returns its id.
 *
 * The opening description is NOT written as a timeline update. An issue's
 * description is what it is; the timeline is what has happened since. Merging
 * them would make the first entry of every timeline a restatement of the
 * header.
 */
function issue_create(array $data): int
{
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('empty_title');
    }

    $status = (string) ($data['status'] ?? ISSUE_WATCHING);
    if (!in_array($status, issue_statuses(), true)) {
        $status = ISSUE_WATCHING;
    }

    q(
        'INSERT INTO issues (property_id, title, description, status, severity,
                             noticed_on, check_interval_days)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        array(
            1,
            mb_substr($title, 0, 200, 'UTF-8'),
            issue_clean_text($data['description'] ?? null),
            $status,
            issue_clean_severity($data['severity'] ?? null),
            issue_clean_date($data['noticed_on'] ?? null) ?? sw_today(),
            issue_clean_interval($data['check_interval_days'] ?? null),
        )
    );

    $id = (int) db()->lastInsertId();

    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        tags_set('issue', $id, $data['tag_ids']);
    }

    /* An issue created with a check interval and no check-ins yet is due from
     * the day it was noticed — otherwise it would have no next_check_on and
     * would never appear on the dashboard until somebody checked it once,
     * which is precisely the thing you would forget to do. */
    issue_recompute($id);

    return $id;
}

/** Update the header fields. Returns false if the issue is gone. */
function issue_save(int $id, array $data): bool
{
    $issue = issue_get($id);
    if ($issue === null) {
        return false;
    }

    $title = trim((string) ($data['title'] ?? $issue['title']));
    if ($title === '') {
        throw new InvalidArgumentException('empty_title');
    }

    q(
        'UPDATE issues SET title = ?, description = ?, noticed_on = ?, check_interval_days = ?
          WHERE id = ?',
        array(
            mb_substr($title, 0, 200, 'UTF-8'),
            array_key_exists('description', $data)
                ? issue_clean_text($data['description'])
                : ($issue['description'] === '' ? null : $issue['description']),
            issue_clean_date($data['noticed_on'] ?? null) ?? $issue['noticed_on'],
            array_key_exists('check_interval_days', $data)
                ? issue_clean_interval($data['check_interval_days'])
                : $issue['check_interval_days'],
            $id,
        )
    );

    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        tags_set('issue', $id, $data['tag_ids']);
    }

    /* Changing the interval moves the next check. Recomputing here is why that
     * is true without the caller having to know it. */
    issue_recompute($id);
    return true;
}

/**
 * Add a timeline entry. Returns its id.
 *
 * This is the check-in: a date, a note, optionally a new severity, and photos
 * attached separately by the media endpoints against the returned id.
 *
 * It recomputes all three derived columns, which is what makes "check this
 * again in 3 months" actually roll forward.
 */
function issue_add_update(int $issueId, array $data): int
{
    if (issue_get($issueId) === null) {
        throw new InvalidArgumentException('not_found');
    }

    q(
        'INSERT INTO issue_updates (issue_id, noted_on, note, severity) VALUES (?, ?, ?, ?)',
        array(
            $issueId,
            issue_clean_date($data['noted_on'] ?? null) ?? sw_today(),
            issue_clean_text($data['note'] ?? null),
            issue_clean_severity($data['severity'] ?? null),
        )
    );

    $updateId = (int) db()->lastInsertId();
    issue_recompute($issueId);
    return $updateId;
}

/** Remove one timeline entry. Its photos go with it, by cascade. */
function issue_delete_update(int $updateId): bool
{
    $row = q('SELECT issue_id FROM issue_updates WHERE id = ?', array($updateId))->fetch();
    if ($row === false) {
        return false;
    }
    $issueId = (int) $row['issue_id'];

    /* The files are NOT cascaded — the database only knows about rows. Delete
     * them here or they are orphaned on disk forever, since this app has no
     * reaping sweep by design. */
    foreach (media_for('issue_update', $updateId) as $photo) {
        media_delete($photo['id']);
    }

    q('DELETE FROM issue_updates WHERE id = ?', array($updateId));
    issue_recompute($issueId);
    return true;
}

/**
 * Move an issue to a new state.
 *
 * $recordId links the service record that FIXED it, and is always optional —
 * fix something yourself and the issue resolves with nothing attached
 * (CLAUDE.md). Passing one on a non-resolved status is ignored rather than
 * refused: the link means "this is what resolved it", so it has no meaning
 * anywhere else.
 */
function issue_set_status(int $id, string $status, ?int $recordId = null): bool
{
    if (!in_array($status, issue_statuses(), true) || issue_get($id) === null) {
        return false;
    }

    $closed = in_array($status, array(ISSUE_RESOLVED, ISSUE_DISMISSED), true);

    q(
        'UPDATE issues SET status = ?, resolved_on = ?, resolved_by_record_id = ? WHERE id = ?',
        array(
            $status,
            $closed ? sw_today() : null,
            ($status === ISSUE_RESOLVED && $recordId !== null && $recordId > 0) ? $recordId : null,
            $id,
        )
    );

    /* A closed issue stops asking to be checked. Leaving next_check_on set
     * would keep a resolved crack on the dashboard forever, which is how you
     * learn to ignore the dashboard. */
    issue_recompute($id);
    return true;
}

/** Delete an issue, its timeline, its tags and every file behind its photos. */
function issue_delete(int $id): bool
{
    if (issue_get($id) === null) {
        return false;
    }

    /* Files first, for the reason issue_delete_update() gives. The rows would
     * cascade; the bytes on disk would not. */
    foreach (issue_updates($id) as $update) {
        foreach ($update['photos'] as $photo) {
            media_delete($photo['id']);
        }
    }
    foreach (media_for('issue', $id) as $photo) {
        media_delete($photo['id']);
    }

    q('DELETE FROM issues WHERE id = ?', array($id));
    return true;
}

/**
 * Rewrite the three derived columns from the timeline. THE ONLY WRITER.
 *
 * Called by every path that can change them: create, save, add update, delete
 * update, set status. Callers never write severity, last_checked_on or
 * next_check_on directly — that is what stops the dashboard and the detail
 * screen from ever disagreeing about when something is next due.
 *
 * The date arithmetic is PHP's, never SQL's: `last_checked_on + INTERVAL n DAY`
 * is a predicate no index can help with and one the SQLite harness cannot
 * evaluate at all (schema.sql).
 */
function issue_recompute(int $id): void
{
    $issue = issue_get($id);
    if ($issue === null) {
        return;
    }

    /* The newest update that CARRIED a severity, which is not necessarily the
     * newest update. A later note that says "no change" without re-rating it
     * must not wipe the rating. */
    $severityRow = q(
        'SELECT severity FROM issue_updates
          WHERE issue_id = ? AND severity IS NOT NULL
          ORDER BY noted_on DESC, id DESC LIMIT 1',
        array($id)
    )->fetch();

    $lastRow = q(
        'SELECT noted_on FROM issue_updates WHERE issue_id = ? ORDER BY noted_on DESC, id DESC LIMIT 1',
        array($id)
    )->fetch();

    /* Fall back to the severity already on the issue when no update carries
     * one — an issue can be rated at creation and never checked in. */
    $severity = $severityRow !== false ? (int) $severityRow['severity'] : $issue['severity'];

    $lastChecked = $lastRow !== false ? (string) $lastRow['noted_on'] : null;

    $nextCheck = null;
    $closed    = in_array($issue['status'], array(ISSUE_RESOLVED, ISSUE_DISMISSED), true);

    if (!$closed && $issue['check_interval_days'] !== null) {
        /* From the last check-in when there was one, and from the day it was
         * noticed when there wasn't — so an issue you logged and never
         * revisited still comes due. That is the case this column exists for. */
        $from = $lastChecked ?? $issue['noticed_on'];
        $base = sw_parse_date($from);
        if ($base !== null) {
            $nextCheck = $base->modify('+' . $issue['check_interval_days'] . ' day')->format('Y-m-d');
        }
    }

    q(
        'UPDATE issues SET severity = ?, last_checked_on = ?, next_check_on = ? WHERE id = ?',
        array($severity, $lastChecked, $nextCheck, $id)
    );
}

/* ------------------------------------------------------- dashboard reads */

/**
 * Issues that want attention today. THE DASHBOARD READS THROUGH THIS.
 *
 * Two kinds, and they are genuinely different questions:
 *   · every 'active' issue — you already decided it needs doing
 *   · every 'watching' issue whose next_check_on has arrived — you asked to be
 *     reminded to look at it again
 *
 * A range scan on the indexed column, never a date expression.
 *
 * @return list<array>
 */
function issues_needing_action(string $today): array
{
    $rows = q(
        "SELECT * FROM issues
          WHERE status = ?
             OR (status = ? AND next_check_on IS NOT NULL AND next_check_on <= ?)
          ORDER BY (severity IS NULL), severity DESC, next_check_on, noticed_on",
        array(ISSUE_ACTIVE, ISSUE_WATCHING, $today)
    )->fetchAll() ?: array();

    return issues_decorate(array_map('issue_row', $rows));
}

/**
 * Future check-backs, for the dashboard's forward timeline.
 *
 * ONLY THE NEXT ONE PER ISSUE, which is all that is stored. A maintenance task
 * genuinely recurs forever and is projected forward; an issue's next look-at
 * depends entirely on what you see when you look, so a chain of them would be
 * schedule this app never promised.
 *
 * @return list<array{on_date:string, id:int, title:string, severity:?int}>
 */
function issues_upcoming_checks(string $after, int $limit = 100): array
{
    $rows = q(
        "SELECT id, title, severity, next_check_on FROM issues
          WHERE status IN (?, ?)
            AND next_check_on IS NOT NULL
            AND next_check_on > ?
          ORDER BY next_check_on, id
          LIMIT " . max(1, $limit),
        array(ISSUE_WATCHING, ISSUE_ACTIVE, $after)
    )->fetchAll() ?: array();

    $out = array();
    foreach ($rows as $row) {
        $out[] = array(
            'on_date'  => (string) $row['next_check_on'],
            'id'       => (int) $row['id'],
            'title'    => (string) $row['title'],
            'severity' => $row['severity'] !== null ? (int) $row['severity'] : null,
        );
    }
    return $out;
}

/* ---------------------------------------------------------------- cleaning */

/** Trim to null. An empty note and no note are the same thing. */
function issue_clean_text($raw): ?string
{
    $text = trim((string) ($raw ?? ''));
    return $text === '' ? null : $text;
}

/**
 * 1-4, or null.
 *
 * Zero maps to NULL rather than being rejected: a `<select>` with an empty
 * option posts "" and a sloppy client posts 0, and both mean "not set". Out of
 * range also becomes NULL rather than clamping to 4 — inventing "Urgent" from
 * a bad value is worse than recording nothing.
 */
function issue_clean_severity($raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $n = (int) $raw;
    return ($n >= 1 && $n <= 4) ? $n : null;
}

/** A positive day count, or null. */
function issue_clean_interval($raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $n = (int) $raw;
    return $n > 0 ? min($n, 3650) : null;
}

/** A Y-m-d date, or null when it cannot be read. */
function issue_clean_date($raw): ?string
{
    $date = sw_parse_date(is_string($raw) ? $raw : null);
    return $date === null ? null : $date->format('Y-m-d');
}
