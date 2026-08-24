<?php
/* The dashboard: what needs doing now, over what is coming.
 *
 * ---------------------------------------------------------------------------
 * TWO COMPONENTS, AND THEY TREAT THE BRIEF DIFFERENTLY ON PURPOSE.
 * ---------------------------------------------------------------------------
 *
 * NEEDS ACTION NOW is split into a log group and a maintenance group. That
 * is the brief's "separate sections for issues vs. maintenance" — issues being
 * what the log holds — honoured
 * where it earns its place: deciding what to do this morning, "call a plumber"
 * and "change a filter" are different kinds of thing.
 *
 * THE FORWARD TIMELINE is one combined chronological stream. Here the
 * separation is dropped deliberately — the organising principle is *when*, and
 * two parallel columns of dates is a calendar nobody can read. Each row still
 * carries its type, as a filled or hollow node.
 *
 * ---------------------------------------------------------------------------
 * THE FIRST PAGE IS SERVER-RENDERED.
 * ---------------------------------------------------------------------------
 *
 * Not fetched on load. The dashboard is the app's front door and the thing you
 * open it to see is right here — a screen that appears empty and then fills in
 * reads as a slow app even when it is not. assets/dashboard.js takes over from
 * the second page onward, and with no JS at all you get one page and a link.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/dashboard.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today = sw_today();
$now   = dashboard_now($today);
$first = dashboard_timeline($today);

page_head(app_name(), 'dashboard');
screen_head(app_name(), page_menu());
?>

<?php /* OPEN BY DEFAULT when there is anything in it, closed when there is
         not. An accordion you have to open to discover it is empty is worse
         than a line of text saying so. */ ?>
<details class="accordion" id="needs-action" <?= $now['total'] > 0 ? 'open' : '' ?>>
  <summary class="accordion-head">
    Needs action now
    <span class="action-count <?= $now['total'] === 0 ? 'is-clear' : '' ?>"><?= (int) $now['total'] ?></span>
  </summary>
  <div class="accordion-body">

  <?php if ($now['total'] === 0): ?>
    <p class="empty">Nothing needs doing. The house is fine.</p>
  <?php endif; ?>

  <?php if ($now['log'] !== array()): ?>
    <section class="action-group">
      <h2 class="cat-head">Log <span class="cat-count"><?= count($now['log']) ?></span></h2>
      <ul class="list">
        <?php foreach ($now['log'] as $entry): ?>
          <li class="list-row">
            <div class="row-slide">
              <a class="row-body" href="entry.php?id=<?= (int) $entry['id'] ?>">
                <span class="row-text"><?= h($entry['title']) ?></span>
                <span class="row-sub">
                  <?php if ($entry['status'] === LOG_ACTIVE): ?>
                    Needs doing
                  <?php elseif ($entry['next_check_on'] !== null): ?>
                    <span class="is-overdue">Check <?= h(fmt_relative_due($entry['next_check_on'], $today)) ?></span>
                  <?php endif; ?>
                  <?php $names = tag_names($entry['tags']); ?>
                  <?= $names === array() ? '' : ' · ' . h(implode(' · ', $names)) ?>
                </span>
              </a>
              <?= render_severity($entry['severity']) ?>
              <?= render_row_edit() ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($now['tasks'] !== array()): ?>
    <section class="action-group">
      <h2 class="cat-head">Maintenance <span class="cat-count"><?= count($now['tasks']) ?></span></h2>
      <ul class="list">
        <?php foreach ($now['tasks'] as $task): ?>
          <li class="list-row" data-id="<?= (int) $task['id'] ?>">
            <div class="row-slide">
              <?php /* The same data-complete hook assets/maintenance.js binds
                       on the Maintenance screen — one handler, delegated to the
                       document, so ticking something off works identically in
                       both places. */ ?>
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
                </span>
              </a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  </div>
</details>

<section class="stack">
  <?php /* .section-title, not .cat-head: this is the heading OF a section
           rather than a label inside one, and the two were being asked to do
           the same job at different weights. */ ?>
  <h2 class="section-title">Coming up</h2>

  <?php if ($first['rows'] === array()): ?>
    <p class="empty">Nothing scheduled ahead. Add a maintenance task or give a log entry a check-back date.</p>
  <?php else: ?>
    <ol class="timeline" id="timeline"
        data-cursor-date="<?= h($first['cursor']['date'] ?? '') ?>"
        data-cursor-key="<?= h($first['cursor']['key'] ?? '') ?>"
        data-done="<?= $first['done'] ? '1' : '0' ?>">
      <?php
        $lastDate = '';
        foreach ($first['rows'] as $row):
          if ($row['on_date'] !== $lastDate):
            $lastDate = $row['on_date'];
      ?>
        <li class="timeline-head" data-date="<?= h($row['on_date']) ?>"><?= h(fmt_date($row['on_date'], 'l j F')) ?></li>
      <?php endif; ?>
        <li class="timeline-item <?= $row['kind'] === 'entry' ? 'is-entry' : '' ?> <?= $row['projected'] ? 'is-projected' : '' ?>">
          <a class="timeline-title"
             href="<?= $row['kind'] === 'entry' ? 'entry.php?id=' : 'task.php?id=' ?><?= (int) $row['id'] ?>">
            <?= h($row['title']) ?>
          </a>
          <span class="timeline-sub">
            <?= $row['kind'] === 'entry' ? 'Check back' : 'Maintenance' ?>
            <?php /* Projected rows are PREDICTIONS, not stored rows. Completing
                     a task early moves everything after it, and the app has not
                     promised you these dates. */ ?>
            <?= $row['projected'] ? ' · projected' : '' ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>

    <?php if (!$first['done']): ?>
      <p class="timeline-more" id="timeline-more">
        <button class="link-btn" type="button" id="timeline-load">Load more</button>
      </p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<script type="module" src="<?= asset('assets/dashboard.js') ?>"></script>
<script type="module" src="<?= asset('assets/maintenance.js') ?>"></script>
<?php
page_foot('dashboard');
