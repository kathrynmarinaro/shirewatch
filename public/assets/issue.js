/* One issue: saving the header, adding check-ins, attaching photos.
 *
 * ---------------------------------------------------------------------------
 * SAVE FIRST, THEN PHOTOS — AND THE UI HAS TO SAY SO.
 * ---------------------------------------------------------------------------
 *
 * api/upload.php refuses an owner row that does not exist, so a brand-new
 * issue has nothing for a photo to attach to until it has been saved once.
 * Rather than hiding that, the photo controls are disabled until there is an
 * id and are enabled the moment there is one.
 *
 * The alternative — buffering the files in the browser and posting them after
 * the save — means holding several megabytes in a phone's memory across a
 * request that can fail, and then having to explain that the issue saved but
 * the photos did not.
 *
 * ---------------------------------------------------------------------------
 * A CHECK-IN IS CREATED BEFORE ITS PHOTO, FOR THE SAME REASON.
 * ---------------------------------------------------------------------------
 *
 * Photos attach to the issue_updates row, not to the issue, so the timeline
 * keeps each photo beside the note it belongs to. Tapping "Add photo" with an
 * unsaved check-in therefore submits the check-in first and then opens the
 * file picker.
 */

import { apiPost, ApiError } from './api.js';
import { attachTagField } from './tagfield.js';
import { attachUpload } from './upload.js';
import { attachLightbox } from './lightbox.js';
import { showSnackbar } from './swipe.js';

const form = document.getElementById('issue-form');
const saved = document.getElementById('issue-saved');
const pill = document.getElementById('queue-pill');
const pillText = document.getElementById('queue-text');

/* The id lives in one place — the form's data-id — and everything reads it
   from there. A module-level `let currentId` would be a second copy that has
   to be kept in step with the DOM the server rendered. */
function issueId() {
  return Number(form?.dataset.id || 0);
}

attachTagField('#issue-tags');
attachLightbox('#issue-photos');
attachLightbox('#issue-timeline');

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
    case 'not_found':    return 'This issue has been deleted.';
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
      id: issueId() || undefined,
      title: String(data.get('title') || ''),
      description: String(data.get('description') || ''),
      noticed_on: String(data.get('noticed_on') || ''),
      severity: String(data.get('severity') || ''),
      check_interval_days: String(data.get('check_interval_days') || ''),
      tag_ids: data.getAll('tag_ids[]').map(Number),
    };

    try {
      const issue = await apiPost('api/issue-save.php', payload);
      const wasNew = !issueId();
      form.dataset.id = String(issue.id);
      enablePhotoControls();

      if (wasNew) {
        /* Replace rather than push: the "new issue" URL is not somewhere the
           back button should return to, because going back to it and saving
           again would create a second issue. */
        window.history.replaceState({}, '', `issue.php?id=${issue.id}`);
        /* A new issue has no timeline section rendered, and building one in JS
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

/* ---- photos ------------------------------------------------------------ */

/* The photo control is attached LAZILY, against a check-in that exists.
 *
 * attachUpload() needs a real owner id, and until you have written a check-in
 * there is nothing for the photo to belong to. So the first tap on "Add photo"
 * creates the check-in, attaches the uploader to it, and then opens the file
 * picker the tap was meant for. Subsequent taps go straight through.
 *
 * The click has to be intercepted BEFORE the OS file sheet opens — once it is
 * up, this page is not running and there is no later moment to do the work in.
 */
let photoDetach = null;

const checkinPhotoLabel = document.getElementById('checkin-photo');
if (checkinPhotoLabel) {
  const fileInput = checkinPhotoLabel.querySelector('input[type="file"]');

  checkinPhotoLabel.addEventListener('click', async (event) => {
    if (photoDetach) { return; }        // already wired; let the tap through
    event.preventDefault();

    const updateId = await submitCheckin({ silent: true });
    if (!updateId) { return; }

    photoDetach = attachUpload(checkinPhotoLabel, {
      ownerType: 'issue_update',
      ownerId: updateId,
      capture: 'single',
      onProgress: setPill,
      onDone: () => window.location.reload(),
    });
    fileInput.click();
  });
}

/** A new issue has no check-in form until it has been saved and reloaded. */
function enablePhotoControls() {
  const section = document.getElementById('checkin');
  if (section) { section.hidden = !issueId(); }
}

/* ---- check-ins --------------------------------------------------------- */

const checkinForm = document.getElementById('checkin-form');

async function submitCheckin({ silent = false } = {}) {
  if (!checkinForm) { return 0; }
  const data = new FormData(checkinForm);

  try {
    const result = await apiPost('api/issue-update.php', {
      issue_id: Number(checkinForm.dataset.issueId),
      noted_on: String(data.get('noted_on') || ''),
      severity: String(data.get('severity') || ''),
      note: String(data.get('note') || ''),
    });
    if (!silent) { window.location.reload(); }
    return result.id;
  } catch (error) {
    showSnackbar(explain(error), { isError: true });
    return 0;
  }
}

if (checkinForm) {
  checkinForm.addEventListener('submit', (event) => {
    event.preventDefault();
    submitCheckin();
  });
}

/* ---- status ------------------------------------------------------------ */

const statusBar = document.getElementById('issue-status');
if (statusBar) {
  statusBar.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-status]');
    if (!button) { return; }

    try {
      await apiPost('api/issue-status.php', {
        id: issueId(),
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

const deleteButton = document.getElementById('issue-delete');
if (deleteButton) {
  deleteButton.addEventListener('click', () => {
    const timeline = document.getElementById('issue-timeline');
    const entries = timeline ? timeline.children.length : 0;
    const photos = document.querySelectorAll('.gallery-item').length;

    /* Say what is actually lost. "Are you sure?" cannot distinguish deleting a
       duplicate you logged twice from deleting three years of evidence, and
       there is no undo on this one. Dismissing is offered by name because it
       is almost always what someone reaching for delete actually wants. */
    const parts = [];
    if (entries > 0) { parts.push(`${entries} check-in${entries === 1 ? '' : 's'}`); }
    if (photos > 0) { parts.push(`${photos} photo${photos === 1 ? '' : 's'}`); }

    const detail = parts.length > 0
      ? `This deletes ${parts.join(' and ')} permanently. There is no undo.\n\nIf it just turned out to be nothing, mark it Dismissed instead — that keeps the record.`
      : 'This deletes the issue permanently. There is no undo.';

    if (!window.confirm(detail)) { return; }

    apiPost('api/issue-delete.php', { id: issueId() })
      .then(() => { window.location.href = 'issues.php'; })
      .catch((error) => showSnackbar(explain(error), { isError: true }));
  });
}

enablePhotoControls();
