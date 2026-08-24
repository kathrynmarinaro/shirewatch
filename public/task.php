<?php
/* One maintenance task: its rule, its instructions, and its history.
 * Also the NEW-task screen (?new=1) — the same form empty.
 *
 * ---------------------------------------------------------------------------
 * THE RULE EDITOR IS TWO SHAPES, AND THE SCREEN SHOWS ONE AT A TIME.
 * ---------------------------------------------------------------------------
 *
 *   Interval  every N days/weeks/months/years — wear-based
 *   Months    specific months of the year — seasonal
 *
 * Both sets of fields are rendered and assets/maintenance.js hides the one
 * that does not apply, so the form still works with JS off (you would see all
 * the fields and the server would ignore the irrelevant half — task_clean_rule()
 * nulls the other shape's columns deliberately).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/tasks.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/vendors.php';

require_login_page();

$today = sw_today();
$isNew = isset($_GET['new']);
$id    = (int) ($_GET['id'] ?? 0);
$task  = $isNew ? null : task_get($id);

if (!$isNew && $task === null) {
    header('Location: maintenance.php');
    exit;
}

$history  = $task === null ? array() : task_completions($task['id']);
$selected = $task === null ? array() : array_column(tags_for('task', $task['id']), 'id');
$pickable = array_merge(tags_of_kind(TAG_LOCATION), tags_of_kind(TAG_CATEGORY));

$months  = $task === null ? array() : recur_parse_months($task['recur_months']);
$vendors = $isNew ? array() : vendors_list();

page_head($isNew ? 'Add a task' : $task['title'], 'maintenance');
screen_head($isNew ? 'Add a task' : 'Task', page_menu());
?>

<form id="task-form" data-id="<?= $task === null ? '' : (int) $task['id'] ?>">

  <label class="field">
    <span>What needs doing?</span>
    <input class="input" type="text" name="title" maxlength="200" required
           value="<?= $task === null ? '' : h($task['title']) ?>"
           placeholder="Change HVAC filter">
  </label>

  <label class="field">
    <span>Instructions</span>
    <?php /* The reason a task is worth an email rather than a calendar entry:
             "16x25x1, on the shelf above the dryer" is what stops the job
             being deferred. The first line goes in the reminder. */ ?>
    <textarea class="input" name="instructions" rows="3"
              placeholder="Size, where the supplies are, anything you forget every time"><?= $task === null ? '' : h($task['instructions']) ?></textarea>
  </label>

  <label class="field">
    <span>How often?</span>
    <select class="input" name="recur_kind" id="recur-kind">
      <option value="interval" <?= ($task === null || $task['recur_kind'] === 'interval') ? 'selected' : '' ?>>
        Every so often
      </option>
      <option value="months" <?= ($task !== null && $task['recur_kind'] === 'months') ? 'selected' : '' ?>>
        Certain months of the year
      </option>
    </select>
  </label>

  <div class="row" data-rule="interval">
    <label class="field">
      <span>Every</span>
      <input class="input" type="number" name="interval_count" min="1" max="999"
             value="<?= (int) ($task['interval_count'] ?? 3) ?>">
    </label>
    <label class="field">
      <span>&nbsp;</span>
      <select class="input" name="interval_unit">
        <?php foreach (array('day' => 'days', 'week' => 'weeks', 'month' => 'months', 'year' => 'years') as $unit => $label): ?>
          <option value="<?= h($unit) ?>"
            <?= (($task['interval_unit'] ?? 'month') === $unit) ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <label class="field" data-rule="interval">
    <span>Counted from</span>
    <?php /* THE DISTINCTION THAT MATTERS. A filter's clock starts when you
             changed it, so doing it late should move the next one late. A
             schedule you want held to its cadence should not drift later every
             time you are a week busy. */ ?>
    <select class="input" name="interval_from">
      <option value="completion" <?= (($task['interval_from'] ?? 'completion') === 'completion') ? 'selected' : '' ?>>
        When I actually do it
      </option>
      <option value="due" <?= (($task['interval_from'] ?? '') === 'due') ? 'selected' : '' ?>>
        When it was due, however late I am
      </option>
    </select>
  </label>

  <div class="field" data-rule="months">
    <span>Which months?</span>
    <?php /* Seasonal work must not drift: gutters happen in March and October
             or they are not gutter cleaning. An interval approximation walks
             them out of their season within a few years. */ ?>
    <div class="chips" id="month-picker">
      <?php foreach (range(1, 12) as $m): ?>
        <label class="chip<?= in_array($m, $months, true) ? ' is-on' : '' ?>">
          <input type="checkbox" name="recur_months[]" value="<?= $m ?>" class="sr-only"
                 <?= in_array($m, $months, true) ? 'checked' : '' ?>>
          <?= h(date('M', mktime(0, 0, 0, $m, 1, 2001))) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <label class="field" data-rule="months">
    <span>Day of the month</span>
    <input class="input" type="number" name="recur_day" min="1" max="31"
           value="<?= (int) ($task['recur_day'] ?? 1) ?>">
  </label>

  <label class="field">
    <span>Next due</span>
    <input class="input" type="date" name="next_due_on"
           value="<?= h($task === null ? '' : $task['next_due_on']) ?>">
    <?php if ($task !== null): ?>
      <span class="hint">Changing the rule leaves this where it is. Move it here if you want it moved.</span>
    <?php endif; ?>
  </label>

  <div class="field">
    <span>Rooms and systems</span>
    <div class="tagfield" id="task-tags">
      <select multiple name="tag_ids[]">
        <?php foreach ($pickable as $tag): ?>
          <option value="<?= (int) $tag['id'] ?>" data-kind="<?= h($tag['kind']) ?>"
            <?= in_array($tag['id'], $selected, true) ? 'selected' : '' ?>><?= h($tag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <p class="row-between">
    <button class="btn-primary" type="submit">Save</button>
    <span class="hint" id="task-saved" aria-live="polite"></span>
  </p>
</form>

<?php if (!$isNew): ?>

  <section class="stack">
    <h2 class="cat-head">Done</h2>
    <p class="row-between">
      <button class="btn-secondary" type="button" id="task-complete"
              data-id="<?= (int) $task['id'] ?>">Mark done today</button>
      <span class="hint">
        Next: <?= h(fmt_date($task['next_due_on'])) ?>
      </span>
    </p>

    <?php /* A PAID ROUTINE SERVICE IS A COMPLETION THAT COST MONEY, not a
             problem you had to log. Filing the annual HVAC visit as a log
             entry would mean inventing a fault that never existed — so the
             money fields live here, on the completion.

             Behind a <details> because the common case is you did it yourself
             in ten minutes, and that case must stay one tap. Open it and the
             button above becomes the one inside, so there is never a form you
             filled in and a button that ignores it. */ ?>
    <details class="accordion" id="paid-completion">
      <summary class="accordion-head">Somebody was paid for it</summary>
      <div class="accordion-body">
        <form id="complete-form" data-id="<?= (int) $task['id'] ?>">
          <div class="row">
            <label class="field">
              <span>When</span>
              <input class="input" type="date" name="completed_on" value="<?= h($today) ?>">
            </label>
            <label class="field">
              <span>Who</span>
              <select class="input" name="vendor_id">
                <option value="">Not in the directory</option>
                <?php foreach ($vendors as $vendor): ?>
                  <option value="<?= (int) $vendor['id'] ?>"><?= h($vendor['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <label class="field">
            <span>…or type a name</span>
            <input class="input" type="text" name="vendor_name" maxlength="160">
          </label>

          <div class="row">
            <label class="field">
              <span>Cost</span>
              <?php /* LEFT EMPTY MEANS "NOT RECORDED", WHICH IS NOT "FREE". */ ?>
              <input class="input" type="text" inputmode="decimal" name="cost"
                     placeholder="Blank if not recorded">
            </label>
            <label class="field">
              <span>How did it go?</span>
              <select class="input" name="rating">
                <option value="">Not rated</option>
                <?php foreach (array(1, 2, 3, 4, 5) as $star): ?>
                  <option value="<?= $star ?>"><?= $star ?> star<?= $star === 1 ? '' : 's' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <label class="field">
            <span>Notes</span>
            <textarea class="input" name="note" rows="2"
                      placeholder="Coils cleaned, refrigerant checked"></textarea>
          </label>

          <p class="row-between">
            <button class="btn-primary" type="submit">Mark done</button>
          </p>
        </form>
        <p class="hint">
          This shows up under Service in the menu, beside the repairs.
        </p>
      </div>
    </details>
  </section>

  <section class="stack">
    <h2 class="cat-head">
      History
      <span class="cat-count"><?= count($history) ?></span>
    </h2>
    <?php if ($history === array()): ?>
      <p class="empty">Never done — or never logged here, anyway.</p>
    <?php else: ?>
      <?php /* NEWEST FIRST, unlike a log entry's timeline. That is a story and
               reads forwards; this is a record, and the question you ask of it
               is "when did I last do this", which is the top row. */ ?>
      <ul class="list">
        <?php foreach ($history as $entry): ?>
          <li class="list-row" id="completion-<?= (int) $entry['id'] ?>"
              data-completion-id="<?= (int) $entry['id'] ?>">
            <div class="row-slide">
              <span class="row-body">
                <span class="row-text"><?= h(fmt_date($entry['completed_on'])) ?></span>
                <?php $paid = $entry['vendor_name'] !== '' || $entry['cost'] !== null; ?>
                <?php if ($entry['note'] !== '' || $paid): ?>
                  <span class="row-sub">
                    <?= h($entry['note']) ?>
                    <?php if ($paid): ?>
                      <?php /* A completion that cost money IS the service
                               record — there is no separate row to link to
                               any more. */ ?>
                      <?= $entry['note'] !== '' ? ' · ' : '' ?>
                      <?= $entry['vendor_name'] === '' ? '' : h($entry['vendor_name']) ?>
                      <?php $cost = render_cost($entry['cost']); ?>
                      <?= $cost === '' ? '' : ' · ' . h($cost) ?>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
                <?= render_stars($entry['rating'] === null ? null : (float) $entry['rating']) ?>
              </span>
              <button class="tap-text" type="button"
                      data-uncomplete="<?= (int) $entry['id'] ?>">Undo</button>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <p class="row-between stack">
    <button class="tap-text" type="button" id="task-pause" data-active="<?= $task['is_active'] ? '1' : '0' ?>">
      <?= $task['is_active'] ? 'Pause this task' : 'Resume this task' ?>
    </button>
    <button class="tap-text danger" type="button" id="task-delete">Delete</button>
  </p>

<?php endif; ?>

<script type="module" src="<?= asset('assets/maintenance.js') ?>"></script>
<?php
page_foot('maintenance');
