<?php
/* The vendor directory.
 *
 * Every rating here is COMPUTED from the service records, never stored — see
 * lib/vendors.php. A manual override is shown beside the average rather than
 * replacing it, so you can always see what you overrode.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/vendors.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$search = trim((string) ($_GET['q'] ?? ''));
$tagIds = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));

$vendors = vendors_list(array('search' => $search, 'tag_ids' => $tagIds));

function vendors_tag_url(int $tagId): string
{
    $current = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));
    $next = in_array($tagId, $current, true)
        ? array_values(array_diff($current, array($tagId)))
        : array_merge($current, array($tagId));
    $params = array();
    if ($next !== array()) { $params['tag'] = $next; }
    if (($_GET['q'] ?? '') !== '') { $params['q'] = $_GET['q']; }
    $qs = http_build_query($params);
    return 'vendors.php' . ($qs === '' ? '' : '?' . $qs);
}

page_head('Vendors', 'vendors');
screen_head('Vendors', page_menu());
?>

  <form class="composer" method="get" action="vendors.php">
    <input class="composer-input" type="search" name="q" value="<?= h($search) ?>"
           placeholder="Search vendors" autocomplete="off" aria-label="Search vendors">
    <button class="composer-add" type="submit">Find</button>
  </form>

  <?php /* One group, not two: a vendor carries trades and nothing else. */ ?>
  <div class="filters">
    <?= render_filter_group('Work types', tags_of_kind(TAG_WORK_TYPE), $tagIds, 'vendors_tag_url') ?>
  </div>

<?php if ($vendors === array()): ?>
  <p class="empty">
    <?= $search !== '' || $tagIds !== array() ? 'Nobody matches those filters.' : 'Nobody in the directory yet.' ?>
  </p>
<?php else: ?>
  <ul class="list">
  <?php foreach ($vendors as $vendor): ?>
    <li class="list-row" data-id="<?= (int) $vendor['id'] ?>">
      <div class="row-slide">
        <a class="row-body" href="vendor.php?id=<?= (int) $vendor['id'] ?>">
          <span class="row-text"><?= h($vendor['name']) ?></span>
          <span class="row-sub">
            <?php $trades = tag_names($vendor['tags']); ?>
            <?= $trades === array() ? '' : h(implode(' · ', $trades)) . ' · ' ?>
            <?php /* "No jobs yet" rather than an empty space: a vendor you
                     added and never used is a real state, and a blank line
                     reads as data that failed to load. */ ?>
            <?= $vendor['jobs'] === 0
                ? 'No jobs yet'
                : (int) $vendor['jobs'] . ' job' . ($vendor['jobs'] === 1 ? '' : 's') ?>
          </span>
        </a>
        <?= render_stars($vendor['rating']) ?>
        <?= render_row_edit() ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?= render_fab('vendor.php?new=1', 'Add a vendor') ?>

<script type="module" src="<?= asset('assets/vendors.js') ?>"></script>
<?php
page_foot('vendors');
