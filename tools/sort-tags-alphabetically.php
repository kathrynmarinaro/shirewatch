<?php
/* Renumber a kind's sort_order so the list reads alphabetically.
 *
 *   php tools/sort-tags-alphabetically.php [location|category|work_type] [--dry-run]
 *
 * The seeded rooms went in as they were typed — off the top of the author's
 * head rather than a considered sequence — and scanning two dozen of them for
 * one name is much easier alphabetically.
 *
 * WHY THIS RENUMBERS RATHER THAN THE QUERIES SORTING BY NAME. Sorting by name
 * in the queries would silently make the drag handles on the Rooms & Tags
 * screen do nothing: you would reorder a list and watch it snap back. Writing
 * the order into sort_order keeps ONE rule everywhere — the list is in
 * sort_order — and simply makes alphabetical the starting point.
 *
 * Safe to run whenever the list has drifted. It touches sort_order and nothing
 * else, so no name changes and nothing is un-tagged.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/tags.php';

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$kinds  = array_values(array_filter($args, static fn(string $a): bool => in_array($a, tag_kinds(), true)));

if ($kinds === array()) {
    $kinds = tag_kinds();
}

foreach ($kinds as $kind) {
    $tags = tags_of_kind($kind);
    if ($tags === array()) {
        continue;
    }

    usort($tags, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    printf("\n%s (%d)\n%s\n", tag_kind_label($kind), count($tags), str_repeat('-', 28));
    foreach ($tags as $tag) {
        printf("  %s\n", $tag['name']);
    }

    if (!$dryRun) {
        /* Spaced by tens by tags_reorder(), so a later single insertion has
         * somewhere to land without renumbering the whole list again. */
        tags_reorder(array_column($tags, 'id'));
    }
}

printf("\n%s\n", $dryRun ? 'DRY RUN — nothing written.' : 'Reordered.');
