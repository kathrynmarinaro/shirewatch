<?php
/* The issue list — the app's front door for its original idea.
 *
 * SERVER-RENDERED, INCLUDING THE FILTERS. Every filter is a link with a query
 * string, so the state of the screen is in the URL: you can bookmark "urgent
 * things in the kitchen", the back button steps through what you looked at,
 * and a reload does not drop you back to everything. A JS-only filter has none
 * of that and is not meaningfully faster on a list this size.
 *
 * assets/issues.js adds swipe-to-delete and the capture flow on top.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/issues.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today = sw_today();

/* ---- read the filters out of the URL ----------------------------------- */

/* `status=all` is a distinct thing from no status at all: the default is the
 * two OPEN states, because a list that opens on four years of resolved issues
 * buries the three you can do something about. */
$statusParam = (string) ($_GET['status'] ?? '');
if ($statusParam === 'all') {
    $statuses = issue_statuses();
} elseif (in_array($statusParam, issue_statuses(), true)) {
    $statuses = array($statusParam);
} else {
    $statuses = issue_open_statuses();
}

$tagIds = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));
$search = trim((string) ($_GET['q'] ?? ''));

$issues = issues_list(array(
    'status'   => $statuses,
    'tag_ids'  => $tagIds,
    'search'   => $search,
));

/** A URL with one filter flipped, preserving everything else. */
function issues_url(array $overrides = array()): string
{
    $params = array();
    if (($_GET['status'] ?? '') !== '') { $params['status'] = $_GET['status']; }
    if (($_GET['q'] ?? '') !== '')      { $params['q'] = $_GET['q']; }
    $params['tag'] = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    if ($params['tag'] === array()) {
        unset($params['tag']);
    }

    $qs = http_build_query($params);
    return 'issues.php' . ($qs === '' ? '' : '?' . $qs);
}

/** The same URL with one tag toggled in or out. */
function issues_tag_url(int $tagId): string
{
    $current = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));
    $next = in_array($tagId, $current, true)
        ? array_values(array_diff($current, array($tagId)))
        : array_merge($current, array($tagId));
    return issues_url(array('tag' => $next));
}

page_head('Issues', 'issues');
screen_head('Issues', page_menu());
?>

  <form class="composer" method="get" action="issues.php">
    <?php foreach ($tagIds as $tagId): ?>
      <input type="hidden" name="tag[]" value="<?= (int) $tagId ?>">
    <?php endforeach; ?>
    <?php if ($statusParam !== ''): ?>
      <input type="hidden" name="status" value="<?= h($statusParam) ?>">
    <?php endif; ?>
    <input class="composer-input" type="search" name="q" value="<?= h($search) ?>"
           placeholder="Search issues" autocomplete="off" aria-label="Search issues">
    <button class="composer-add" type="submit">Find</button>
  </form>

  <?php /* Status first, on its own row: it is the one filter that is always
           on, and burying it among twenty room chips hides the fact that the
           list is showing you a subset. */ ?>
  <div class="filterbar" role="group" aria-label="Status">
    <a class="chip<?= $statusParam === '' ? ' is-on' : '' ?>" href="<?= h(issues_url(array('status' => null))) ?>">Open</a>
    <?php foreach (issue_statuses() as $status): ?>
      <a class="chip<?= $statusParam === $status ? ' is-on' : '' ?>"
         href="<?= h(issues_url(array('status' => $status))) ?>"><?= h(issue_status_label($status)) ?></a>
    <?php endforeach; ?>
    <a class="chip<?= $statusParam === 'all' ? ' is-on' : '' ?>" href="<?= h(issues_url(array('status' => 'all'))) ?>">All</a>
  </div>

  <?= render_filters($tagIds, 'issues_tag_url') ?>

<?php if ($tagIds !== array() || $search !== '' || $statusParam !== ''): ?>
  <p class="hint">
    <?= count($issues) ?> match<?= count($issues) === 1 ? '' : 'es' ?>.
    <a class="link-btn" href="issues.php">Clear filters</a>
  </p>
<?php endif; ?>

<?php if ($issues === array()): ?>
  <p class="empty">
    <?= $tagIds !== array() || $search !== '' ? 'Nothing matches those filters.' : 'Nothing logged yet.' ?>
  </p>
<?php else: ?>
  <ul class="list" id="issue-list">
  <?php foreach ($issues as $issue): ?>
    <li class="list-row" data-id="<?= (int) $issue['id'] ?>">
      <div class="row-slide">
        <a class="row-body" href="issue.php?id=<?= (int) $issue['id'] ?>">
          <span class="row-text"><?= h($issue['title']) ?></span>
          <span class="row-sub">
            <?php $names = tag_names($issue['tags']); ?>
            <?= $names === array() ? 'Untagged' : h(implode(' · ', $names)) ?>
            <?php if ($issue['next_check_on'] !== null): ?>
              · <span class="<?= $issue['next_check_on'] <= $today ? 'is-overdue' : '' ?>">Check <?= h(fmt_relative_due($issue['next_check_on'], $today)) ?></span>
            <?php endif; ?>
            <?php if ($issue['photo_count'] > 0): ?>
              · <?= (int) $issue['photo_count'] ?> photo<?= $issue['photo_count'] === 1 ? '' : 's' ?>
            <?php endif; ?>
          </span>
        </a>
        <?php /* NULL severity renders NOTHING — not a grey pill, which would
                 put something that looks like data on every issue where none
                 was recorded (CLAUDE.md). */ ?>
        <?= render_severity($issue['severity']) ?>
        <?= render_row_edit() ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?= render_fab('issue.php?new=1', 'Log an issue') ?>

<script type="module" src="<?= asset('assets/issues.js') ?>"></script>
<?php
page_foot('issues');
