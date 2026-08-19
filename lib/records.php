<?php
/* Service and repair records: what was done, by whom, for how much.
 *
 * NOTHING OUTSIDE THIS FILE WRITES SQL AGAINST service_records.
 *
 * ---------------------------------------------------------------------------
 * THE VENDOR NAME IS SNAPSHOTTED ON WRITE.
 * ---------------------------------------------------------------------------
 *
 * vendor_id is a real foreign key AND vendor_name is a copy of the name at the
 * time. Deleting a vendor nulls the link and leaves the string, so the 2023
 * invoice still says who sent it (schema.sql).
 *
 * It is NOT kept in sync afterwards. A vendor renaming themselves means
 * "they changed their name", not "this invoice came from somebody else" — but
 * the record of who you actually dealt with in 2023 is the name that was on
 * the invoice. Do not add a sync.
 *
 * ---------------------------------------------------------------------------
 * TWO LINKS TO AN ISSUE, POINTING OPPOSITE WAYS, AND BOTH BELONG.
 * ---------------------------------------------------------------------------
 *
 *   service_records.issue_id       "this work relates to that issue"
 *   issues.resolved_by_record_id   "this record is what FIXED it"
 *
 * Three visits can reference one issue while only one of them resolved it.
 * This file owns the first; lib/issues.php owns the second.
 */

declare(strict_types=1);

require_once __DIR__ . '/tags.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/vendors.php';

function record_row(array $row): array
{
    return array(
        'id'           => (int) $row['id'],
        'vendor_id'    => $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
        'vendor_name'  => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : '',
        'title'        => (string) $row['title'],
        'description'  => $row['description'] !== null ? (string) $row['description'] : '',
        'performed_on' => (string) $row['performed_on'],
        'cost'         => $row['cost'] !== null ? (string) $row['cost'] : null,
        'rating'       => $row['rating'] !== null ? (int) $row['rating'] : null,
        'issue_id'     => $row['issue_id'] !== null ? (int) $row['issue_id'] : null,
        'task_id'      => $row['task_id'] !== null ? (int) $row['task_id'] : null,
        'created_at'   => (string) $row['created_at'],
    );
}

function record_get(int $id): ?array
{
    $row = q('SELECT * FROM service_records WHERE id = ?', array($id))->fetch();
    if ($row === false) {
        return null;
    }
    $record = record_row($row);
    $record['tags']  = tags_for('record', $id);
    $record['media'] = media_for('record', $id);
    return $record;
}

/**
 * The history, newest work first.
 *
 * @param array $filters { vendor_id?, issue_id?, task_id?, tag_ids?, search?,
 *                         year? }
 * @return list<array>  decorated with tags and media
 */
