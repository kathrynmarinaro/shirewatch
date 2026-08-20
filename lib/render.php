<?php
/* Shared rendering for the things more than one screen draws.
 *
 * Issues, service records and (later) task completions all show galleries,
 * severity pills, star ratings and tag chips. Left to the screens there would
 * be four hand-written versions of each, and the first CSS change would fix
 * one of them.
 *
 * Same reasoning as Book Tracker's lib/render.php, which exists for the same
 * reason and is where this pattern comes from.
 *
 * EVERY FUNCTION HERE ESCAPES ITS OWN OUTPUT and returns a string rather than
 * echoing, so a caller can put one inside an attribute or a heading without
 * wondering whether it already escaped. */

declare(strict_types=1);

require_once __DIR__ . '/issues.php';

/**
 * One gallery tile.
 *
 * A real <a> to the detail image, so with no JavaScript at all tapping a photo
 * still shows you the photo. assets/lightbox.js reads href, data-original,
 * data-caption and data-date off it and upgrades that to the swipeable viewer
 * — the viewer is an enhancement, never the only route.
 *
 * A photo still in the queue renders as a labelled placeholder rather than a
 * broken image. That state is NORMAL and brief (uploads return before any
 * resizing starts), so it has to look deliberate.
 */
function render_photo(array $photo, ?string $date = null): string
{
    $classes = 'gallery-item';
    if ($photo['status'] !== 'ready' || $photo['thumb'] === null) {
        $classes .= $photo['status'] === 'failed' ? ' is-failed' : ' is-pending';
    }

    $html = '<li class="' . $classes . '" data-id="' . (int) $photo['id'] . '">';

    if ($photo['status'] === 'ready' && $photo['thumb'] !== null) {
        $html .= '<a href="' . h((string) $photo['detail']) . '"'
            . ' data-original="' . h((string) ($photo['original'] ?? '')) . '"'
            . ' data-caption="' . h((string) ($photo['caption'] ?? '')) . '"'
            . ' data-date="' . h($date === null ? '' : fmt_date($date)) . '">'
            . '<img src="' . h((string) $photo['thumb']) . '" alt="' . h((string) ($photo['caption'] ?? '')) . '" loading="lazy">'
            . '</a>';
    }

    if ($date !== null && $photo['status'] === 'ready') {
        /* The date over a timeline photo is what makes a strip of four read as
         * a sequence rather than as four pictures of a wall. */
        $html .= '<span class="gallery-date">' . h(fmt_date($date)) . '</span>';
    }

    return $html . '</li>';
}

/** A whole gallery, or '' when there is nothing to show. */
function render_gallery(array $photos, array $opts = array()): string
{
    $photos = array_values(array_filter($photos, static fn(array $p): bool => $p['kind'] === 'photo'));
    if ($photos === array()) {
        return '';
    }

    $classes = 'gallery' . (!empty($opts['strip']) ? ' is-strip' : '');
    $id      = isset($opts['id']) ? ' id="' . h($opts['id']) . '"' : '';
    $date    = $opts['date'] ?? null;

    $html = '<ul class="' . $classes . '"' . $id . '>';
    foreach ($photos as $photo) {
        $html .= render_photo($photo, $date);
    }
    return $html . '</ul>';
}

/** The document list — invoices and warranties. '' when there are none. */
function render_documents(array $media): string
{
    $docs = array_values(array_filter($media, static fn(array $m): bool => $m['kind'] === 'document'));
    if ($docs === array()) {
        return '';
    }

    $html = '<ul class="doclist">';
    foreach ($docs as $doc) {
        $html .= '<li><a class="doc" href="' . h((string) $doc['original']) . '" target="_blank" rel="noopener">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5"/></svg>'
            . '<span class="doc-name">' . h((string) ($doc['filename'] ?? 'Document')) . '</span>'
            . '</a></li>';
    }
    return $html . '</ul>';
}

/**
 * A severity pill, or '' for NULL.
 *
 * THE EMPTY STRING IS THE WHOLE POINT. Rendering unset severities as a grey
 * "Watch" pill would put something that looks like data on every issue where
 * none was recorded (CLAUDE.md).
 */
function render_severity(?int $severity): string
{
    $label = issue_severity_label($severity);
    if ($label === '') {
        return '';
    }
    return '<span class="sev sev-' . (int) $severity . '">' . h($label) . '</span>';
}

/**
 * A star rating, 1-5, or '' for NULL.
 *
 * $value may be fractional for a vendor AVERAGE; a per-job rating never is.
 * The half star is drawn by a clipped overlay rather than a half-star glyph,
 * because the two glyphs do not align at the same optical weight.
 */
function render_stars(?float $value, array $opts = array()): string
{
    if ($value === null || $value <= 0) {
        return '';
    }

    $html = '<span class="stars" role="img" aria-label="'
        . h(rtrim(rtrim(number_format($value, 1), '0'), '.')) . ' out of 5">';

    for ($i = 1; $i <= 5; $i++) {
        $class = 'star';
        if ($value >= $i) {
            $class .= ' is-on';
        } elseif ($value >= $i - 0.5) {
            $class .= ' is-half';
        }
        $html .= '<span class="' . $class . '" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.8 1.2 7L12 18l-6.2 3.2L7 14.2l-5-4.8 7-.9z"/></svg>'
            . '</span>';
    }
    $html .= '</span>';

    if (!empty($opts['number'])) {
        $html .= '<span class="stars-num">' . h(number_format($value, 1)) . '</span>';
    }
    return $html;
}

