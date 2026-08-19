<?php
/* One vendor: contact details, their rating, and the work they have done.
 * Also the NEW-vendor screen (?new=1).
 *
 * THE WORK HISTORY IS READ-ONLY AND AUTOMATIC. There is no way to add a job to
 * a vendor from this screen — you log the service record and it appears here.
 * One source of truth, per the brief.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/vendors.php';
require_once __DIR__ . '/../lib/records.php';
require_once __DIR__ . '/../lib/render.php';

require_login_page();

$today  = sw_today();
$isNew  = isset($_GET['new']) || (int) ($_GET['id'] ?? 0) === 0;
$id     = (int) ($_GET['id'] ?? 0);
$vendor = $isNew ? null : vendor_get($id);

if (!$isNew && $vendor === null) {
    header('Location: vendors.php');
    exit;
}

$jobs     = $vendor === null ? array() : records_list(array('vendor_id' => $vendor['id']));
$workTags = tags_of_kind(TAG_WORK_TYPE);
$selected = $vendor === null ? array() : array_column($vendor['tags'], 'id');

page_head($isNew ? 'Add a vendor' : $vendor['name'], 'vendors');
screen_head($isNew ? 'Add a vendor' : 'Vendor', page_menu());
?>

<form id="vendor-form" data-id="<?= $vendor === null ? '' : (int) $vendor['id'] ?>">

  <label class="field">
    <span>Name</span>
    <input class="input" type="text" name="name" maxlength="160" required
           value="<?= $vendor === null ? '' : h($vendor['name']) ?>">
  </label>

  <div class="row">
    <label class="field">
      <span>Phone</span>
      <?php /* type="tel" so the phone shows a keypad, and the value stays a
               string — a number type would mangle "(512) 555-0142". */ ?>
      <input class="input" type="tel" name="phone" maxlength="40"
             value="<?= $vendor === null ? '' : h($vendor['phone']) ?>">
    </label>
    <label class="field">
      <span>Email</span>
      <input class="input" type="email" name="email" maxlength="255"
             value="<?= $vendor === null ? '' : h($vendor['email']) ?>">
    </label>
  </div>

  <label class="field">
    <span>Notes</span>
    <textarea class="input" name="notes" rows="3"
              placeholder="Ask for Danny. Charges a trip fee out of hours."><?= $vendor === null ? '' : h($vendor['notes']) ?></textarea>
  </label>

  <div class="field">
    <span>Work types</span>
    <div class="tagfield" id="vendor-tags">
      <select multiple name="tag_ids[]">
        <?php foreach ($workTags as $tag): ?>
          <option value="<?= (int) $tag['id'] ?>" data-kind="<?= h($tag['kind']) ?>"
            <?= in_array($tag['id'], $selected, true) ? 'selected' : '' ?>><?= h($tag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <label class="field">
    <span>Override the overall rating</span>
    <?php /* NULL means "use the average". The average is printed beside this
             when both exist, so an override never hides what it overrode. */ ?>
    <select class="input" name="rating_override">
      <option value="">Use the average of their jobs</option>
      <?php foreach (array(1, 2, 3, 4, 5) as $star): ?>
        <option value="<?= $star ?>"
          <?= ($vendor !== null && $vendor['rating_override'] === $star) ? 'selected' : '' ?>>
          <?= $star ?> star<?= $star === 1 ? '' : 's' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <p class="row-between">
    <button class="btn-primary" type="submit">Save</button>
    <span class="hint" id="vendor-saved" aria-live="polite"></span>
  </p>
</form>

<?php if (!$isNew): ?>

  <section class="stack">
    <h2 class="cat-head">Rating</h2>
    <?php if ($vendor['rating'] === null): ?>
      <p class="hint">No rated jobs yet. Rate a service record and it will show here.</p>
    <?php else: ?>
      <p>
        <?= render_stars($vendor['rating'], array('number' => true)) ?>
        <?php if ($vendor['rating_override'] !== null && $vendor['average'] !== null): ?>
          <span class="stars-note">
            Overridden. Their jobs average <?= h(number_format((float) $vendor['average'], 1)) ?>
            over <?= (int) $vendor['rated_jobs'] ?> rated job<?= $vendor['rated_jobs'] === 1 ? '' : 's' ?>.
          </span>
        <?php elseif ($vendor['rating_override'] !== null): ?>
          <span class="stars-note">Set by hand — none of their jobs are rated.</span>
        <?php else: ?>
          <span class="stars-note">
            Averaged over <?= (int) $vendor['rated_jobs'] ?> rated job<?= $vendor['rated_jobs'] === 1 ? '' : 's' ?>.
          </span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </section>

  <section class="stack">
    <h2 class="cat-head">
      Work history
      <span class="cat-count"><?= count($jobs) ?></span>
    </h2>
    <?php if ($jobs === array()): ?>
      <p class="empty">Nothing logged against them yet.</p>
    <?php else: ?>
      <?php /* Filled in automatically from the service records — there is no
               control here to add one, deliberately. */ ?>
      <ul class="list">
        <?php foreach ($jobs as $job): ?>
          <li class="list-row">
            <div class="row-slide">
              <a class="row-body" href="record.php?id=<?= (int) $job['id'] ?>">
                <span class="row-text"><?= h($job['title']) ?></span>
                <span class="row-sub">
                  <?= h(fmt_date($job['performed_on'], 'j M Y')) ?>
                  <?php $cost = render_cost($job['cost']); ?>
                  <?= $cost === '' ? '' : ' · ' . h($cost) ?>
                </span>
              </a>
              <?= render_stars($job['rating'] === null ? null : (float) $job['rating']) ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php
        $known = array_filter($jobs, static fn(array $j): bool => $j['cost'] !== null);
        if ($known !== array()):
          $sum = array_sum(array_map(static fn(array $j): float => (float) $j['cost'], $known));
      ?>
        <p class="hint">
          $<?= number_format($sum, 2) ?> across <?= count($known) ?> job<?= count($known) === 1 ? '' : 's' ?> with a recorded cost.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <p class="stack">
    <button class="tap-text danger" type="button" id="vendor-delete"
            data-jobs="<?= count($jobs) ?>">Delete this vendor</button>
  </p>

<?php endif; ?>

<script type="module" src="<?= asset('assets/vendors.js') ?>"></script>
<?php
page_foot('vendors');
