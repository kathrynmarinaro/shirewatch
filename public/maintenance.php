<?php
/* The maintenance list: what is due, what is coming, what you have paused.
 *
 * Grouped by urgency rather than sorted flat. "Overdue / This week / Later" is
 * the question you are actually asking when you open this screen on a
 * Saturday morning, and a flat list of nineteen dated rows makes you do that
 * grouping in your head every time.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/tasks.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today   = sw_today();
$weekEnd = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');

$showPaused = isset($_GET['paused']);
$tagIds     = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));

$tasks = tasks_list(array(
    'active'  => !$showPaused,
    'tag_ids' => $tagIds,
));

/* Three buckets, filled in one pass. Overdue and today are kept SEPARATE from
 * each other: "you should have done this in March" and "this is today's job"
 * are different feelings, and merging them lets the March one hide. */
$groups = array('Overdue' => array(), 'This week' => array(), 'Later' => array());
foreach ($tasks as $task) {
    if ($task['next_due_on'] <= $today) {
        $groups['Overdue'][] = $task;
    } elseif ($task['next_due_on'] <= $weekEnd) {
        $groups['This week'][] = $task;
    } else {
        $groups['Later'][] = $task;
    }
}

/** The same screen with Active/Paused flipped, keeping the tag filters on. */
function maint_state_url(bool $paused): string
{
    $params = array();
    $tags = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));
    if ($tags !== array()) { $params['tag'] = $tags; }
    if ($paused) { $params['paused'] = 1; }
    $qs = http_build_query($params);
    return 'maintenance.php' . ($qs === '' ? '' : '?' . $qs);
}

function maint_tag_url(int $tagId): string
{
    $current = array_values(array_filter(array_map('intval', (array) ($_GET['tag'] ?? array()))));
    $next = in_array($tagId, $current, true)
        ? array_values(array_diff($current, array($tagId)))
        : array_merge($current, array($tagId));

    $params = array();
    if ($next !== array()) { $params['tag'] = $next; }
    if (isset($_GET['paused'])) { $params['paused'] = 1; }
    $qs = http_build_query($params);
    return 'maintenance.php' . ($qs === '' ? '' : '?' . $qs);
}

page_head('Maintenance', 'maintenance');
screen_head('Maintenance', page_menu());
?>

  <?php /* Active / Paused is a filter like any other now, rather than a link
           at the bottom of the screen. Paused work is a real state you go
           looking for, not a footnote. */ ?>
  <div class="filterbar" role="group" aria-label="Active or paused">
    <a class="chip<?= $showPaused ? '' : ' is-on' ?>" href="<?= h(maint_state_url(false)) ?>">Active</a>
    <a class="chip<?= $showPaused ? ' is-on' : '' ?>" href="<?= h(maint_state_url(true)) ?>">Paused</a>
  </div>

  <?= render_filters($tagIds, 'maint_tag_url') ?>

<?php if ($tasks === array()): ?>
  <p class="empty">
    <?php if ($showPaused): ?>
      Nothing is paused.
    <?php elseif ($tagIds !== array()): ?>
      Nothing matches those filters.
    <?php else: ?>
      No maintenance set up yet.
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php foreach ($groups as $label => $group): ?>
  <?php if ($group === array()) { continue; } ?>
  <section class="cat-group">
    <h2 class="cat-head <?= $label === 'Overdue' ? 'is-overdue' : '' ?>">
      <?= h($showPaused ? 'Paused' : $label) ?>
      <span class="cat-count"><?= count($group) ?></span>
    </h2>
    <ul class="list" data-task-list>
    <?php foreach ($group as $task): ?>
      <li class="list-row" data-id="<?= (int) $task['id'] ?>">
        <div class="row-slide">
          <?php /* A button, not an <input type=checkbox> — same call the house
                   list row makes everywhere. Completing is an action with a
                   consequence (the schedule moves), not a piece of form state. */ ?>
          <button class="row-check" type="button" aria-pressed="false"
                  data-complete="<?= (int) $task['id'] ?>"
                  aria-label="Mark <?= h($task['title']) ?> done"></button>
          <a class="row-body" href="task.php?id=<?= (int) $task['id'] ?>">
            <span class="row-text"><?= h($task['title']) ?></span>
            <span class="row-sub">
              <span class="<?= $task['next_due_on'] <= $today ? 'is-overdue' : '' ?>">
                <?= h(fmt_relative_due($task['next_due_on'], $today)) ?>
              </span>
              <?php $every = recur_describe($task); ?>
              <?= $every === '' ? '' : ' · ' . h($every) ?>
              <?php $names = tag_names($task['tags']); ?>
              <?= $names === array() ? '' : ' · ' . h(implode(' · ', $names)) ?>
            </span>
          </a>
          <?= render_row_edit() ?>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>

<?= render_fab('task.php?new=1', 'Add a task') ?>

<script type="module" src="<?= asset('assets/maintenance.js') ?>"></script>
<?php
page_foot('maintenance');
