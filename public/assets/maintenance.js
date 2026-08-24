/* Maintenance: the list's checkboxes and the task editor.
 *
 * ONE MODULE FOR BOTH SCREENS. They share the complete/undo behaviour, and the
 * alternative is two files that both have to be fixed when the undo copy
 * changes. Each block below no-ops when its element is not on the page.
 */

import { apiPost, ApiError } from './api.js';
import { attachTagField } from './tagfield.js';
import { showSnackbar } from './swipe.js';

function explain(error) {
  if (!(error instanceof ApiError)) { return 'Something went wrong.'; }
  switch (error.code) {
    case 'empty_title':     return 'Give it a title first.';
    case 'bad_recurrence':  return 'That schedule would never come due — pick months, or an interval.';
    case 'not_found':       return 'This task has been deleted.';
    case 'unauthorized':    return 'Your session expired. Reload and sign in again.';
    case 'network_unreachable': return 'No connection.';
    default:                return 'Something went wrong.';
  }
}

/* ---- completing from the list ------------------------------------------ */

/* Delegated to the document: the list is grouped into three <section>s, so
   binding per list would be three attachments, and a row that moves between
   groups after a completion would need re-binding. */
document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-complete]');
  if (!button) { return; }

  const row = button.closest('.list-row');
  const title = row ? row.querySelector('.row-text').textContent.trim() : 'Task';

  button.setAttribute('aria-pressed', 'true');
  button.disabled = true;

  try {
    const result = await apiPost('api/task-complete.php', { id: Number(button.dataset.complete) });

    /* Say WHEN IT IS NEXT DUE, not just "done". The whole point of completing
       something here is that the schedule moved, and a row that silently
       vanishes leaves you wondering whether it moved to next month or next
       year. */
    const next = new Date(result.next_due_on + 'T00:00:00');
    showSnackbar(
      `${title} done · next ${next.toLocaleDateString(undefined, { month: 'long', day: 'numeric' })}`
    );

    /* Collapse the row rather than re-render the page. It has left this
       group — it is not due any more — and the grouping is the server's. */
    if (row) {
      row.classList.add('is-removing');
      setTimeout(() => row.remove(), 300);
    }
  } catch (error) {
    button.setAttribute('aria-pressed', 'false');
    button.disabled = false;
    showSnackbar(explain(error), { isError: true });
  }
});

/* ---- undoing a completion ---------------------------------------------- */

document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-uncomplete]');
  if (!button) { return; }

  try {
    await apiPost('api/task-uncomplete.php', { completion_id: Number(button.dataset.uncomplete) });
    window.location.reload();
  } catch (error) {
    showSnackbar(explain(error), { isError: true });
  }
});

/* ---- the editor -------------------------------------------------------- */

const form = document.getElementById('task-form');

if (form) {
  attachTagField('#task-tags');

  /* Show one rule shape at a time. Both are in the markup so the form still
     works with JS off; this only hides the half that does not apply. */
  const kind = document.getElementById('recur-kind');

  function syncRuleFields() {
    const value = kind.value;
    form.querySelectorAll('[data-rule]').forEach((block) => {
      block.hidden = block.dataset.rule !== value;
    });
  }

  kind.addEventListener('change', syncRuleFields);
  syncRuleFields();

  /* The month picker is checkboxes styled as chips, so it posts correctly with
     no JS. This only mirrors the checked state onto the chip class. */
  const monthPicker = document.getElementById('month-picker');
  if (monthPicker) {
    monthPicker.addEventListener('change', (event) => {
      const box = event.target.closest('input[type="checkbox"]');
      if (box) { box.closest('.chip').classList.toggle('is-on', box.checked); }
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(form);

    const payload = {
      id: Number(form.dataset.id || 0) || undefined,
      title: String(data.get('title') || ''),
      instructions: String(data.get('instructions') || ''),
      recur_kind: String(data.get('recur_kind') || 'interval'),
      interval_count: String(data.get('interval_count') || ''),
      interval_unit: String(data.get('interval_unit') || ''),
      interval_from: String(data.get('interval_from') || 'completion'),
      recur_months: data.getAll('recur_months[]').join(','),
      recur_day: String(data.get('recur_day') || '1'),
      next_due_on: String(data.get('next_due_on') || ''),
      tag_ids: data.getAll('tag_ids[]').map(Number),
    };

    try {
      const task = await apiPost('api/task-save.php', payload);
      const wasNew = !form.dataset.id;
      form.dataset.id = String(task.id);

      if (wasNew) {
        window.location.replace(`task.php?id=${task.id}`);
        return;
      }

      const saved = document.getElementById('task-saved');
      if (saved) {
        saved.textContent = `Saved · next ${task.next_due_on}`;
        setTimeout(() => { saved.textContent = ''; }, 3000);
      }
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

/* ---- detail-screen actions --------------------------------------------- */

const completeButton = document.getElementById('task-complete');
if (completeButton) {
  completeButton.addEventListener('click', async () => {
    try {
      await apiPost('api/task-complete.php', { id: Number(completeButton.dataset.id) });
      window.location.reload();
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

/* The same completion, with a bill attached.
 *
 * A PAID ROUTINE SERVICE IS A COMPLETION THAT COST MONEY. It goes through the
 * same endpoint as the plain button above — one code path moves a due date,
 * and adding a second one for the paid case is how the two would eventually
 * disagree about what "done" means. */
const completeForm = document.getElementById('complete-form');
if (completeForm) {
  completeForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(completeForm);

    try {
      await apiPost('api/task-complete.php', {
        id: Number(completeForm.dataset.id),
        completed_on: String(data.get('completed_on') || ''),
        note: String(data.get('note') || ''),
        vendor_id: Number(data.get('vendor_id') || 0),
        vendor_name: String(data.get('vendor_name') || ''),
        /* Sent as typed. An empty cost is "not recorded", which the server
           stores as NULL — turning it into 0 here would say it was free. */
        cost: String(data.get('cost') || ''),
        rating: String(data.get('rating') || ''),
      });
      window.location.reload();
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

const pauseButton = document.getElementById('task-pause');
if (pauseButton) {
  pauseButton.addEventListener('click', async () => {
    const active = pauseButton.dataset.active === '1';
    try {
      await apiPost('api/task-active.php', {
        id: Number(form.dataset.id),
        active: !active,
      });
      window.location.reload();
    } catch (error) {
      showSnackbar(explain(error), { isError: true });
    }
  });
}

const deleteButton = document.getElementById('task-delete');
if (deleteButton) {
  deleteButton.addEventListener('click', () => {
    /* Pausing is nearly always what someone reaching for delete actually
       wants, and it is the one that keeps the history — so it is named here
       rather than left to be discovered. */
    const ok = window.confirm(
      'This deletes the task and everything logged against it. There is no undo.\n\n'
      + 'If you just want it off the list for now, Pause keeps the history.'
    );
    if (!ok) { return; }

    apiPost('api/task-delete.php', { id: Number(form.dataset.id) })
      .then(() => { window.location.href = 'maintenance.php'; })
      .catch((error) => showSnackbar(explain(error), { isError: true }));
  });
}
