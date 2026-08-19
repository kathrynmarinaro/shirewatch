<?php
/* One issue: its header, its timeline, and the check-in form.
 *
 * Also the NEW-issue screen (?new=1) — the same form with nothing in it. One
 * template for both, because a separate "add" screen is a second place to add
 * a field to and the second one always gets forgotten.
 *
 * ---------------------------------------------------------------------------
 * THE CAPTURE FLOW IS: SAVE FIRST, THEN PHOTOS.
 * ---------------------------------------------------------------------------
 *
 * api/upload.php refuses an owner row that does not exist yet, so the issue
 * has to be created before a photo can attach to it. On a new issue the photo
 * control is therefore disabled until the first save, and assets/issues.js
 * enables it once it has an id. Fighting that — buffering files client-side
 * and posting them after — would mean holding several megabytes in a phone's
 * memory through a request that might fail.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/issues.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today   = sw_today();
$isNew   = isset($_GET['new']);
$id      = (int) ($_GET['id'] ?? 0);
$issue   = $isNew ? null : issue_get($id);

if (!$isNew && $issue === null) {
    /* A deleted issue reached from a stale bookmark or the back button. Fail
     * soft to the list rather than 404ing a screen the app itself linked to. */
    header('Location: issues.php');
    exit;
}

$updates    = $issue === null ? array() : issue_updates($issue['id']);
$trend      = issue_trend($updates);
$issueTags  = $issue === null ? array() : tags_for('issue', $issue['id']);
$issuePhotos = $issue === null ? array() : media_for('issue', $issue['id']);
$selected   = array_column($issueTags, 'id');

$pickable = array_merge(tags_of_kind(TAG_LOCATION), tags_of_kind(TAG_CATEGORY));

page_head($isNew ? 'Log an issue' : $issue['title'], 'issues');
screen_head($isNew ? 'Log an issue' : 'Issue', page_menu());
?>