function records_list(array $filters = array()): array
{
    $where  = array('1 = 1');
    $params = array();

    foreach (array('vendor_id', 'issue_id', 'task_id') as $key) {
        if (!empty($filters[$key])) {
            $where[]  = "r.$key = ?";
            $params[] = (int) $filters[$key];
        }
    }

    if (!empty($filters['year'])) {
        /* A string range, not YEAR(performed_on) — a function on the column is
         * not sargable, and the harness cannot evaluate it either. */
        $year     = (int) $filters['year'];
        $where[]  = 'r.performed_on BETWEEN ? AND ?';
        $params[] = $year . '-01-01';
        $params[] = $year . '-12-31';
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[]  = '(r.title LIKE ? OR r.description LIKE ? OR r.vendor_name LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $tagIds = array_values(array_filter(array_map('intval', $filters['tag_ids'] ?? array())));
    $join   = '';
    $group  = '';
    if ($tagIds !== array()) {
        $holes  = implode(', ', array_fill(0, count($tagIds), '?'));
        $join   = " JOIN record_tags rt ON rt.record_id = r.id AND rt.tag_id IN ($holes)";
        $group  = ' GROUP BY r.id HAVING COUNT(DISTINCT rt.tag_id) = ' . count($tagIds);
        $params = array_merge($tagIds, $params);
    }

    $rows = q(
        'SELECT r.* FROM service_records r' . $join
        . ' WHERE ' . implode(' AND ', $where) . $group
        . ' ORDER BY r.performed_on DESC, r.id DESC',
        $params
    )->fetchAll() ?: array();

    return records_decorate(array_map('record_row', $rows));
}

/** Tags and media for a list, in two queries rather than two per row. */
function records_decorate(array $records): array
{
    if ($records === array()) {
        return array();
    }
    $ids   = array_column($records, 'id');
    $tags  = tags_for_many('record', $ids);
    $media = media_for_many('record', $ids);

    foreach ($records as $index => $record) {
        $records[$index]['tags']  = $tags[$record['id']] ?? array();
        $records[$index]['media'] = $media[$record['id']] ?? array();
    }
    return $records;
}

/** The years that have records in them, newest first — for the history filter. */
function records_years(): array
{
    $rows = q('SELECT DISTINCT performed_on FROM service_records ORDER BY performed_on DESC')
        ->fetchAll(PDO::FETCH_COLUMN) ?: array();

    $years = array();
    foreach ($rows as $date) {
        $year = (int) substr((string) $date, 0, 4);
        if (!in_array($year, $years, true)) {
            $years[] = $year;
        }
    }
    return $years;
}

/* ----------------------------------------------------------------- writes */

/** Create or update. Returns the id. */
function record_save(?int $id, array $data): int
{
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('empty_title');
    }

    $vendorId = isset($data['vendor_id']) && (int) $data['vendor_id'] > 0
        ? (int) $data['vendor_id']
        : null;

    /* The snapshot is taken from the vendor row at write time. When there is no
     * vendor, an explicitly typed name is still kept — plenty of work is done
     * by somebody who is not in the directory and never will be. */
    $vendorName = null;
    if ($vendorId !== null) {
        $vendor = vendor_get($vendorId);
        if ($vendor === null) {
            $vendorId = null;
        } else {
            $vendorName = $vendor['name'];
        }
    }
    if ($vendorName === null) {
        $vendorName = vendor_clean($data['vendor_name'] ?? null, 160);
    }

    $fields = array(
        $vendorId,
        $vendorName,
        mb_substr($title, 0, 200, 'UTF-8'),
        vendor_clean($data['description'] ?? null, 65535),
        record_clean_date($data['performed_on'] ?? null) ?? sw_today(),
        record_clean_cost($data['cost'] ?? null),
        vendor_clean_rating($data['rating'] ?? null),
        record_clean_link($data['issue_id'] ?? null),
        record_clean_link($data['task_id'] ?? null),
    );

    if ($id !== null && $id > 0 && record_get($id) !== null) {
        q('UPDATE service_records
              SET vendor_id = ?, vendor_name = ?, title = ?, description = ?, performed_on = ?,
                  cost = ?, rating = ?, issue_id = ?, task_id = ?
            WHERE id = ?',
            array_merge($fields, array($id)));
    } else {
        q('INSERT INTO service_records
             (property_id, vendor_id, vendor_name, title, description, performed_on, cost, rating, issue_id, task_id)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge(array(1), $fields));
        $id = (int) db()->lastInsertId();
    }

    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        tags_set('record', $id, $data['tag_ids']);
    }
    return $id;
}

/**
 * Delete a record, every file behind it, and any reference to it.
 *
 * issues.resolved_by_record_id HAS NO FOREIGN KEY behind it — it would have to
 * point forward at a table created later in schema.sql, and SQLite cannot add
 * one by ALTER, so a database-enforced version would not exist in the tests.
 * Clearing it here is the enforcement, and it is why nothing outside this file
 * may delete a service_records row.
 *
 * The issue itself is left standing. Deleting the invoice is not the same as
 * deciding the crack was never fixed.
 */
function record_delete(int $id): bool
{
    if (record_get($id) === null) {
        return false;
    }

    foreach (media_for('record', $id) as $item) {
        media_delete($item['id']);
    }

    q('UPDATE issues SET resolved_by_record_id = NULL WHERE resolved_by_record_id = ?', array($id));
    q('UPDATE task_completions SET service_record_id = NULL WHERE service_record_id = ?', array($id));

    q('DELETE FROM service_records WHERE id = ?', array($id));
    return true;
}

/**
 * A cost, or NULL.
 *
 * NULL IS "NOT RECORDED", WHICH IS NOT THE SAME AS FREE (schema.sql). An empty
 * field therefore stores NULL and a typed 0 stores 0.00 — the second is a
 * claim that the work cost nothing, and only one of them should render as $0.
 */
function record_clean_cost($raw): ?string
{
    if ($raw === null || trim((string) $raw) === '') {
        return null;
    }
    /* Strip whatever a phone keyboard put in — currency symbols, thousands
     * separators, stray spaces — before deciding it is not a number. */
    $clean = preg_replace('/[^0-9.\-]/', '', (string) $raw) ?? '';
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }
    return number_format(max(0, (float) $clean), 2, '.', '');
}

function record_clean_date($raw): ?string
{
    $date = sw_parse_date(is_string($raw) ? $raw : null);
    return $date === null ? null : $date->format('Y-m-d');
}

function record_clean_link($raw): ?int
{
    $n = (int) ($raw ?? 0);
    return $n > 0 ? $n : null;
}
