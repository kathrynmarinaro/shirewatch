<?php
/* Tags: locations, categories and vendor work types.
 *
 * ---------------------------------------------------------------------------
 * NOTHING OUTSIDE THIS FILE WRITES SQL AGAINST tags OR THE FOUR JOIN TABLES.
 * ---------------------------------------------------------------------------
 *
 * Foundation owns this because every module touches tags — issues, tasks,
 * records and vendors all carry them. Left to the modules there would be four
 * versions of the same "fetch the tags for these ids" query, and the first
 * schema change would fix three of them.
 *
 * ---------------------------------------------------------------------------
 * ONE TABLE, THREE VOCABULARIES (DELEGATION-PLAN.md §2.1).
 * ---------------------------------------------------------------------------
 *
 * They are the same shape — a short editable list of names you pick several of
 * — so they are one table with a `kind` column and one picker component,
 * rather than three tables and three pickers that drift apart.
 *
 * ---------------------------------------------------------------------------
 * RENAMING IS A ONE-ROW UPDATE, AND THAT IS THE WHOLE POINT.
 * ---------------------------------------------------------------------------
 *
 * Everything links to tags.id and never to the text. So renaming "Emma Room"
 * is `UPDATE tags SET name = ?` and every issue, task and record already filed
 * under it follows — nothing to migrate, and no window where some rows say one
 * thing and some say another.
 *
 * The deliberate opposite is service_records.vendor_name, which IS a stored
 * string (schema.sql). A room that gets renamed is the same room; a vendor that
 * gets deleted is gone and the invoice still has to say who sent it. Different
 * questions, different answers — do not "make them consistent."
 *
 * PORTABILITY. MySQL is the production target; tools/test-harness.php runs
 * these functions against SQLite. Every statement below is in the intersection
 * of the two. */

declare(strict_types=1);

/* The three kinds, spelled once. They are an ENUM in the schema, so a typo is
 * a database error rather than a silent no-op — but a database error on a tag
 * picker is still a picker that does nothing, so callers use the constants. */
const TAG_LOCATION  = 'location';
const TAG_CATEGORY  = 'category';
const TAG_WORK_TYPE = 'work_type';

/** @return list<string> */
function tag_kinds(): array
{
    return array(TAG_LOCATION, TAG_CATEGORY, TAG_WORK_TYPE);
}

/**
 * Human label for a kind, for headings and the picker's tabs.
 * Falls back to the raw value rather than throwing — an unknown kind is a
 * degraded heading, not a dead screen.
 */
function tag_kind_label(string $kind): string
{
    $labels = array(
        TAG_LOCATION  => 'Rooms & areas',
        TAG_CATEGORY  => 'Systems',
        TAG_WORK_TYPE => 'Work types',
    );
    return $labels[$kind] ?? $kind;
}

/**
 * Is this kind scoped to a property?
 *
 * Locations are (rooms differ per house); systems and trades are not (plumbing
 * is plumbing everywhere). This function is the ONLY place that asymmetry is
 * expressed — see the comment on tags.property_id in schema.sql.
 */
function tag_kind_is_scoped(string $kind): bool
{
    return $kind === TAG_LOCATION;
}

/* ------------------------------------------------------------------ reads */

/**
 * Every tag of one kind, in sort order then name.
 *
 * Name is the tiebreaker so that two tags sharing a sort_order — which happens
 * the moment you add one, since new tags all land on the same default — come
 * back in a stable order instead of whatever the storage engine felt like.
 *
 * @return list<array{id:int, kind:string, name:string, sort_order:int}>
 */
function tags_of_kind(string $kind, int $propertyId = 1): array
{
    if (!in_array($kind, tag_kinds(), true)) {
        return array();
    }

    $rows = tag_kind_is_scoped($kind)
        ? q(
            'SELECT id, kind, name, sort_order FROM tags
              WHERE kind = ? AND property_id = ?
              ORDER BY sort_order, name',
            array($kind, $propertyId)
        )->fetchAll()
        : q(
            'SELECT id, kind, name, sort_order FROM tags
              WHERE kind = ? AND property_id IS NULL
              ORDER BY sort_order, name',
            array($kind)
        )->fetchAll();

    return array_map('tag_row', $rows ?: array());
}

/** Normalize one database row into the shape every caller expects. */
function tag_row(array $row): array
{
    return array(
        'id'         => (int) $row['id'],
        'kind'       => (string) $row['kind'],
        'name'       => (string) $row['name'],
        'sort_order' => (int) ($row['sort_order'] ?? 100),
    );
}

/** One tag by id, or null. */
function tag_by_id(int $id): ?array
{
    $row = q('SELECT id, kind, name, sort_order FROM tags WHERE id = ?', array($id))->fetch();
    return $row === false ? null : tag_row($row);
}

