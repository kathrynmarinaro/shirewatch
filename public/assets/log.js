/* The log LIST. Swipe to delete, with an undo that really restores.
 *
 * The filters are server-rendered links (public/log.php) and this module
 * deliberately does not touch them: the state of the screen lives in the URL,
 * so it can be bookmarked and the back button steps through it.
 */

import { apiPost, ApiError } from './api.js';
import { attachSwipeDelete } from './swipe.js';

/* ---------------------------------------------------------------------------
 * WHY SWIPING A LOG ENTRY DOES NOT DELETE IT.
 * ---------------------------------------------------------------------------
 *
 * Grocery swipes a row and it is gone, with five seconds of undo. That is right
 * for a shopping list: the row is worth nothing and you will retype it in two
 * seconds if you were wrong.
 *
 * A log entry carries a timeline and photographs that cannot be retyped, and
 * its delete is not undoable server-side — entry_delete() removes the files. So
 * a swipe here DISMISSES: the entry and its photos survive, it leaves the open
 * list, and undo is a real status change back.
 *
 * Deleting outright is on the detail screen, behind a confirmation, where the
 * consequence can be spelled out.
 */
attachSwipeDelete('#log-list', {
  label: (row) => `Dismissed “${row.querySelector('.row-text').textContent.trim()}”`,
  onDelete: (id) => apiPost('api/entry-status.php', { id: Number(id), status: 'dismissed' }),
  onUndo:   (id) => apiPost('api/entry-status.php', { id: Number(id), status: 'watching' }),
});
