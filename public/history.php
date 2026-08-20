<?php
/* Service history — everything that has been done to the house.
 *
 * Reached from the hamburger rather than the tab bar: it is almost always
 * reached FROM an issue or a vendor rather than browsed. It is a real URL, so
 * nothing in the menu is the only way to anything.
 *
 * Grouped by year, because "what did we spend on the house in 2025" is the
 * question this screen gets asked, and a flat reverse-chronological list makes
 * you find the year boundaries yourself.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/records.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today  = sw_today();
$year   = (int) ($_GET['year'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$tagIds = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));

$records = records_list(array(
    'year'    => $year ?: null,
    'search'  => $search,
    'tag_ids' => $tagIds,
));

$years = records_years();

/* Grouped in one pass. The subtotal is per year and is deliberately NOT a
 * grand total across years: the brief puts budgeting and cost analytics out of
 * scope, and a running lifetime figure is the first step onto that road. */
/* NOTE the (string) casts on the key below. PHP silently turns a numeric
 * string array key into an int, so "2026" comes back out of this array as
 * 2026 — and h() is typed ?string, which makes that a fatal TypeError on a
 * screen that otherwise looks finished. */
$byYear = array();
foreach ($records as $record) {
    $byYear[substr($record['performed_on'], 0, 4)][] = $record;
}

function history_url(array $overrides): string
{
    $params = array();
    if (($_GET['q'] ?? '') !== '')    { $params['q'] = $_GET['q']; }
    if ((int) ($_GET['year'] ?? 0))   { $params['year'] = (int) $_GET['year']; }
    $tags = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));
    if ($tags !== array()) { $params['tag'] = $tags; }

    foreach ($overrides as $key => $value) {
        if ($value === null) { unset($params[$key]); } else { $params[$key] = $value; }
    }
    $qs = http_build_query($params);
    return 'history.php' . ($qs === '' ? '' : '?' . $qs);
}

page_head('Service history', null);
screen_head('Service history', page_menu());
?>

  <form class="composer" method="get" action="history.php">
    <input class="composer-input" type="search" name="q" value="<?= h($search) ?>"
           placeholder="Search work, vendors, notes" autocomplete="off" aria-label="Search history">
    <button class="composer-add" type="submit">Find</button>
  </form>

<?php if ($years !== array()): ?>
  <div class="filterbar" role="group" aria-label="Year">
    <a class="chip<?= $year === 0 ? ' is-on' : '' ?>" href="<?= h(history_url(array('year' => null))) ?>">All years</a>
    <?php foreach ($years as $y): ?>
      <a class="chip<?= $year === $y ? ' is-on' : '' ?>" href="<?= h(history_url(array('year' => $y))) ?>"><?= $y ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($records === array()): ?>
  <p class="empty">
    <?= $search !== '' || $year !== 0 || $tagIds !== array()
        ? 'Nothing matches those filters.'
        : 'Nothing logged yet. Add a record when you next pay somebody.' ?>
  </p>
<?php endif; ?>

<?php foreach ($byYear as $groupYear => $group): ?>
  <?php
    /* NULL costs are skipped rather than counted as zero — "not recorded" is
       not "free" (CLAUDE.md), and a subtotal that silently treats them as zero
       is a number you would trust and shouldn't. */
    $known = array_filter($group, static fn(array $r): bool => $r['cost'] !== null);
    $sum   = array_sum(array_map(static fn(array $r): float => (float) $r['cost'], $known));
    $unpriced = count($group) - count($known);
  ?>
  <section class="cat-group">
    <h2 class="cat-head">
      <?= h((string) $groupYear) ?>
      <span class="cat-count">
        <?= $known === array() ? count($group) . ' jobs' : '$' . number_format($sum, 0) ?>
      </span>
    </h2>
    <?php if ($unpriced > 0 && $known !== array()): ?>
      <p class="hint"><?= $unpriced ?> without a recorded cost, not counted.</p>
    <?php endif; ?>

    <ul class="list">
    <?php foreach ($group as $record): ?>
      <li class="list-row" data-id="<?= (int) $record['id'] ?>">
        <div class="row-slide">
          <a class="row-body" href="record.php?id=<?= (int) $record['id'] ?>">
            <span class="row-text"><?= h($record['title']) ?></span>
            <span class="row-sub">
              <?= h(fmt_date($record['performed_on'], 'j M')) ?>
              <?= $record['vendor_name'] === '' ? '' : ' · ' . h($record['vendor_name']) ?>
              <?php $cost = render_cost($record['cost']); ?>
              <?= $cost === '' ? '' : ' · ' . h($cost) ?>
            </span>
          </a>
          <?= render_stars($record['rating'] === null ? null : (float) $record['rating']) ?>
          <?= render_row_edit() ?>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>

<?= render_fab('record.php?new=1', 'Log some work') ?>

<script type="module" src="<?= asset('assets/history.js') ?>"></script>
<?php
page_foot(null);