/**
 * The join table and its item column for one taggable thing.
 *
 * A WHITELIST, and the reason every function below can interpolate its result
 * into SQL: these are the only four values that ever reach a query, and an
 * unknown $type returns null rather than a table name. Nothing a caller passes
 * can become SQL.
 *
 * @return array{0:string,1:string}|null  [table, item column]
 */
function tag_link_table(string $type): ?array
{
    $map = array(
        'issue'  => array('issue_tags',  'issue_id'),
        'task'   => array('task_tags',   'task_id'),
        'record' => array('record_tags', 'record_id'),
        'vendor' => array('vendor_tags', 'vendor_id'),
    );
    return $map[$type] ?? null;
}

/**
 * Tags attached to ONE item.
 *
 * @return list<array{id:int, kind:string, name:string, sort_order:int}>
 */
function tags_for(string $type, int $itemId): array
{
    $found = tags_for_many($type, array($itemId));
    return $found[$itemId] ?? array();
}

/**
 * Tags for MANY items at once, keyed by item id.
 *
 * THIS IS THE FUNCTION LIST SCREENS MUST USE. Calling tags_for() inside a loop
 * over forty issues is forty round trips, and it is the single easiest way to
 * make this app feel slow on a phone. Items with no tags are present in the
 * result with an empty array, so a caller can index into it without checking.
 *
 * @param list<int> $itemIds
 * @return array<int, list<array{id:int, kind:string, name:string, sort_order:int}>>
 */
