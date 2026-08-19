/* The dashboard's forward timeline: paging as you scroll.
 *
 * ---------------------------------------------------------------------------
 * THE FIRST PAGE IS ALREADY ON THE SCREEN.
 * ---------------------------------------------------------------------------
 *
 * public/index.php renders it server-side, and this module only appends what
 * comes after. So the app's front door shows its content immediately rather
 * than appearing empty and filling in, and with no JavaScript you still get a
 * page of the timeline — just no more of it.
 *
 * ---------------------------------------------------------------------------
 * KEYSET PAGING, AND THE CURSOR LIVES IN THE DOM.
 * ---------------------------------------------------------------------------
 *
 * The <ol> carries data-cursor-date and data-cursor-key, written by the server
 * for the first page and rewritten here after each fetch. Keeping it there
 * rather than in a module variable means the server and the client cannot
 * disagree about where the stream got to, and a re-render picks up correctly.
 *
 * Completing a task from the "needs action" list is handled by
 * assets/maintenance.js, which delegates from the document — one handler for
 * both screens rather than two that drift.
 */

import { apiGet, ApiError } from './api.js';
import { showSnackbar } from './swipe.js';

const timeline = document.getElementById('timeline');
const moreWrap = document.getElementById('timeline-more');
const loadButton = document.getElementById('timeline-load');

if (timeline) {
  let loading = false;

  /* The date heading last written, so an appended page does not repeat a
     heading the previous page already printed for the same day. Seeded from
     the server-rendered markup rather than from an assumption about it. */
  let lastDate = (() => {
    const heads = timeline.querySelectorAll('.timeline-head');
    return heads.length > 0 ? heads[heads.length - 1].dataset.date || '' : '';
  })();

  function done() {
    return timeline.dataset.done === '1';
  }

  async function loadMore() {
    if (loading || done()) { return; }
    loading = true;
    if (loadButton) { loadButton.textContent = 'Loading…'; }

    try {
      const page = await apiGet('api/timeline.php', {
        after: timeline.dataset.cursorDate,
        key: timeline.dataset.cursorKey,
      });

      page.rows.forEach(appendRow);

      if (page.cursor) {
        timeline.dataset.cursorDate = page.cursor.date;
        timeline.dataset.cursorKey = page.cursor.key;
      }
      timeline.dataset.done = page.done || page.rows.length === 0 ? '1' : '0';

      if (done() && moreWrap) {
        /* Say the stream ENDED rather than silently removing the control —
           a button that vanishes reads as a failure, and this horizon is a
           config value, not the end of time. */
        moreWrap.textContent = 'That is as far ahead as the app looks.';
      } else if (loadButton) {
        loadButton.textContent = 'Load more';
      }
    } catch (error) {
      if (loadButton) { loadButton.textContent = 'Load more'; }
      showSnackbar(
        error instanceof ApiError && error.code === 'network_unreachable'
          ? 'No connection.'
          : 'Could not load more.',
        { isError: true }
      );
    } finally {
      loading = false;
    }
  }

  function appendRow(row) {
    if (row.on_date !== lastDate) {
      lastDate = row.on_date;
      const head = document.createElement('li');
      head.className = 'timeline-head';
      head.dataset.date = row.on_date;
      head.textContent = formatDate(row.on_date);
      timeline.appendChild(head);
    }

    const item = document.createElement('li');
    item.className = 'timeline-item'
      + (row.kind === 'issue' ? ' is-issue' : '')
      + (row.projected ? ' is-projected' : '');

    const link = document.createElement('a');
    link.className = 'timeline-title';
    link.href = (row.kind === 'issue' ? 'issue.php?id=' : 'task.php?id=') + row.id;
    /* textContent — a title is user-entered and this is where it re-enters the
       DOM after the server escaped it for the first page. */
    link.textContent = row.title;
    item.appendChild(link);

    const sub = document.createElement('span');
    sub.className = 'timeline-sub';
    sub.textContent = (row.kind === 'issue' ? 'Check back' : 'Maintenance')
      + (row.projected ? ' · projected' : '');
    item.appendChild(sub);

    timeline.appendChild(item);
  }

  function formatDate(iso) {
    /* Parsed as local midnight, not UTC: `new Date('2026-09-01')` is UTC and
       renders as the previous day west of Greenwich, which would put every
       heading one day early for the person this app is built for. */
    const date = new Date(iso + 'T00:00:00');
    return date.toLocaleDateString(undefined, {
      weekday: 'long', day: 'numeric', month: 'long',
    });
  }

  if (loadButton) {
    loadButton.addEventListener('click', loadMore);
  }

  /* Auto-load as the end comes into view, with the button still there as the
     manual fallback. IntersectionObserver is checked rather than assumed —
     without it the button alone works fine, which is the whole point of
     rendering one. */
  if (moreWrap && typeof window.IntersectionObserver === 'function') {
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) { loadMore(); }
    }, { rootMargin: '400px' });
    observer.observe(moreWrap);
  }
}
