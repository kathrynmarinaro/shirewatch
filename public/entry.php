<?php
/* One log entry: its header, its timeline, and the form that adds to it.
 *
 * Also the NEW-entry screen (?new=1) — the same form with nothing in it. One
 * template for both, because a separate "add" screen is a second place to add
 * a field to and the second one always gets forgotten.
 *
 * ---------------------------------------------------------------------------
 * THE CAPTURE FLOW IS: SAVE FIRST, THEN PHOTOS.
 * ---------------------------------------------------------------------------
 *
 * api/upload.php refuses an owner row that does not exist yet, so the entry
 * has to be created before a photo can attach to it. On a new entry the photo
 * control is therefore disabled until the first save, and assets/log.js
 * enables it once it has an id. Fighting that — buffering files client-side
 * and posting them after — would mean holding several megabytes in a phone's
 * memory through a request that might fail.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/log.php';
require_once __DIR__ . '/../lib/vendors.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today   = sw_today();
$isNew   = isset($_GET['new']);
$id      = (int) ($_GET['id'] ?? 0);
$entry   = $isNew ? null : entry_get($id);

if (!$isNew && $entry === null) {
    /* A deleted entry reached from a stale bookmark or the back button. Fail
     * soft to the list rather than 404ing a screen the app itself linked to. */
    header('Location: log.php');
    exit;
}

$updates    = $entry === null ? array() : entry_updates($entry['id']);
$trend      = entry_trend($updates);
$entryTags   = $entry === null ? array() : tags_for('entry', $entry['id']);
$entryPhotos = $entry === null ? array() : media_for('entry', $entry['id']);
$selected    = array_column($entryTags, 'id');

$pickable = array_merge(tags_of_kind(TAG_LOCATION), tags_of_kind(TAG_CATEGORY));
$vendors  = vendors_list();

page_head($isNew ? 'New log entry' : $entry['title'], 'log');
screen_head($isNew ? 'New log entry' : 'Log entry', page_menu());
?>

