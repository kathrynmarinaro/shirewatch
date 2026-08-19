<?php
/* One service record: what was done, by whom, what it cost, and the paperwork.
 * Also the NEW-record screen (?new=1).
 *
 * ---------------------------------------------------------------------------
 * THE BATCH UPLOAD FLOW LIVES HERE.
 * ---------------------------------------------------------------------------
 *
 * A service record is the one place that takes a pile of files at once: a few
 * photos of the damage, the invoice, maybe a warranty. Photos and documents go
 * up through the same control and are separated by their bytes, not by which
 * button you pressed — you should not have to know that a PDF is a different
 * kind of thing from a photograph.
 *
 * ?issue_id= and ?task_id= pre-link the record, so "log what fixed this" from
 * an issue lands here with the connection already made.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/records.php';
require_once __DIR__ . '/../lib/issues.php';
require_once __DIR__ . '/../lib/tasks.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today  = sw_today();
$isNew  = isset($_GET['new']) || (int) ($_GET['id'] ?? 0) === 0;
$id     = (int) ($_GET['id'] ?? 0);
$record = $isNew ? null : record_get($id);

if (!$isNew && $record === null) {
    header('Location: history.php');
    exit;
}

/* Pre-linked from an issue or a task. */
$linkIssue = $record !== null ? $record['issue_id'] : ((int) ($_GET['issue_id'] ?? 0) ?: null);
$linkTask  = $record !== null ? $record['task_id']  : ((int) ($_GET['task_id'] ?? 0) ?: null);

$vendors  = vendors_list();
$pickable = array_merge(tags_of_kind(TAG_LOCATION), tags_of_kind(TAG_CATEGORY));
$selected = $record === null ? array() : array_column($record['tags'], 'id');

/* Only OPEN issues are offered, plus whichever one is already linked. A
 * dropdown of every issue you ever logged is unusable after two years, and
 * relinking work to a long-resolved issue is not a thing anyone does. */
$openIssues = issues_list(array('status' => issue_open_statuses()));
if ($linkIssue !== null && !in_array($linkIssue, array_column($openIssues, 'id'), true)) {
    $already = issue_get($linkIssue);
    if ($already !== null) {
        $openIssues[] = $already + array('tags' => array(), 'cover' => null, 'photo_count' => 0);
    }
}

page_head($isNew ? 'Log some work' : $record['title'], null);
screen_head($isNew ? 'Log some work' : 'Service record', page_menu());
?>