function tags_for_many(string $type, array $itemIds): array
{
    $link = tag_link_table($type);
    if ($link === null) {
        return array();
    }
    list($table, $column) = $link;

    $ids = array_values(array_unique(array_map('intval', $itemIds)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

    $out = array();
    foreach ($ids as $id) {
        $out[$id] = array();
    }
    if ($ids === array()) {
        return $out;
    }

    /* Placeholders are generated from the COUNT of ids, never from the ids
     * themselves, and the values still go through the bound array. The only
     * thing interpolated is `?, ?, ?` and two whitelisted identifiers. */
    $holes = implode(', ', array_fill(0, count($ids), '?'));

    $rows = q(
        "SELECT l.$column AS item_id, t.id, t.kind, t.name, t.sort_order
           FROM $table l
           JOIN tags t ON t.id = l.tag_id
          WHERE l.$column IN ($holes)
          ORDER BY t.kind, t.sort_order, t.name",
        $ids
    )->fetchAll();

    foreach ($rows ?: array() as $row) {
        $out[(int) $row['item_id']][] = tag_row($row);
    }
    return $out;
}

/** Just the names, for a compact row of pills. @return list<string> */
function tag_names(array $tags, ?string $kind = null): array
{
    $out = array();
    foreach ($tags as $tag) {
        if ($kind === null || ($tag['kind'] ?? '') === $kind) {
            $out[] = (string) $tag['name'];
        }
    }
    return $out;
}

/* ----------------------------------------------------------------- writes */

/**
 * Replace an item's tags with exactly $tagIds. Returns the ids actually set.
 *
 * DELETE-THEN-INSERT rather than a diff, deliberately. The set is a handful of
 * rows, the join tables have no columns of their own worth preserving, and a
 * diff is three queries and an off-by-one waiting to happen. The whole thing
 * runs in a transaction so a failure halfway cannot leave an item with half
 * its tags.
 *
 * Ids that do not exist are DROPPED SILENTLY, not rejected. A picker showing a
 * tag that was deleted in another tab should cost you that one chip, not the
 * whole save. The return value is what was really stored, so a caller that
 * cares can compare.
 *
 * @param list<int> $tagIds
 * @return list<int>
 */
function tags_set(string $type, int $itemId, array $tagIds): array
{
    $link = tag_link_table($type);
    if ($link === null || $itemId <= 0) {
        return array();
    }
    list($table, $column) = $link;

    $wanted = array_values(array_unique(array_map('intval', $tagIds)));
    $wanted = array_values(array_filter($wanted, static fn(int $id): bool => $id > 0));

    $valid = array();
    if ($wanted !== array()) {
        $holes = implode(', ', array_fill(0, count($wanted), '?'));
        $rows  = q("SELECT id FROM tags WHERE id IN ($holes)", $wanted)->fetchAll();
        foreach ($rows ?: array() as $row) {
            $valid[] = (int) $row['id'];
        }
    }

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        q("DELETE FROM $table WHERE $column = ?", array($itemId));
        foreach ($valid as $tagId) {
            q("INSERT INTO $table ($column, tag_id) VALUES (?, ?)", array($itemId, $tagId));
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $valid;
}

/**
 * Create a tag. Returns its id, or the EXISTING id if the name is taken.
 *
 * Idempotent on (kind, property_id, name) because the schema's unique key says
 * so, which is what makes a double-tapped Add button harmless and what makes
 * the seed INSERTs re-runnable.
 *
 * The name is stored EXACTLY as given apart from trimming the whitespace a
 * phone keyboard adds. No title-casing, no canonical form, no de-duplication
 * on a normalized key (§2.12) — "Ext Studio" stays "Ext Studio".
 */
function tag_add(string $kind, string $name, int $propertyId = 1): ?int
{
    if (!in_array($kind, tag_kinds(), true)) {
        return null;
    }
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $name = mb_substr($name, 0, 80, 'UTF-8');

    $scoped = tag_kind_is_scoped($kind) ? $propertyId : null;

    $existing = tag_find($kind, $name, $propertyId);
    if ($existing !== null) {
        return $existing['id'];
    }

    q(
        'INSERT INTO tags (kind, property_id, name, sort_order) VALUES (?, ?, ?, ?)',
        array($kind, $scoped, $name, 100)
    );
    return (int) db()->lastInsertId();
}

/** An existing tag with this exact name, or null. */
function tag_find(string $kind, string $name, int $propertyId = 1): ?array
{
    $row = tag_kind_is_scoped($kind)
        ? q(
            'SELECT id, kind, name, sort_order FROM tags
              WHERE kind = ? AND property_id = ? AND name = ?',
            array($kind, $propertyId, $name)
        )->fetch()
        : q(
            'SELECT id, kind, name, sort_order FROM tags
              WHERE kind = ? AND property_id IS NULL AND name = ?',
            array($kind, $name)
        )->fetch();

    return $row === false ? null : tag_row($row);
}

/**
 * Rename a tag in place. True if it changed.
 *
 * ONE UPDATE, AND EVERY ITEM ALREADY TAGGED FOLLOWS IT. This is the behaviour
 * the foreign key exists to give: nothing is copied, nothing is migrated, and
 * there is no moment where the old and new names both appear.
 *
 * Returns false rather than throwing when the new name collides with a sibling
 * of the same kind — merging two tags is a different operation with a different
 * consequence (every item on the loser has to move), and doing it by accident
 * on a rename would be silent data loss.
 */
function tag_rename(int $id, string $name): bool
{
    $tag  = tag_by_id($id);
    $name = mb_substr(trim($name), 0, 80, 'UTF-8');
    if ($tag === null || $name === '') {
        return false;
    }
    if ($name === $tag['name']) {
        return true;
    }

    $clash = tag_find($tag['kind'], $name, tag_property_of($id) ?? 1);
    if ($clash !== null && $clash['id'] !== $id) {
        return false;
    }

    q('UPDATE tags SET name = ? WHERE id = ?', array($name, $id));
    return true;
}

/** The property a tag is scoped to, or null for an unscoped kind. */
function tag_property_of(int $id): ?int
{
    $row = q('SELECT property_id FROM tags WHERE id = ?', array($id))->fetch();
    if ($row === false || $row['property_id'] === null) {
        return null;
    }
    return (int) $row['property_id'];
}

/**
 * Delete a tag. It lifts off every item it was on, by cascade.
 *
 * No confirmation logic and no "in use" guard here — that is the caller's
 * screen to build. What this guarantees is that the join rows go with it
 * rather than being left pointing at nothing.
 */
function tag_delete(int $id): bool
{
    return q('DELETE FROM tags WHERE id = ?', array($id))->rowCount() > 0;
}

/**
 * Write a new order for a list of tag ids, in the order given.
 *
 * Spaced by tens, so a later single insertion between two of them has room to
 * land without renumbering the whole list.
 *
 * @param list<int> $orderedIds
 */
function tags_reorder(array $orderedIds): void
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $n = 10;
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            q('UPDATE tags SET sort_order = ? WHERE id = ?', array($n, $id));
            $n += 10;
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * How many items of each type carry this tag.
 *
 * For the delete confirmation on the Rooms & Tags screen: "Kitchen is on 6
 * issues and 2 tasks" is the difference between an informed delete and an
 * undoable-looking one that quietly detaches six things.
 *
 * @return array{issue:int, task:int, record:int, vendor:int}
 */
function tag_usage(int $id): array
{
    $out = array();
    foreach (array('issue', 'task', 'record', 'vendor') as $type) {
        list($table, $column) = tag_link_table($type);
        $out[$type] = (int) q("SELECT COUNT(*) FROM $table WHERE tag_id = ?", array($id))->fetchColumn();
    }
    return $out;
}