<form id="entry-form" data-id="<?= $entry === null ? '' : (int) $entry['id'] ?>">

  <label class="field">
    <span>What did you notice?</span>
    <input class="input" type="text" name="title" maxlength="200" required
           value="<?= $entry === null ? '' : h($entry['title']) ?>"
           placeholder="Hairline crack in the library ceiling">
  </label>

  <label class="field">
    <span>Description</span>
    <textarea class="input" name="description" rows="3"
              placeholder="Where exactly, how big, what you already tried"><?= $entry === null ? '' : h($entry['description']) ?></textarea>
  </label>

  <div class="row">
    <label class="field">
      <span>Noticed on</span>
      <input class="input" type="date" name="noticed_on"
             value="<?= h($entry === null ? $today : $entry['noticed_on']) ?>">
    </label>

    <label class="field">
      <span>Severity</span>
      <?php /* The empty option is FIRST and is the default. Severity is
               optional and NULL renders as nothing, so pre-selecting "Watch"
               would silently rate every entry nobody rated. */ ?>
      <select class="input" name="severity">
        <option value="">Not set</option>
        <?php foreach (array(1, 2, 3, 4) as $level): ?>
          <option value="<?= $level ?>"
            <?= ($entry !== null && $entry['severity'] === $level) ? 'selected' : '' ?>>
            <?= h(entry_severity_label($level)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <label class="field">
    <span>Check back every</span>
    <?php /* THE COLUMN THAT MAKES THE TIMELINE HAPPEN. An entry you log and
             never revisit has one photo and no progression, and the premise of
             this app is the second photo. Blank is legal — not everything
             needs watching. */ ?>
    <select class="input" name="check_interval_days">
      <option value="">Don’t remind me</option>
      <?php foreach (array(30 => 'Month', 90 => '3 months', 180 => '6 months', 365 => 'Year') as $days => $label): ?>
        <option value="<?= $days ?>"
          <?= ($entry !== null && $entry['check_interval_days'] === $days) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <div class="field">
    <span>Rooms and systems</span>
    <div class="tagfield" id="entry-tags">
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
    <span class="hint" id="entry-saved" aria-live="polite"></span>
  </p>
</form>

<?php if (!$isNew): ?>

  <?php /* Status lives outside the form: it is a decision, not a field, and
           each one has a different consequence. Dismissing keeps the record
           and the photos; deleting does not, which is why they are not
           adjacent. */ ?>
  <section class="stack">
    <h2 class="cat-head">Status</h2>
    <div class="filterbar" role="group" aria-label="Status" id="entry-status">
      <?php foreach (entry_statuses() as $status): ?>
        <button class="chip<?= $entry['status'] === $status ? ' is-on' : '' ?>"
                type="button" data-status="<?= h($status) ?>"><?= h(entry_status_label($status)) ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($entry['resolved_on'] !== null): ?>
      <p class="hint">
        <?= h(entry_status_label($entry['status'])) ?> on <?= h(fmt_relative_due($entry['resolved_on'], $today)) ?>.
        <?php if ($entry['resolved_by_update_id'] !== null): ?>
          <a href="#update-<?= (int) $entry['resolved_by_update_id'] ?>">See what fixed it</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </section>

  <?php if ($entryPhotos !== array()): ?>
  <section class="stack">
    <h2 class="cat-head">First photos</h2>
    <?= render_gallery($entryPhotos, array('id' => 'entry-photos', 'date' => $entry['noticed_on'])) ?>
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
      <p class="empty">No updates yet. Add one below when you look at it again.</p>
    <?php else: ?>
      <ol class="timeline" id="entry-timeline">
        <?php /* OLDEST FIRST — this is a progression, and newest-first tells
                 the story backwards. */ ?>
        <?php foreach ($updates as $update): ?>
          <li class="timeline-item<?= $update['kind'] === 'service' ? ' is-service' : '' ?>"
              id="update-<?= (int) $update['id'] ?>" data-update-id="<?= (int) $update['id'] ?>">
            <span class="timeline-title">
              <?= h(fmt_date($update['noted_on'])) ?>
              <?= render_severity($update['severity']) ?>
              <?php if ($update['kind'] === 'service'): ?>
                <?php /* A visit is an event in this story, not a record filed
                         somewhere else. Everything about it lives on the line
                         where it happened. */ ?>
                <span class="chip is-static">Service</span>
              <?php endif; ?>
            </span>
            <?php if ($update['kind'] === 'service'): ?>
              <span class="timeline-sub">
                <?php $who = (string) ($update['vendor_name'] ?? ''); ?>
                <?= $who === '' ? 'No vendor recorded' : h($who) ?>
                <?php $cost = render_cost($update['cost']); ?>
                <?= $cost === '' ? '' : ' · ' . h($cost) ?>
                <?= render_stars($update['rating'] === null ? null : (float) $update['rating']) ?>
              </span>
            <?php endif; ?>
            <?php if ($update['note'] !== ''): ?>
              <span class="timeline-sub"><?= nl2br(h($update['note'])) ?></span>
            <?php endif; ?>
            <?= render_gallery($update['photos'], array('strip' => true, 'date' => $update['noted_on'])) ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

  <section class="stack" id="update-add">
    <h2 class="cat-head">Add an update</h2>
    <form id="update-form" data-entry-id="<?= (int) $entry['id'] ?>">

      <?php /* ONE FORM, TWO KINDS. A note is something you observed; a service
               is somebody being paid. They belong on the same timeline — that
               is the whole point of the merge — so this is one form with the
               money fields hidden until you say it was a service. */ ?>
      <div class="filterbar" role="group" aria-label="Kind of update" id="update-kind">
        <button class="chip is-on" type="button" data-kind="note">Note</button>
        <button class="chip" type="button" data-kind="service">Service</button>
      </div>
      <input type="hidden" name="kind" value="note">

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
              <option value="<?= $level ?>"><?= h(entry_severity_label($level)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label class="field">
        <span>What has changed?</span>
        <textarea class="input" name="note" rows="2"
                  placeholder="Wider than the pencil mark now"></textarea>
      </label>
      <div data-kind-fields="service" hidden>
        <label class="field">
          <span>Who came out</span>
          <select class="input" name="vendor_id">
            <option value="">Not in the directory</option>
            <?php foreach ($vendors as $vendor): ?>
              <option value="<?= (int) $vendor['id'] ?>"><?= h($vendor['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

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
      </div>

      <p class="row-between">
        <button class="btn-primary" type="submit">Add update</button>
        <label class="btn-secondary" id="update-photo">
          Add photo
          <input type="file" accept="image/*" hidden>
        </label>
      </p>
    </form>
    <p class="hint">
      Photos and invoices attach to the update once you have added it, so the
      timeline keeps them beside the thing they belong to.
    </p>
  </section>

  <p class="stack">
    <button class="tap-text danger" type="button" id="entry-delete">Delete this entry</button>
  </p>

<?php endif; ?>

<div class="queue-pill" id="queue-pill" hidden>
  <span class="queue-spinner" aria-hidden="true"></span>
  <span id="queue-text">Processing…</span>
</div>

<script type="module" src="<?= asset('assets/entry.js') ?>"></script>
<?php
page_foot('log');
