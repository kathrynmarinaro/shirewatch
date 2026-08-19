/* Full-screen photo viewer.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL.
 * ---------------------------------------------------------------------------
 *
 * The galleries render 96px squares, and the entire premise of this app is
 * comparing a photo of a crack against a photo of the same crack eight months
 * later. That comparison cannot happen at thumbnail size. Tapping has to open
 * something big, and swiping has to move between them without going back to
 * the grid each time.
 *
 * ---------------------------------------------------------------------------
 * IT SERVES THE DETAIL COPY, NOT THE ORIGINAL.
 * ---------------------------------------------------------------------------
 *
 * 1600px on a phone is already more pixels than the screen has. The full
 * originals are kept (CLAUDE.md) but they are 3-12MB each, and paging through
 * a timeline would download tens of megabytes over a phone connection to show
 * something visually identical.
 *
 * The original is one tap away, labelled, for when you actually want it — in
 * front of an adjuster, or to crop out of.
 *
 * ---------------------------------------------------------------------------
 * DEGRADATION.
 * ---------------------------------------------------------------------------
 *
 * With no JS, the gallery's <a> elements are ordinary links straight to the
 * detail image. You lose the swipe and the caption; you do not lose the photo.
 * Nothing here is the only route to anything.
 */

function resolveRoot(root) {
  return typeof root === 'string' ? document.querySelector(root) : root;
}

/* One at a time, module-global — the rule every sheet in this app follows. */
let open = null;

const SWIPE_MIN = 40;      // px before a horizontal drag counts as a page turn

export function closeLightbox() {
  if (open === null) { return; }
  open.el.remove();
  document.body.style.removeProperty('overflow');
  window.removeEventListener('keydown', open.onKey);
  open = null;
}

/**
 * Attach the viewer to a gallery.
 *
 * Delegated to the root, so a gallery re-rendered after an upload needs no
 * re-attach — which matters because that is exactly when new photos appear.
 *
 * Reads everything from the DOM rather than taking a list of photos: the
 * server already rendered them, and a second copy in JS is a second thing to
 * keep in sync.
 *
 *   <ul class="gallery" id="record-photos">
 *     <li class="gallery-item" data-id="8">
 *       <a href="uploads/detail/ab.webp"
 *          data-original="uploads/original/ab.jpg"
 *          data-caption="Before"
 *          data-date="12 March 2026">
 *         <img src="uploads/thumb/ab.webp" alt="">
 *       </a>
 *     </li>
 *   </ul>
 *
 * @returns {() => void} detach
 */
export function attachLightbox(root, opts = {}) {
  const el = resolveRoot(root);
  const noop = () => {};
  if (!el) { return noop; }

  const { itemSelector = '.gallery-item a' } = opts;

  function items() {
    return Array.from(el.querySelectorAll(itemSelector));
  }

  function onClick(event) {
    const link = event.target.closest(itemSelector);
    if (!link || !el.contains(link)) { return; }

    /* Cmd/ctrl-click and middle-click keep their normal meaning — the href is
       a real URL and opening it in a tab is a reasonable thing to want. */
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }

    event.preventDefault();
    show(items().indexOf(link));
  }

  function show(index) {
    const all = items();
    if (index < 0 || index >= all.length) { return; }

    if (open === null) { build(); }
    render(all, index);
  }

  function build() {
    const box = document.createElement('div');
    box.className = 'lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Photo');

    const onKey = (event) => {
      if (event.key === 'Escape') { closeLightbox(); }
      if (event.key === 'ArrowRight') { step(1); }
      if (event.key === 'ArrowLeft') { step(-1); }
    };

    /* Stop the page behind from scrolling under the overlay. Restored by
       closeLightbox(), including on the Escape path. */
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKey);
    document.body.appendChild(box);

    open = { el: box, onKey, index: 0, all: [] };

    box.addEventListener('click', (event) => {
      const act = event.target.dataset ? event.target.dataset.act : null;
      if (act === 'next') { step(1); return; }
      if (act === 'prev') { step(-1); return; }
      if (act === 'close' || event.target === box) { closeLightbox(); }
    });

    /* Horizontal swipe pages; a vertical one is left to the browser so the
       caption can scroll if it is long. The threshold and the axis test match
       swipe.js's, so the two gestures feel like the same app. */
    let start = null;
    box.addEventListener('pointerdown', (event) => {
      start = { x: event.clientX, y: event.clientY };
    });
    box.addEventListener('pointerup', (event) => {
      if (start === null) { return; }
      const dx = event.clientX - start.x;
      const dy = event.clientY - start.y;
      start = null;
      if (Math.abs(dx) > SWIPE_MIN && Math.abs(dx) > Math.abs(dy)) {
        step(dx < 0 ? 1 : -1);
      }
    });
  }

  function step(by) {
    if (open === null) { return; }
    const next = open.index + by;
    if (next < 0 || next >= open.all.length) { return; }
    render(open.all, next);
  }

  function render(all, index) {
    const link = all[index];
    open.all = all;
    open.index = index;

    const caption = link.dataset.caption || '';
    const date = link.dataset.date || '';
    const original = link.dataset.original || '';

    open.el.textContent = '';

    const img = document.createElement('img');
    img.className = 'lightbox-img';
    img.src = link.getAttribute('href');
    img.alt = caption || 'Photo';
    open.el.appendChild(img);

    const bar = document.createElement('div');
    bar.className = 'lightbox-bar';

    if (date || caption) {
      const text = document.createElement('div');
      text.className = 'lightbox-text';
      /* textContent — a caption is user-entered and this is where it re-enters
         the DOM after the server escaped it. */
      if (date) {
        const d = document.createElement('strong');
        d.textContent = date;
        text.appendChild(d);
      }
      if (caption) {
        const c = document.createElement('span');
        c.textContent = caption;
        text.appendChild(c);
      }
      bar.appendChild(text);
    }

    if (original) {
      const full = document.createElement('a');
      full.className = 'lightbox-full';
      full.href = original;
      full.target = '_blank';
      full.rel = 'noopener';
      full.textContent = 'Full resolution';
      bar.appendChild(full);
    }

    const counter = document.createElement('span');
    counter.className = 'lightbox-count';
    counter.textContent = `${index + 1} / ${all.length}`;
    bar.appendChild(counter);

    open.el.appendChild(bar);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'lightbox-close';
    close.dataset.act = 'close';
    close.setAttribute('aria-label', 'Close');
    close.textContent = '×';
    open.el.appendChild(close);

    /* Arrows only where there is somewhere to go. A permanently visible arrow
       that does nothing at the end of the set reads as a broken control. */
    if (index > 0) { open.el.appendChild(arrow('prev', '‹')); }
    if (index < all.length - 1) { open.el.appendChild(arrow('next', '›')); }
  }

  function arrow(act, glyph) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'lightbox-nav is-' + act;
    button.dataset.act = act;
    button.setAttribute('aria-label', act === 'next' ? 'Next photo' : 'Previous photo');
    button.textContent = glyph;
    return button;
  }

  el.addEventListener('click', onClick);
  return () => {
    el.removeEventListener('click', onClick);
    closeLightbox();
  };
}
