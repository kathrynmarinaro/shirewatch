/* Rooms & Tags — upgrades the server-rendered screen in place.
 *
 * ---------------------------------------------------------------------------
 * EVERY ACTION HERE ALREADY WORKS WITHOUT THIS FILE.
 * ---------------------------------------------------------------------------
 *
 * public/tags.php renders real forms that post to themselves and handles all
 * four actions server-side. This module intercepts them and swaps in the
 * inline editor, drag reordering and a delete confirmation that knows how many
 * items the tag is on. Turn JavaScript off and the screen is plainer and
 * slower, and still completely usable.
 *
 * That matters more on this screen than on most: it is the one you would reach
 * for to fix a typo in a room name, and a script that failed to load must not
 * be what stops you.
 *
 * ---------------------------------------------------------------------------
 * WHY THE DELETE CONFIRMATION FETCHES A COUNT FIRST.
 * ---------------------------------------------------------------------------
 *
 * Deleting a tag lifts it off every item it was on, and there is no undo. "Are
 * you sure?" cannot tell you what you are about to lose; "Kitchen is on 6
 * issues and 2 tasks" can. One extra round trip, taken only when you have
 * actually tapped the bin.
 */

import { apiPost, apiGet, ApiError } from './api.js';
import { attachInlineEdit } from './inline-edit.js';
import { attachReorder } from './reorder.js';
import { showSnackbar } from './swipe.js';

const lists = Array.from(document.querySelectorAll('[data-tag-list]'));

/* Names are VARCHAR(80) in schema.sql. Mirrored here so the editor stops you
   at the limit rather than letting the server silently truncate — a rename
   that comes back shorter than what you typed reads as a bug. */
const NAME_MAX = 80;

/** Turn an ApiError into something a person can act on. */
function explain(error) {
  if (!(error instanceof ApiError)) { return 'Something went wrong.'; }
  switch (error.code) {
    case 'name_taken':    return 'Another tag of that kind already has that name.';
    case 'empty_name':    return 'A tag needs a name.';
    case 'unauthorized':  return 'Your session expired. Reload and sign in again.';
    case 'network_unreachable': return 'No connection.';
    default:              return 'Something went wrong.';
  }
}

lists.forEach((list) => {
  /* ---- rename ---------------------------------------------------------- */

  attachInlineEdit(list, {
    maxLength: NAME_MAX,
    onSave: async (id, text) => {
      const tag = await apiPost('api/tag-rename.php', { id: Number(id), name: text });
      /* Return what the SERVER stored, not what was typed. It trims, and
         showing the trimmed value is how you find out that it did. */
      return tag.name;
    },
  });

  /* ---- reorder --------------------------------------------------------- */

  attachReorder(list, {
    onReorder: async (orderedIds) => {
      await apiPost('api/tag-reorder.php', { ids: orderedIds.map(Number) });
    },
  });
});

/* ---- delete ------------------------------------------------------------ */

/* Delegated to the document rather than bound per form, so the handler
   survives a row being re-rendered and costs one listener instead of forty. */
document.addEventListener('submit', async (event) => {
  const form = event.target.closest('form[data-delete]');
  if (!form) { return; }

  event.preventDefault();

  const row  = form.closest('.list-row');
  const id   = Number(form.querySelector('[name="id"]').value);
  const name = row ? row.querySelector('.row-text').textContent.trim() : 'this tag';

  /* A failed count must not block the delete — it is context, not a gate. */
  let usage = null;
  try {
    usage = await apiGet('api/tag-usage.php', { id });
  } catch (error) {
    usage = null;
  }

  const confirmed = await confirmDelete(name, usage);
  if (!confirmed) { return; }

  try {
    const result = await apiPost('api/tag-delete.php', { id });
    if (row) { row.remove(); }
    bumpCount(form);

    const touched = result.usage
      ? Object.values(result.usage).reduce((a, b) => a + b, 0)
      : 0;
    showSnackbar(
      touched > 0
        ? `Deleted “${name}” from ${touched} item${touched === 1 ? '' : 's'}`
        : `Deleted “${name}”`
    );
  } catch (error) {
    showSnackbar(explain(error), { isError: true });
  }
});

/**
 * The confirmation sheet. Resolves true to go ahead.
 *
 * Built on demand and removed on close, like every other sheet in the app —
 * an empty fixed-position element sitting in the page is one z-index mistake
 * away from swallowing taps on the tab bar underneath it.
 */
function confirmDelete(name, usage) {
  return new Promise((resolve) => {
    const total = usage
      ? Object.values(usage).reduce((a, b) => a + b, 0)
      : 0;

    const parts = [];
    if (usage) {
      const labels = { issue: 'issue', task: 'task', record: 'record', vendor: 'vendor' };
      Object.entries(usage).forEach(([key, count]) => {
        if (count > 0) {
          parts.push(`${count} ${labels[key]}${count === 1 ? '' : 's'}`);
        }
      });
    }

    const sheet = document.createElement('div');
    sheet.className = 'sheet';
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');

    const panel = document.createElement('div');
    panel.className = 'sheet-panel';

    const head = document.createElement('div');
    head.className = 'sheet-group';
    head.textContent = total > 0
      ? `“${name}” is on ${parts.join(' and ')}`
      : `“${name}” isn’t used anywhere`;
    panel.appendChild(head);

    if (total > 0) {
      const note = document.createElement('div');
      note.className = 'sheet-group';
      note.style.textTransform = 'none';
      note.style.letterSpacing = '0';
      note.style.fontWeight = '400';
      note.textContent = 'They keep their other tags. Nothing else is deleted.';
      panel.appendChild(note);
    }

    const go = document.createElement('button');
    go.type = 'button';
    go.className = 'is-danger';
    go.textContent = `Delete “${name}”`;
    go.addEventListener('click', () => { sheet.remove(); resolve(true); });
    panel.appendChild(go);

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'sheet-cancel';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', () => { sheet.remove(); resolve(false); });
    panel.appendChild(cancel);

    sheet.addEventListener('click', (event) => {
      if (event.target === sheet) { sheet.remove(); resolve(false); }
    });

    sheet.appendChild(panel);
    document.body.appendChild(sheet);
  });
}

/** Keep the accordion's count honest after a delete. */
function bumpCount(form) {
  const details = form.closest('details');
  const badge = details ? details.querySelector('.accordion-count') : null;
  if (!badge) { return; }
  badge.textContent = String(Math.max(0, Number(badge.textContent) - 1));
}