/** Tag chips, read-only. '' when there are none. */
function render_tags(array $tags, array $opts = array()): string
{
    if ($tags === array()) {
        return empty($opts['empty']) ? '' : '<span class="hint">' . h((string) $opts['empty']) . '</span>';
    }

    $html = '<div class="chips">';
    foreach ($tags as $tag) {
        $class = 'chip is-static' . ($tag['kind'] === TAG_LOCATION ? ' is-location' : '');
        $html .= '<span class="' . $class . '">' . h($tag['name']) . '</span>';
    }
    return $html . '</div>';
}

/**
 * A cost, or '' for NULL.
 *
 * NULL IS "NOT RECORDED", WHICH IS NOT THE SAME AS FREE (schema.sql). Rendering
 * it as $0.00 would turn every unrecorded cost into a claim that the work cost
 * nothing, which is the kind of thing you only notice when adding a year up.
 */
function render_cost(?string $cost): string
{
    if ($cost === null || $cost === '') {
        return '';
    }
    return '$' . number_format((float) $cost, 2);
}

/* ==================================================================
 * FILTER GROUPS
 * ------------------------------------------------------------------
 * Shared by the Issues, Maintenance and Vendors lists. Three copies of
 * this markup is three places to fix when the chip layout changes, and
 * the third one always gets missed.
 * ================================================================== */

/**
 * One collapsible group of filter chips.
 *
 * SELECTED CHIPS SORT TO THE FRONT so the filter you want to turn off is the
 * first thing under your thumb rather than somewhere down a scrolling list of
 * two dozen rooms. Everything else follows alphabetically. This happens at
 * RENDER time — nothing is stored, so clearing a filter drops the chip back
 * into place on the next load.
 *
 * OPEN when the group has an active filter, closed otherwise. A collapsed
 * group hiding a filter that is silently narrowing your list is how you end up
 * convinced the app has lost your data.
 *
 * The chips are LINKS, not buttons: the filter state lives in the URL, so it
 * survives a reload, can be bookmarked, and the back button steps through it.
 *
 * @param list<array>            $tags     from tags_of_kind()
 * @param list<int>              $selected currently-on tag ids
 * @param callable(int): string  $urlFor   given a tag id, the URL that toggles it
 */
function render_filter_group(string $label, array $tags, array $selected, callable $urlFor): string
{
    if ($tags === array()) {
        return '';
    }

    $on  = array();
    $off = array();
    foreach ($tags as $tag) {
        if (in_array($tag['id'], $selected, true)) {
            $on[] = $tag;
        } else {
            $off[] = $tag;
        }
    }

    /* Alphabetical within each half. The author's typed order for the seeded
     * rooms was off the top of her head, not a considered sequence, so
     * scanning beats preserving it. tools/sort-tags-alphabetically.php
     * renumbers sort_order to match, which keeps drag-reordering meaningful
     * for anyone who does want their own order later. */
    $byName = static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']);
    usort($on, $byName);
    usort($off, $byName);

    $html = '<details class="filter-group"' . ($on === array() ? '' : ' open') . '>'
        . '<summary class="filter-head">' . h($label);

    if ($on !== array()) {
        $html .= '<span class="filter-count">' . count($on) . '</span>';
    }

    $html .= '</summary><div class="filter-body">';

    foreach (array_merge($on, $off) as $tag) {
        $isOn = in_array($tag['id'], $selected, true);
        $html .= '<a class="chip' . ($isOn ? ' is-on' : '')
            . ($tag['kind'] === TAG_LOCATION ? ' is-location' : '') . '"'
            . ' href="' . h($urlFor($tag['id'])) . '"'
            . ($isOn ? ' aria-pressed="true"' : '')
            . '>' . h($tag['name']) . '</a>';
    }

    return $html . '</div></details>';
}

/**
 * The standard pair — Rooms then Systems — for the Issues and Maintenance
 * lists. Rooms first: "where is it" is how you remember a thing; "what kind of
 * thing is it" is how you group them afterwards.
 */
function render_filters(array $selected, callable $urlFor): string
{
    return '<div class="filters">'
        . render_filter_group('Rooms & areas', tags_of_kind(TAG_LOCATION), $selected, $urlFor)
        . render_filter_group('Systems', tags_of_kind(TAG_CATEGORY), $selected, $urlFor)
        . '</div>';
}

/**
 * The floating add button.
 *
 * Replaces the full-width button that sat under the list — which moved further
 * away the more entries you had, so the action got harder exactly as the app
 * got more useful.
 */
function render_fab(string $href, string $label): string
{
    return '<a class="fab" href="' . h($href) . '" aria-label="' . h($label) . '">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'
        . '</a>';
}

/**
 * The pencil at the right of a row.
 *
 * aria-hidden and not focusable on purpose: the whole row is already a link to
 * the same place, so this is a picture of what tapping does. A second tab stop
 * leading to the identical destination is noise in a keyboard walk.
 */
function render_row_edit(): string
{
    return '<span class="row-edit" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 5l4 4"/></svg>'
        . '</span>';
}