<form id="record-form" data-id="<?= $record === null ? '' : (int) $record['id'] ?>">

  <label class="field">
    <span>What was done?</span>
    <input class="input" type="text" name="title" maxlength="200" required
           value="<?= $record === null ? '' : h($record['title']) ?>"
           placeholder="Rebuilt the shut-off valve">
  </label>

  <div class="row">
    <label class="field">
      <span>When</span>
      <input class="input" type="date" name="performed_on"
             value="<?= h($record === null ? $today : $record['performed_on']) ?>">
    </label>
    <label class="field">
      <span>Cost</span>
      <?php /* LEFT EMPTY MEANS "NOT RECORDED", WHICH IS NOT "FREE"
               (CLAUDE.md). There is no placeholder of 0 and no default. */ ?>
      <input class="input" type="text" inputmode="decimal" name="cost"
             value="<?= $record === null || $record['cost'] === null ? '' : h($record['cost']) ?>"
             placeholder="Leave blank if not recorded">
    </label>
  </div>

  <label class="field">
    <span>Who did it</span>
    <select class="input" name="vendor_id">
      <option value="">Nobody / did it myself</option>
      <?php foreach ($vendors as $vendor): ?>
        <option value="<?= (int) $vendor['id'] ?>"
          <?= ($record !== null && $record['vendor_id'] === $vendor['id']) ? 'selected' : '' ?>>
          <?= h($vendor['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="hint">
      Not in the list? <a href="vendor.php?new=1">Add them</a>, or just type the
      name below — plenty of work is done by somebody you will never call again.
    </span>
  </label>

  <label class="field">
    <span>…or a name, if they are not in the directory</span>
    <input class="input" type="text" name="vendor_name" maxlength="160"
           value="<?= ($record !== null && $record['vendor_id'] === null) ? h($record['vendor_name']) : '' ?>">
  </label>

  <label class="field">
    <span>How did it go?</span>
    <?php /* Rated PER JOB. The vendor's overall rating is the average of these
             (schema.sql), so this is the only place a rating is ever typed. */ ?>
    <select class="input" name="rating">
      <option value="">Not rated</option>
      <?php foreach (array(1, 2, 3, 4, 5) as $star): ?>
        <option value="<?= $star ?>" <?= ($record !== null && $record['rating'] === $star) ? 'selected' : '' ?>>
          <?= $star ?> star<?= $star === 1 ? '' : 's' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="field">
    <span>Notes</span>
    <textarea class="input" name="description" rows="3"><?= $record === null ? '' : h($record['description']) ?></textarea>
  </label>

  <label class="field">
    <span>Related issue</span>
    <select class="input" name="issue_id">
      <option value="">None</option>
      <?php foreach ($openIssues as $issue): ?>
        <option value="<?= (int) $issue['id'] ?>" <?= $linkIssue === $issue['id'] ? 'selected' : '' ?>>
          <?= h($issue['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="field">
    <span>Related maintenance task</span>
    <select class="input" name="task_id">
      <option value="">None</option>
      <?php foreach (tasks_list() as $task): ?>
        <option value="<?= (int) $task['id'] ?>" <?= $linkTask === $task['id'] ? 'selected' : '' ?>>
          <?= h($task['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <div class="field">
    <span>Rooms and systems</span>
    <div class="tagfield" id="record-tags">
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
    <span class="hint" id="record-saved" aria-live="polite"></span>
  </p>
</form>

<?php if (!$isNew): ?>

  <section class="stack">
    <h2 class="cat-head">Photos and paperwork</h2>
    <?= render_gallery($record['media'], array('id' => 'record-photos', 'date' => $record['performed_on'])) ?>
    <?= render_documents($record['media']) ?>
    <p class="stack">
      <label class="btn-secondary" id="record-upload">
        Add photos or a PDF
        <?php /* One control for both. They are separated by their bytes in
                 lib/media.php, not by which button you pressed. */ ?>
        <input type="file" accept="image/*,application/pdf" multiple hidden>
      </label>
    </p>
  </section>

  <?php if ($record['issue_id'] !== null): ?>
    <?php $linked = issue_get($record['issue_id']); ?>
    <?php if ($linked !== null): ?>
      <section class="stack">
        <h2 class="cat-head">Related issue</h2>
        <p><a href="issue.php?id=<?= (int) $linked['id'] ?>"><?= h($linked['title']) ?></a></p>
        <?php /* "This work relates to the issue" and "this work RESOLVED the
                 issue" are different claims, so resolving is an explicit
                 action here rather than something linking implies. */ ?>
        <?php if ($linked['resolved_by_record_id'] !== (int) $record['id']): ?>
          <button class="btn-secondary" type="button" id="resolve-issue"
                  data-issue="<?= (int) $linked['id'] ?>" data-record="<?= (int) $record['id'] ?>">
            This is what fixed it
          </button>
        <?php else: ?>
          <p class="hint">Marked as what resolved this issue.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <p class="stack">
    <button class="tap-text danger" type="button" id="record-delete">Delete this record</button>
  </p>

<?php endif; ?>

<div class="queue-pill" id="queue-pill" hidden>
  <span class="queue-spinner" aria-hidden="true"></span>
  <span id="queue-text">Processing…</span>
</div>

<script type="module" src="<?= asset('assets/history.js') ?>"></script>
<?php
page_foot(null);
