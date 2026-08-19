/* Service records: the form, the batch upload, and the resolve link. */

import { apiPost, ApiError } from './api.js';
import { attachTagField } from './tagfield.js';
import { attachUpload } from './upload.js';
import { attachLightbox } from './lightbox.js';
import { showSnackbar } from './swipe.js';

const form = document.getElementById('record-form');

function explain(error) {
  if (!(error instanceof ApiError)) { return 'Something went wrong.'; }
  switch (error.code) {
    case 'empty_title':  return 'Say what was done first.';
    case 'not_found':    return 'This record has been deleted.';
    case 'unauthorized': return 'Your session expired. Reload and sign in again.';
    case 'network_unreachable': return 'No connection.';
    default:             return 'Something went wrong.';
  }
}

attachLightbox('#record-photos');

if (form) {
  attachTagField('#record-tags');

  const pill = document.getElementById('queue-pill');
  const pillText = document.getElementById('queue-text');
  const setPill = (text) => {
    if (!pill) { return; }
    if (text === null) { pill.hidden = true; return; }
    pillText.textContent = text;
    pill.hidden = false;
  };

  /* The batch control only exists once the record does — api/upload.php
     refuses an owner row that is not there yet, and the server does not render
     this section on a new record. */
  const uploadLabel = document.getElementById('record-upload');
  if (uploadLabel && form.dataset.id) {
    attachUpload(uploadLabel, {
      ownerType: 'record',
      ownerId: Number(form.dataset.id),
      capture: 'batch',
      onProgress: setPill,
      onDone: () => window.location.reload(),
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(form);

    const payload = {
      id: Number(form.dataset.id || 0) || undefined,
      title: String(data.get('title') || ''),
      description: String(data.get('description') || ''),
      performed_on: String(data.get('performed_on') || ''),
      /* Sent as typed. record_clean_cost() strips currency symbols and
         separators server-side, and an empty string stays NULL — "not
         recorded" is not "free". */
      cost: String(data.get('cost') || ''),
      rating: String(data.get('rating') || ''),
      vendor_id: String(data.get('vendor_id') || ''),
      vendor_name: String(data.get('vendor_name') || ''),
      issue_id: String(data.get('issue_id') || ''),
      task_id: String(data.get('task_id') || ''),
      tag_ids: data.getAll('tag_ids[]').map(Number),
    };

    try {
      const record = await apiPost('api/record-save.php', payload);
      if (!form.dataset.id) {
        /* Replace, not push: going back to the blank form and saving again
           would create a second record. */
        window.location.replace(`record.php?id=${record.id}`);
        return;
      }
      const saved = document.getElementById('record-saved');
      if (saved) {
        saved.textContent = 'Saved';
        setTimeout(() => { saved.textContent = ''; }, 2500);
      }
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

/* "This is what fixed it" is a separate claim from "this work relates to that
   issue", so it is a separate action rather than something linking implies. */
const resolveButton = document.getElementById('resolve-issue');
if (resolveButton) {
  resolveButton.addEventListener('click', async () => {
    try {
      await apiPost('api/issue-status.php', {
        id: Number(resolveButton.dataset.issue),
        status: 'resolved',
        record_id: Number(resolveButton.dataset.record),
      });
      window.location.reload();
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

const deleteButton = document.getElementById('record-delete');
if (deleteButton) {
  deleteButton.addEventListener('click', () => {
    const docs = document.querySelectorAll('.doclist .doc').length;
    const extra = docs > 0
      ? `\n\nThis includes ${docs} uploaded document${docs === 1 ? '' : 's'} — if that is a scanned invoice or warranty, this may be the only copy.`
      : '';
    if (!window.confirm(`Delete this record and its photos? There is no undo.${extra}`)) { return; }

    apiPost('api/record-delete.php', { id: Number(form.dataset.id) })
      .then(() => { window.location.href = 'history.php'; })
      .catch((error) => showSnackbar(explain(error), { isError: true }));
  });
}
