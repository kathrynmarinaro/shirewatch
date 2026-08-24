/* One log entry: saving the header, adding updates, attaching photos.
 *
 * ---------------------------------------------------------------------------
 * SAVE FIRST, THEN PHOTOS — AND THE UI HAS TO SAY SO.
 * ---------------------------------------------------------------------------
 *
 * api/upload.php refuses an owner row that does not exist, so a brand-new
 * entry has nothing for a photo to attach to until it has been saved once.
 * Rather than hiding that, the photo controls are disabled until there is an
 * id and are enabled the moment there is one.
 *
 * The alternative — buffering the files in the browser and posting them after
 * the save — means holding several megabytes in a phone's memory across a
 * request that can fail, and then having to explain that the entry saved but
 * the photos did not.
 *
 * ---------------------------------------------------------------------------
 * AN UPDATE IS CREATED BEFORE ITS PHOTO, FOR THE SAME REASON.
 * ---------------------------------------------------------------------------
 *
 * Photos attach to the log_updates row, not to the entry, so the timeline
 * keeps each photo beside the note it belongs to. Tapping "Add photo" with an
 * unsaved update therefore submits the update first and then opens the file
 * picker.
 *
 * ---------------------------------------------------------------------------
 * ONE FORM, TWO KINDS OF UPDATE.
 * ---------------------------------------------------------------------------
 *
 * A note is something you observed; a service is somebody being paid. The
 * chips flip a hidden input and reveal the money fields — they do not swap in
 * a second form. Two forms would mean two submit paths, two photo buttons and
 * a decision to make before you have started typing, when in practice you
 * often find out it was a service halfway through writing the note.
 *
 * The service fields are only READ when the service chip is on. Switching back
 * to Note leaves whatever was typed in the DOM, and sending it would file a
 * plumber's invoice against an observation because you tapped a chip twice.
 */

import { apiPost, ApiError } from './api.js';
import { attachTagField } from './tagfield.js';
import { attachUpload } from './upload.js';
import { attachLightbox } from './lightbox.js';
import { showSnackbar } from './swipe.js';

const form = document.getElementById('entry-form');
const saved = document.getElementById('entry-saved');
const pill = document.getElementById('queue-pill');
const pillText = document.getElementById('queue-text');

/* The id lives in one place — the form's data-id — and everything reads it
   from there. A module-level `let currentId` would be a second copy that has
   to be kept in step with the DOM the server rendered. */
function entryId() {
  return Number(form?.dataset.id || 0);
}

attachTagField('#entry-tags');
attachLightbox('#entry-photos');
attachLightbox('#entry-timeline');

function setPill(text) {
  if (!pill) { return; }
  if (text === null) { pill.hidden = true; return; }
  pillText.textContent = text;
  pill.hidden = false;
}

function explain(error) {
  if (!(error instanceof ApiError)) { return 'Something went wrong.'; }
  switch (error.code) {
    case 'empty_title':  return 'Give it a title first.';
    case 'not_found':    return 'This entry has been deleted.';
    case 'unauthorized': return 'Your session expired. Reload and sign in again.';
    case 'network_unreachable': return 'No connection.';
    default:             return 'Something went wrong.';
  }
}

/* ---- the header form --------------------------------------------------- */

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(form);

    const payload = {
      id: entryId() || undefined,
      title: String(data.get('title') || ''),
      description: String(data.get('description') || ''),
      noticed_on: String(data.get('noticed_on') || ''),
      severity: String(data.get('severity') || ''),
      check_interval_days: String(data.get('check_interval_days') || ''),
      tag_ids: data.getAll('tag_ids[]').map(Number),
    };

    try {
      const entry = await apiPost('api/entry-save.php', payload);
      const wasNew = !entryId();
      form.dataset.id = String(entry.id);
      enablePhotoControls();

      if (wasNew) {
        /* Replace rather than push: the "new entry" URL is not somewhere the
           back button should return to, because going back to it and saving
           again would create a second entry. */
        window.history.replaceState({}, '', `entry.php?id=${entry.id}`);
        /* A new entry has no timeline section rendered, and building one in JS
           would be a second implementation of the server's template. Reload
           once, here only, so the rest of the screen exists. */
        window.location.reload();
        return;
      }

      if (saved) {
        saved.textContent = 'Saved';
        setTimeout(() => { saved.textContent = ''; }, 2500);
      }
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

/* ---- note or service --------------------------------------------------- */

const updateForm = document.getElementById('update-form');
const kindBar = document.getElementById('update-kind');
const kindInput = updateForm?.querySelector('input[name="kind"]');

function currentKind() {
  return String(kindInput?.value || 'note');
}

function setKind(kind) {
  if (kindInput) { kindInput.value = kind; }

  kindBar?.querySelectorAll('[data-kind]').forEach((chip) => {
    const on = chip.dataset.kind === kind;
    chip.classList.toggle('is-on', on);
    chip.setAttribute('aria-pressed', on ? 'true' : 'false');
  });

  updateForm?.querySelectorAll('[data-kind-fields]').forEach((block) => {
    block.hidden = block.dataset.kindFields !== kind;
  });
}

if (kindBar) {
  kindBar.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-kind]');
    if (chip) { setKind(chip.dataset.kind); }
  });
  setKind(currentKind());
}

