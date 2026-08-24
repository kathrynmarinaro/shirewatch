<?php
/* Rooms & Tags — the editing screen for the three vocabularies.
 *
 * Reached from the hamburger, not the tab bar: this is a thing you do twice a
 * year (lib/layout.php's menu_items()).
 *
 * ONE SCREEN FOR ALL THREE KINDS rather than three screens or three tabs. They
 * behave identically — add, rename, reorder, delete — so three copies of the
 * same list would be three places to fix the same bug, and the accordion means
 * the two you are not editing cost one line each.
 *
 * SERVER-RENDERED, AND IT WORKS WITH NO JS. Every row is a real form that
 * posts to itself; assets/tags.js upgrades the same markup to inline editing
 * and drag reordering. That matters more here than on other screens: this is
 * the screen you would reach for to fix a typo, and a broken script must not
 * be what stops you.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/tags.php';

require_login_page();

/* The gate fails open when no password hash is configured (lib/auth.php), and
 * this screen keeps its notice in the session across a redirect — so start the
 * session explicitly rather than relying on the gate having done it. */
auth_start_session();

/* ------------------------------------------------------------ no-JS posts */

/* The JS path uses the /api/tag-*.php endpoints. This handles the same four
 * actions as ordinary form posts, so the screen degrades rather than dying.
 * Both paths go through lib/tags.php, so there is one implementation of each
 * action and no way for the two to disagree. */
$notice = '';
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf_form();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $name   = (string) ($_POST['name'] ?? '');
    $kind   = (string) ($_POST['kind'] ?? '');

    if ($action === 'add' && in_array($kind, tag_kinds(), true)) {
        $newId = tag_add($kind, $name);
        $notice = $newId === null ? '' : 'Added “' . $name . '”.';
        $error  = $newId === null ? 'That name is empty.' : '';
    } elseif ($action === 'rename' && $id > 0) {
        $error  = tag_rename($id, $name) ? '' : 'Another tag of that kind is already called “' . $name . '”.';
        $notice = $error === '' ? 'Renamed.' : '';
    } elseif ($action === 'delete' && $id > 0) {
        $usage = tag_usage($id);
        $tag   = tag_by_id($id);
        if ($tag !== null && tag_delete($id)) {
            $touched = array_sum($usage);
            $notice  = 'Deleted “' . $tag['name'] . '”'
                . ($touched > 0 ? ', removing it from ' . $touched . ' item' . ($touched === 1 ? '' : 's') : '')
                . '.';
        }
    }

    /* POST-redirect-GET, so a refresh after adding a room does not add it
     * again — and so the notice survives exactly one render. */
    $_SESSION['tags_notice'] = $notice;
    $_SESSION['tags_error']  = $error;
    header('Location: tags.php');
    exit;
}

auth_start_session();
$notice = (string) ($_SESSION['tags_notice'] ?? '');
$error  = (string) ($_SESSION['tags_error'] ?? '');
unset($_SESSION['tags_notice'], $_SESSION['tags_error']);

/* ------------------------------------------------------------------ render */

page_head('Rooms & Tags', null);
screen_head('Rooms & Tags', page_menu());
?>

  <p class="hint">
    Renaming one of these updates it everywhere it is already used — every
    log entry, task and vendor follows the new name. Deleting one removes
    it from those items but never deletes the items themselves.
  </p>

<?php if ($notice !== ''): ?>
  <p class="card"><?= h($notice) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <p class="card field-err"><?= h($error) ?></p>
<?php endif; ?>

<?php
/* Rooms first and open by default: it is the list with 24 rows in it and the
 * one most likely to need editing. The other two are seeded from a generic
 * vocabulary that mostly does not need touching. */
$kinds = array(TAG_LOCATION, TAG_CATEGORY, TAG_WORK_TYPE);
foreach ($kinds as $index => $kind):
    $tags = tags_of_kind($kind);
?>
  <details class="accordion" <?= $index === 0 ? 'open' : '' ?>>
    <summary class="accordion-head">
      <?= h(tag_kind_label($kind)) ?>
      <span class="accordion-count"><?= count($tags) ?></span>
    </summary>
    <div class="accordion-body">

      <ul class="list" data-kind="<?= h($kind) ?>" data-tag-list>
      <?php foreach ($tags as $tag): ?>
        <li class="list-row" data-id="<?= (int) $tag['id'] ?>">
          <div class="row-slide">
            <span class="drag-handle" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M5 9h14M5 15h14"/></svg>
            </span>
            <span class="row-text"><?= h($tag['name']) ?></span>
            <?php /* A real form, so this row deletes with JS off. tags.js
                     intercepts the submit and replaces it with the confirm
                     sheet plus a usage count. */ ?>
            <form method="post" class="row-form" data-delete>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $tag['id'] ?>">
              <button class="icon-btn is-danger" type="submit"
                      aria-label="Delete <?= h($tag['name']) ?>">
                <svg viewBox="0 0 24 24"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>
              </button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>

      <?php if ($tags === array()): ?>
        <p class="empty">Nothing here yet.</p>
      <?php endif; ?>

      <?php /* The composer is the house quick-add, same as Grocery's. Enter on
               the phone keyboard submits it. */ ?>
      <form method="post" class="composer">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="kind" value="<?= h($kind) ?>">
        <input class="composer-input" type="text" name="name" autocomplete="off"
               placeholder="Add <?= h(strtolower(tag_kind_label($kind))) ?>"
               aria-label="Add to <?= h(tag_kind_label($kind)) ?>">
        <button class="composer-add" type="submit">Add</button>
      </form>

    </div>
  </details>
<?php endforeach; ?>

  <?php /* Rename happens in place via inline-edit.js; this is the fallback
           form it posts through when JS is off. Kept out of every row so the
           markup above stays one row per row. */ ?>
  <form method="post" id="rename-fallback" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="rename">
    <input type="hidden" name="id" value="">
    <input type="text" name="name" value="">
  </form>

<script type="module" src="<?= asset('assets/tags.js') ?>"></script>
<?php
page_foot(null);
