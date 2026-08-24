/* Uploading photos and documents, and draining the queue afterwards.
 *
 * ---------------------------------------------------------------------------
 * TWO CAPTURE SHAPES, ONE CODE PATH.
 * ---------------------------------------------------------------------------
 *
 *   capture: 'single'  the issue flow — snap and log in the moment
 *   capture: 'batch'   the service record flow — multi-select a pile of
 *                      invoices and damage photos
 *
 * The only difference is the `multiple` and `capture` attributes on the input.
 * Everything after the file picker closes is identical, which is what keeps
 * the two from drifting: there is one place that validates, one that posts,
 * one that drains.
 *
 * ---------------------------------------------------------------------------
 * THE DRAIN LOOP IS WHY THE UPLOAD FEELS INSTANT.
 * ---------------------------------------------------------------------------
 *
 * api/upload.php saves originals and returns before any resizing starts. This
 * module then calls api/worker.php in a loop until the queue is empty,
 * updating a progress pill as it goes.
 *
 * IF THE TAB CLOSES MID-DRAIN NOTHING IS LOST. The rows are already in the
 * database as 'pending', and cron/process-queue.php picks them up within a few
 * minutes. That is the entire reason the queue exists rather than resizing
 * inline — so this loop is an optimisation, not a commitment.
 *
 * ---------------------------------------------------------------------------
 * REJECTIONS ARE REPORTED PER FILE, NEVER AS A FAILED BATCH.
 * ---------------------------------------------------------------------------
 *
 * Nine photos of a repair are not worth losing because the tenth was a
 * screenshot. The server returns `created` and `rejected` side by side and
 * this shows both.
 */

import { ApiError } from './api.js';
import { showSnackbar } from './swipe.js';

/* Mirrors media.max_upload_mb in config.example.php. Checked client-side ONLY
   to fail fast on a 60MB video before spending a minute uploading it — the
   server checks again and is the one that decides. */
const MAX_MB = 25;

/** Why a file was refused, said the way a person would say it. */
const REASONS = {
  too_large:                  'too big',
  empty_file:                 'empty',
  incomplete_upload:          'upload was cut off',
  unsupported_type:           'not a photo or PDF',
  heic_unsupported_on_server: 'this server can’t read HEIC — set the camera to “Most Compatible”',
  server_cannot_write:        'the server couldn’t save it',
  save_failed:                'couldn’t be saved',
  upload_failed:              'upload failed',
};

function resolveRoot(root) {
  return typeof root === 'string' ? document.querySelector(root) : root;
}

/**
 * Wire up an upload control.
 *
 *   <label class="btn-secondary" id="add-photo">
 *     Add photo
 *     <input type="file" accept="image/*" hidden>
 *   </label>
 *
 *   attachUpload('#add-photo', {
 *     ownerType: 'update',
 *     ownerId:   41,
 *     capture:   'single',
 *     onDone:    (created) => renderGallery(created),
 *   });
 *
 * @returns {() => void} detach
 */
export function attachUpload(root, opts = {}) {
  const el = resolveRoot(root);
  const noop = () => {};
  if (!el) { return noop; }

  const input = el.matches('input[type="file"]') ? el : el.querySelector('input[type="file"]');
  if (!input) { return noop; }

  const {
    ownerType,
    ownerId,
    capture = 'batch',
    onDone = null,
    onProgress = null,
  } = opts;

  if (!ownerType || !ownerId) {
    throw new TypeError('attachUpload: ownerType and ownerId are required');
  }

  if (capture === 'batch') {
    input.multiple = true;
    input.removeAttribute('capture');
  } else {
    input.multiple = false;
    /* Not capture="camera": that forces the camera and removes the camera roll
       as an option. A photo of the crack you took last week is just as valid as
       one you take now, and the OS sheet offers both. */
    input.removeAttribute('capture');
  }

  async function handle() {
    const files = Array.from(input.files || []);
    /* Reset immediately, so picking the SAME file twice in a row still fires a
       change event. Without this, re-uploading after a failure silently does
       nothing and looks like the button is broken. */
    input.value = '';

    if (files.length === 0) { return; }

    const tooBig = files.filter((f) => f.size > MAX_MB * 1024 * 1024);
    const send = files.filter((f) => f.size <= MAX_MB * 1024 * 1024);

    if (send.length === 0) {
      showSnackbar(`Too big — ${MAX_MB}MB is the limit`, { isError: true });
      return;
    }

    const form = new FormData();
    form.append('owner_type', ownerType);
    form.append('owner_id', String(ownerId));
    send.forEach((file) => form.append('files[]', file));

    setProgress(`Uploading ${send.length}…`);

    let result;
    try {
      result = await postForm('api/upload.php', form);
    } catch (error) {
      setProgress(null);
      showSnackbar(uploadError(error), { isError: true });
      return;
    }

    const rejected = (result.rejected || []).concat(
      tooBig.map((f) => ({ name: f.name, reason: 'too_large' }))
    );
    if (rejected.length > 0) {
      showSnackbar(describeRejections(rejected), { isError: true, ms: 7000 });
    }

    if (typeof onDone === 'function') { onDone(result.created || []); }

    await drain();
  }

  /** Call the worker until the queue is empty. */
  async function drain() {
    /* A hard cap rather than "until remaining is 0". If something is wedged —
       a photo that fails, is retried, fails again — an unbounded loop would
       hammer the server from a phone in someone's pocket. The cron is the
       backstop; this just has to cover the common case. */
    for (let i = 0; i < 40; i++) {
      let status;
      try {
        status = await postForm('api/worker.php', null);
      } catch (error) {
        break;                 // the cron will finish it
      }
      setProgress(status.remaining > 0 ? `Processing ${status.remaining}…` : null);
      if (status.remaining === 0 || status.processed === 0) { break; }
    }
    setProgress(null);
  }

  function setProgress(text) {
    if (typeof onProgress === 'function') { onProgress(text); }
  }

  input.addEventListener('change', handle);
  return () => input.removeEventListener('change', handle);
}

/**
 * POST without api.js, because this sends FormData.
 *
 * api.js sets Content-Type: application/json, which would break a multipart
 * body — the browser has to set that header itself so it can include the
 * boundary. The CSRF header is the same one api.js sends; keep them in step.
 */
async function postForm(url, form) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'X-Requested-With': 'Shirewatch' },
    body: form,
    credentials: 'same-origin',
  });

  let payload = null;
  try { payload = await response.json(); } catch (error) { payload = null; }

  if (!response.ok) {
    throw new ApiError(
      (payload && payload.error) || 'http_' + response.status,
      response.status,
      payload && payload.detail
    );
  }
  return payload || {};
}

function uploadError(error) {
  if (!(error instanceof ApiError)) { return 'Upload failed.'; }
  switch (error.code) {
    case 'payload_too_large':
      return 'That batch was too big for the server. Try fewer at a time.';
    case 'owner_not_found':
      return 'Save this first, then add photos.';
    case 'unauthorized':
      return 'Your session expired. Reload and sign in again.';
    case 'network_unreachable':
      return 'No connection.';
    default:
      return 'Upload failed.';
  }
}

/** "notes.txt wasn't a photo or PDF" — names the file, not just the count. */
function describeRejections(rejected) {
  if (rejected.length === 1) {
    const one = rejected[0];
    return `${one.name} — ${REASONS[one.reason] || 'couldn’t be added'}`;
  }
  return `${rejected.length} files couldn’t be added`;
}
