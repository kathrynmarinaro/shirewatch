<?php
/* Service — every time somebody was paid to do something to the house.
 *
 * Reached from the hamburger, not the tab bar: this is a view you consult
 * ("what did the roof cost us?") rather than a place you work. The working
 * screens are Log and Maintenance.
 *
 * ---------------------------------------------------------------------------
 * IT READS FROM TWO PLACES AND SAYS SO.
 * ---------------------------------------------------------------------------
 *
 * A visit that fixed something is an update on a log entry; routine paid work
 * is a maintenance completion that cost money. lib/service.php is the only
 * code that unions them, and each row here says which it was and links back to
 * the thing it belonged to — because "the AC repair" and "the annual service"
 * are different memories even when they cost the same.
 *
 * Grouped by year with totals, because "what did we spend on the house in
 * 2025" is the question this screen actually gets asked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/service.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today    = sw_today();
$year     = (int) ($_GET['year'] ?? 0);
$vendorId = (int) ($_GET['vendor'] ?? 0);
$search   = trim((string) ($_GET['q'] ?? ''));

$rows = service_list(array(
    'year'      => $year ?: null,
    'vendor_id' => $vendorId ?: null,
    'search'    => $search,
));

$years   = service_years();
$vendors = vendors_list();

$byYear = array();
foreach ($rows as $row) {
    $byYear[substr($row['on_date'], 0, 4)][] = $row;
}

function service_url(array $overrides): string
{
    $params = array();
    foreach (array('q', 'year', 'vendor') as $key) {
        if (($_GET[$key] ?? '') !== '') {
            $params[$key] = $_GET[$key];
        }
    }
    foreach ($overrides as $key => $value) {
        if ($value === null) { unset($params[$key]); } else { $params[$key] = $value; }
    }
    $qs = http_build_query($params);
    return 'service.php' . ($qs === '' ? '' : '?' . $qs);
}

page_head('Service', null);
screen_head('Service', page_menu());
?>

  <p class="hint">
    Every visit somebody was paid for — whether it started as something that
    broke, or as scheduled maintenance.
  </p>

  <form class="composer" method="get" action="service.php">
    <?php if ($year !== 0): ?><input type="hidden" name="year" value="<?= $year ?>"><?php endif; ?>
    <?php if ($vendorId !== 0): ?><input type="hidden" name="vendor" value="<?= $vendorId ?>"><?php endif; ?>
    <input class="composer-input" type="search" name="q" value="<?= h($search) ?>"
           placeholder="Search work, vendors, notes" autocomplete="off" aria-label="Search service">
    <button class="composer-add" type="submit">Find</button>
  </form>

<?php if ($years !== array()): ?>
  <div class="filterbar" role="group" aria-label="Year">
    <a class="chip<?= $year === 0 ? ' is-on' : '' ?>" href="<?= h(service_url(array('year' => null))) ?>">All years</a>
    <?php foreach ($years as $y): ?>
      <a class="chip<?= $year === $y ? ' is-on' : '' ?>" href="<?= h(service_url(array('year' => $y))) ?>"><?= $y ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($vendors !== array()): ?>
  <div class="filters">
    <details class="filter-group"<?= $vendorId !== 0 ? ' open' : '' ?>>
      <summary class="filter-head">
        Vendor
        <?php if ($vendorId !== 0): ?><span class="filter-count">1</span><?php endif; ?>
      </summary>
      <div class="filter-body">
        <?php foreach ($vendors as $vendor): ?>
          <a class="chip<?= $vendorId === $vendor['id'] ? ' is-on' : '' ?>"
             href="<?= h(service_url(array('vendor' => $vendorId === $vendor['id'] ? null : $vendor['id']))) ?>">
            <?= h($vendor['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </details>
  </div>
<?php endif; ?>

<?php if ($rows === array()): ?>
  <p class="empty">
    <?= $search !== '' || $year !== 0 || $vendorId !== 0
        ? 'Nothing matches those filters.'
        : 'Nothing paid for yet. Log a service on a log entry, or record a cost when you complete a maintenance task.' ?>
  </p>
<?php endif; ?>

<?php foreach ($byYear as $groupYear => $group): ?>
  <?php $sum = service_total($group); ?>
  <section class="cat-group">
    <h2 class="cat-head">
      <?= h((string) $groupYear) ?>
      <span class="cat-count">
        <?= $sum['priced'] === 0
            ? count($group) . ' visit' . (count($group) === 1 ? '' : 's')
            : '$' . number_format($sum['total'], 0) ?>
      </span>
    </h2>
    <?php if ($sum['unpriced'] > 0 && $sum['priced'] > 0): ?>
      <?php /* Counted, never summed as zero — "not recorded" is not "free",
               and a total that quietly treats them as nothing is a number you
               would trust and shouldn't. */ ?>
      <p class="hint"><?= $sum['unpriced'] ?> without a recorded cost, not counted.</p>
    <?php endif; ?>

    <ul class="list">
    <?php foreach ($group as $row): ?>
      <li class="list-row">
        <div class="row-slide">
          <?php /* Anchored at the visit itself, not just the parent. On an entry
                   with three years of timeline, landing at the top and asking
                   somebody to scroll for the row they just tapped is the same
                   as not linking it. */ ?>
          <a class="row-body" href="<?= $row['source'] === 'log'
              ? 'entry.php?id=' . (int) $row['parent_id'] . '#update-' . (int) $row['id']
              : 'task.php?id=' . (int) $row['parent_id'] . '#completion-' . (int) $row['id'] ?>">
            <span class="row-text"><?= h($row['title']) ?></span>
            <span class="row-sub">
              <?= h(fmt_date($row['on_date'], 'j M')) ?>
              <?= $row['vendor_name'] === '' ? '' : ' · ' . h($row['vendor_name']) ?>
              <?php $cost = render_cost($row['cost']); ?>
              <?= $cost === '' ? '' : ' · ' . h($cost) ?>
              <?php /* Which kind of work this was. "The AC repair" and "the
                       annual service" are different memories even at the same
                       price, and the link goes somewhere different. */ ?>
              · <?= $row['source'] === 'log' ? 'Repair' : 'Maintenance' ?>
            </span>
          </a>
          <?= render_stars($row['rating'] === null ? null : (float) $row['rating']) ?>
          <?= render_row_edit() ?>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>

<?php
page_foot(null);
