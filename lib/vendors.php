<?php
/* The vendor directory, and the ratings derived from their work.
 *
 * NOTHING OUTSIDE THIS FILE WRITES SQL AGAINST vendors.
 *
 * ---------------------------------------------------------------------------
 * A VENDOR'S RATING IS COMPUTED, NEVER STORED.
 * ---------------------------------------------------------------------------
 *
 * It is AVG() over that vendor's rated service records — a handful of rows —
 * and caching it would be a denormalization with a sync bug attached and
 * nothing to buy with it. Every path that writes a record would have to
 * remember to recompute, and the one that forgot would be invisible.
 *
 * rating_override is NULL = "use the average", non-NULL = "I've decided". The
 * average is shown BESIDE an override rather than replaced by it, so an
 * override never hides what it is overriding — that is how you forget you set
 * one.
 *
 * ---------------------------------------------------------------------------
 * WORK HISTORY IS NOT MAINTAINED BY HAND.
 * ---------------------------------------------------------------------------
 *
 * The brief asks for it to be "automatically populated from linked service
 * records", so there is no vendor_jobs table and no way to add a job to a
 * vendor except by logging the service record. One source of truth.
 */

declare(strict_types=1);

require_once __DIR__ . '/tags.php';

function vendor_row(array $row): array
{
    return array(
        'id'              => (int) $row['id'],
        'name'            => (string) $row['name'],
        'phone'           => $row['phone'] !== null ? (string) $row['phone'] : '',
        'email'           => $row['email'] !== null ? (string) $row['email'] : '',
        'notes'           => $row['notes'] !== null ? (string) $row['notes'] : '',
        'rating_override' => $row['rating_override'] !== null ? (int) $row['rating_override'] : null,
        'created_at'      => (string) $row['created_at'],
    );
}

function vendor_get(int $id): ?array
{
    $row = q('SELECT * FROM vendors WHERE id = ?', array($id))->fetch();
    if ($row === false) {
        return null;
    }
    $vendor = vendor_row($row);
    $vendor['tags'] = tags_for('vendor', $id);
    return array_merge($vendor, vendor_rating($id));
}

/**
 * The whole directory, with ratings and job counts attached in three queries.
 *
 * @param array $filters { tag_ids?: list<int>, search?: string }
 * @return list<array>
 */
function vendors_list(array $filters = array()): array
{
    $where  = array('1 = 1');
    $params = array();

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[]  = '(v.name LIKE ? OR v.notes LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $tagIds = array_values(array_filter(array_map('intval', $filters['tag_ids'] ?? array())));
    $join   = '';
    $group  = '';
    if ($tagIds !== array()) {
        $holes  = implode(', ', array_fill(0, count($tagIds), '?'));
        $join   = " JOIN vendor_tags vt ON vt.vendor_id = v.id AND vt.tag_id IN ($holes)";
        $group  = ' GROUP BY v.id HAVING COUNT(DISTINCT vt.tag_id) = ' . count($tagIds);
        $params = array_merge($tagIds, $params);
    }

    $rows = q(
        'SELECT v.* FROM vendors v' . $join . ' WHERE ' . implode(' AND ', $where) . $group
        . ' ORDER BY v.name',
        $params
    )->fetchAll() ?: array();

    if ($rows === array()) {
        return array();
    }

    $vendors = array_map('vendor_row', $rows);
    $ids     = array_column($vendors, 'id');
    $tags    = tags_for_many('vendor', $ids);
    $ratings = vendor_ratings_for($ids);

    foreach ($vendors as $index => $vendor) {
        $vendors[$index]['tags'] = $tags[$vendor['id']] ?? array();
        $vendors[$index] += $ratings[$vendor['id']] ?? vendor_empty_rating();
    }
    return $vendors;
}

function vendor_empty_rating(): array
{
    return array('average' => null, 'rated_jobs' => 0, 'jobs' => 0, 'rating' => null, 'total_cost' => null);
}

/**
 * Rating and job counts for one vendor.
 *
 * `rating` is what to SHOW: the override when there is one, otherwise the
 * average. `average` is kept separately so the screen can print both when they
 * differ.
 */