<form id="issue-form" data-id="<?= $issue === null ? '' : (int) $issue['id'] ?>">

  <label class="field">
    <span>What did you notice?</span>
    <input class="input" type="text" name="title" maxlength="200" required
           value="<?= $issue === null ? '' : h($issue['title']) ?>"
           placeholder="Hairline crack in the library ceiling">
  </label>

  <label class="field">
    <span>Description</span>
    <textarea class="input" name="description" rows="3"
              placeholder="Where exactly, how big, what you already tried"><?= $issue === null ? '' : h($issue['description']) ?></textarea>
  </label>

  <div class="row">
    <label class="field">
      <span>Noticed on</span>
      <input class="input" type="date" name="noticed_on"
             value="<?= h($issue === null ? $today : $issue['noticed_on']) ?>">
    </label>

    <label class="field">
      <span>Severity</span>
      <?php /* The empty option is FIRST and is the default. Severity is
               optional and NULL renders as nothing, so pre-selecting "Watch"
               would silently rate every issue nobody rated. */ ?>
      <select class="input" name="severity">
        <option value="">Not set</option>
        <?php foreach (array(1, 2, 3, 4) as $level): ?>
          <option value="<?= $level ?>"
            <?= ($issue !== null && $issue['severity'] === $level) ? 'selected' : '' ?>>
            <?= h(issue_severity_label($level)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <label class="field">
    <span>Check back every</span>
    <?php /* THE COLUMN THAT MAKES THE TIMELINE HAPPEN. An issue you log and
             never revisit has one photo and no progression, and the premise of
             this app is the second photo. Blank is legal — not everything
             needs watching. */ ?>
    <select class="input" name="check_interval_days">
      <option value="">Don’t remind me</option>
      <?php foreach (array(30 => 'Month', 90 => '3 months', 180 => '6 months', 365 => 'Year') as $days => $label): ?>
        <option value="<?= $days ?>"
          <?= ($issue !== null && $issue['check_interval_days'] === $days) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <div class="field">
    <span>Rooms and systems</span>
    <div class="tagfield" id="issue-tags">
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
    <span class="hint" id="issue-saved" aria-live="polite"></span>
  </p>
</form>

<?php if (!$isNew): ?>

  <?php /* Status lives outside the form: it is a decision, not a field, and
           each one has a different consequence. Dismissing keeps the record
           and the photos; deleting does not, which is why they are not
           adjacent. */ ?>
  <section class="stack">
    <h2 class="cat-head">Status</h2>
    <div class="filterbar" role="group" aria-label="Status" id="issue-status">
      <?php foreach (issue_statuses() as $status): ?>
        <button class="chip<?= $issue['status'] === $status ? ' is-on' : '' ?>"
                type="button" data-status="<?= h($status) ?>"><?= h(issue_status_label($status)) ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($issue['resolved_on'] !== null): ?>
      <p class="hint">
        <?= h(issue_status_label($issue['status'])) ?> on <?= h(fmt_relative_due($issue['resolved_on'], $today)) ?>.
        <?php if ($issue['resolved_by_record_id'] !== null): ?>
          <a href="record.php?id=<?= (int) $issue['resolved_by_record_id'] ?>">See what fixed it</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </section>

  <?php if ($issuePhotos !== array()): ?>
  <section class="stack">
    <h2 class="cat-head">First photos</h2>
    <?= render_gallery($issuePhotos, array('id' => 'issue-photos', 'date' => $issue['noticed_on'])) ?>
  </section>
  <?php endif; ?>

  <section class="stack">
    <h2 class="cat-head">
      Timeline
      <?php /* The trend is EMPTY unless at least two entries recorded a
               severity. Severity is optional, so most timelines have none —
               and printing "stable" for an unrated one would be inventing a
               judgement nobody made. */ ?>
      <?php if ($trend !== ''): ?>
        <span class="cat-count"><?= h(array('worse' => 'Getting worse', 'better' => 'Improving', 'stable' => 'Stable')[$trend]) ?></span>
      <?php endif; ?>
    </h2>

    <?php if ($updates === array()): ?>
      <p class="empty">No check-ins yet. Add one below when you look at it again.</p>
    <?php else: ?>
      <ol class="timeline" id="issue-timeline">
        <?php /* OLDEST FIRST — this is a progression, and newest-first tells
                 the story backwards. */ ?>
        <?php foreach ($updates as $update): ?>
          <li class="timeline-item" data-update-id="<?= (int) $update['id'] ?>">
            <span class="timeline-title">
              <?= h(fmt_date($update['noted_on'])) ?>
              <?= render_severity($update['severity']) ?>
            </span>
            <?php if ($update['note'] !== ''): ?>
              <span class="timeline-sub"><?= nl2br(h($update['note'])) ?></span>
            <?php endif; ?>
            <?= render_gallery($update['photos'], array('strip' => true, 'date' => $update['noted_on'])) ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

  <section class="stack" id="checkin">
    <h2 class="cat-head">Add a check-in</h2>
    <form id="checkin-form" data-issue-id="<?= (int) $issue['id'] ?>">
      <div class="row">
        <label class="field">
          <span>Date</span>
          <input class="input" type="date" name="noted_on" value="<?= h($today) ?>">
        </label>
        <label class="field">
          <span>Severity now</span>
          <select class="input" name="severity">
            <option value="">No change</option>
            <?php foreach (array(1, 2, 3, 4) as $level): ?>
              <option value="<?= $level ?>"><?= h(issue_severity_label($level)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label class="field">
        <span>What has changed?</span>
        <textarea class="input" name="note" rows="2"
                  placeholder="Wider than the pencil mark now"></textarea>
      </label>
      <p class="row-between">
        <button class="btn-primary" type="submit">Add check-in</button>
        <label class="btn-secondary" id="checkin-photo">
          Add photo
          <input type="file" accept="image/*" hidden>
        </label>
      </p>
    </form>
    <p class="hint">
      Photos attach to the check-in once you have added it, so the timeline
      keeps them beside the note they belong to.
    </p>
  </section>

  <p class="stack">
    <button class="tap-text danger" type="button" id="issue-delete">Delete this issue</button>
  </p>

<?php endif; ?>

<div class="queue-pill" id="queue-pill" hidden>
  <span class="queue-spinner" aria-hidden="true"></span>
  <span id="queue-text">Processing…</span>
</div>

<script type="module" src="<?= asset('assets/issue.js') ?>"></script>
<?php
page_foot('issues');