/* ---- photos ------------------------------------------------------------ */

/* The photo control is attached LAZILY, against an update that exists.
 *
 * attachUpload() needs a real owner id, and until you have written an update
 * there is nothing for the photo to belong to. So the first tap on "Add photo"
 * creates the update, attaches the uploader to it, and then opens the file
 * picker the tap was meant for. Subsequent taps go straight through.
 *
 * The click has to be intercepted BEFORE the OS file sheet opens — once it is
 * up, this page is not running and there is no later moment to do the work in.
 */
let photoDetach = null;

const updatePhotoLabel = document.getElementById('update-photo');
if (updatePhotoLabel) {
  const fileInput = updatePhotoLabel.querySelector('input[type="file"]');

  updatePhotoLabel.addEventListener('click', async (event) => {
    if (photoDetach) { return; }        // already wired; let the tap through
    event.preventDefault();

    const updateId = await submitUpdate({ silent: true });
    if (!updateId) { return; }

    photoDetach = attachUpload(updatePhotoLabel, {
      ownerType: 'update',
      ownerId: updateId,
      capture: 'single',
      onProgress: setPill,
      onDone: () => window.location.reload(),
    });
    fileInput.click();
  });
}

/** A new entry has no update form until it has been saved and reloaded. */
function enablePhotoControls() {
  const section = document.getElementById('update-add');
  if (section) { section.hidden = !entryId(); }
}

/* ---- updates ----------------------------------------------------------- */

async function submitUpdate({ silent = false } = {}) {
  if (!updateForm) { return 0; }
  const data = new FormData(updateForm);
  const kind = currentKind();

  const payload = {
    entry_id: Number(updateForm.dataset.entryId),
    kind: kind,
    noted_on: String(data.get('noted_on') || ''),
    severity: String(data.get('severity') || ''),
    note: String(data.get('note') || ''),
  };

  if (kind === 'service') {
    payload.vendor_id = Number(data.get('vendor_id') || 0);
    payload.vendor_name = String(data.get('vendor_name') || '');
    /* Sent as typed. An empty cost is "not recorded", which the server stores
       as NULL — turning it into 0 here would say the plumber came free. */
    payload.cost = String(data.get('cost') || '');
    payload.rating = String(data.get('rating') || '');
  }

  try {
    const result = await apiPost('api/entry-update.php', payload);
    if (!silent) { window.location.reload(); }
    return result.id;
  } catch (error) {
    showSnackbar(explain(error), { isError: true });
    return 0;
  }
}

if (updateForm) {
  updateForm.addEventListener('submit', (event) => {
    event.preventDefault();
    submitUpdate();
  });
}

/* ---- status ------------------------------------------------------------ */

const statusBar = document.getElementById('entry-status');
if (statusBar) {
  statusBar.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-status]');
    if (!button) { return; }

    try {
      await apiPost('api/entry-status.php', {
        id: entryId(),
        status: button.dataset.status,
      });
      statusBar.querySelectorAll('[data-status]').forEach((chip) => {
        chip.classList.toggle('is-on', chip === button);
      });
      showSnackbar(`Marked ${button.textContent.trim().toLowerCase()}`);
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

/* ---- delete ------------------------------------------------------------ */

const deleteButton = document.getElementById('entry-delete');
if (deleteButton) {
  deleteButton.addEventListener('click', () => {
    const timeline = document.getElementById('entry-timeline');
    const updates = timeline ? timeline.children.length : 0;
    const photos = document.querySelectorAll('.gallery-item').length;

    /* Say what is actually lost. "Are you sure?" cannot distinguish deleting a
       duplicate you logged twice from deleting three years of evidence, and
       there is no undo on this one. Dismissing is offered by name because it
       is almost always what someone reaching for delete actually wants. */
    const parts = [];
    if (updates > 0) { parts.push(`${updates} update${updates === 1 ? '' : 's'}`); }
    if (photos > 0) { parts.push(`${photos} photo${photos === 1 ? '' : 's'}`); }

    const detail = parts.length > 0
      ? `This deletes ${parts.join(' and ')} permanently. There is no undo.\n\nIf it just turned out to be nothing, mark it Dismissed instead — that keeps the record.`
      : 'This deletes the entry permanently. There is no undo.';

    if (!window.confirm(detail)) { return; }

    apiPost('api/entry-delete.php', { id: entryId() })
      .then(() => { window.location.href = 'log.php'; })
      .catch((error) => showSnackbar(explain(error), { isError: true }));
  });
}

enablePhotoControls();