function vendor_rating(int $id): array
{
    $found = vendor_ratings_for(array($id));
    return $found[$id] ?? vendor_empty_rating();
}

/**
 * The same, batched. One query for the aggregates, one for the overrides.
 *
 * COUNT(rating) counts only non-NULL, which is the distinction that matters:
 * a vendor with four jobs and no ratings has an average of NULL, not zero
 * (schema.sql). AVG() ignores NULLs for the same reason.
 *
 * @param list<int> $ids
 * @return array<int, array>
 */
function vendor_ratings_for(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === array()) {
        return array();
    }
    $holes = implode(', ', array_fill(0, count($ids), '?'));

    $rows = q(
        "SELECT vendor_id,
                COUNT(*)       AS jobs,
                COUNT(rating)  AS rated_jobs,
                AVG(rating)    AS average,
                SUM(cost)      AS total_cost
           FROM service_records
          WHERE vendor_id IN ($holes)
          GROUP BY vendor_id",
        $ids
    )->fetchAll() ?: array();

    $out = array();
    foreach ($ids as $id) {
        $out[$id] = vendor_empty_rating();
    }
    foreach ($rows as $row) {
        $id = (int) $row['vendor_id'];
        $out[$id]['jobs']       = (int) $row['jobs'];
        $out[$id]['rated_jobs'] = (int) $row['rated_jobs'];
        $out[$id]['average']    = $row['rated_jobs'] > 0 ? round((float) $row['average'], 2) : null;
        $out[$id]['total_cost'] = $row['total_cost'] !== null ? (string) $row['total_cost'] : null;
    }

    $overrides = q("SELECT id, rating_override FROM vendors WHERE id IN ($holes)", $ids)->fetchAll() ?: array();
    foreach ($overrides as $row) {
        $id = (int) $row['id'];
        $override = $row['rating_override'] !== null ? (int) $row['rating_override'] : null;
        $out[$id]['rating'] = $override !== null ? (float) $override : $out[$id]['average'];
    }

    return $out;
}

/* ----------------------------------------------------------------- writes */

function vendor_save(?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('empty_name');
    }

    $fields = array(
        mb_substr($name, 0, 160, 'UTF-8'),
        vendor_clean(($data['phone'] ?? null), 40),
        vendor_clean(($data['email'] ?? null), 255),
        vendor_clean(($data['notes'] ?? null), 65535),
        vendor_clean_rating($data['rating_override'] ?? null),
    );

    if ($id !== null && $id > 0 && vendor_get($id) !== null) {
        q('UPDATE vendors SET name = ?, phone = ?, email = ?, notes = ?, rating_override = ? WHERE id = ?',
            array_merge($fields, array($id)));
    } else {
        q('INSERT INTO vendors (property_id, name, phone, email, notes, rating_override) VALUES (?, ?, ?, ?, ?, ?)',
            array_merge(array(1), $fields));
        $id = (int) db()->lastInsertId();
    }

    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        tags_set('vendor', $id, $data['tag_ids']);
    }
    return $id;
}

/**
 * Delete a vendor. THEIR WORK HISTORY SURVIVES.
 *
 * service_records.vendor_id is ON DELETE SET NULL and vendor_name is a
 * snapshot string written on insert, so the 2023 invoice still says who sent
 * it (schema.sql). This is the deliberate opposite of the tag rename, ten
 * tables away — a room that gets renamed is the same room; a vendor that gets
 * deleted is gone but the money you paid them is not.
 */
function vendor_delete(int $id): bool
{
    if (vendor_get($id) === null) {
        return false;
    }
    q('DELETE FROM vendors WHERE id = ?', array($id));
    return true;
}

function vendor_clean($raw, int $max): ?string
{
    $text = trim((string) ($raw ?? ''));
    return $text === '' ? null : mb_substr($text, 0, $max, 'UTF-8');
}

/** 1-5, or NULL for "use the average". Zero and out-of-range are NULL. */
function vendor_clean_rating($raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $n = (int) $raw;
    return ($n >= 1 && $n <= 5) ? $n : null;
}
