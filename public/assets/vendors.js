/* The vendor directory and one vendor's screen. */

import { apiPost, ApiError } from './api.js';
import { attachTagField } from './tagfield.js';
import { showSnackbar } from './swipe.js';

const form = document.getElementById('vendor-form');

function explain(error) {
  if (!(error instanceof ApiError)) { return 'Something went wrong.'; }
  switch (error.code) {
    case 'empty_name':   return 'A vendor needs a name.';
    case 'not_found':    return 'This vendor has been deleted.';
    case 'unauthorized': return 'Your session expired. Reload and sign in again.';
    case 'network_unreachable': return 'No connection.';
    default:             return 'Something went wrong.';
  }
}

if (form) {
  attachTagField('#vendor-tags');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(form);

    try {
      const vendor = await apiPost('api/vendor-save.php', {
        id: Number(form.dataset.id || 0) || undefined,
        name: String(data.get('name') || ''),
        phone: String(data.get('phone') || ''),
        email: String(data.get('email') || ''),
        notes: String(data.get('notes') || ''),
        /* Empty means "go back to the average". Never sent as 0 — zero is not
           a legal rating, and clearing an override and rating them zero must
           not be the same request. */
        rating_override: String(data.get('rating_override') || ''),
        tag_ids: data.getAll('tag_ids[]').map(Number),
      });

      if (!form.dataset.id) {
        window.location.replace(`vendor.php?id=${vendor.id}`);
        return;
      }
      const saved = document.getElementById('vendor-saved');
      if (saved) {
        saved.textContent = 'Saved';
        setTimeout(() => { saved.textContent = ''; }, 2500);
      }
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

const deleteButton = document.getElementById('vendor-delete');
if (deleteButton) {
  deleteButton.addEventListener('click', () => {
    const jobs = Number(deleteButton.dataset.jobs || 0);

    /* Say that the history SURVIVES. Without that sentence, deleting a vendor
       with eight jobs against them looks like deleting eight thousand dollars
       of records, so nobody does it and the directory fills with people who
       went out of business. */
    const detail = jobs > 0
      ? `Delete this vendor?\n\nTheir ${jobs} job${jobs === 1 ? '' : 's'} stay in the service history and keep their name — only the directory entry goes.`
      : 'Delete this vendor?';

    if (!window.confirm(detail)) { return; }

    apiPost('api/vendor-delete.php', { id: Number(form.dataset.id) })
      .then(() => { window.location.href = 'vendors.php'; })
      .catch((error) => showSnackbar(explain(error), { isError: true }));
  });
}
