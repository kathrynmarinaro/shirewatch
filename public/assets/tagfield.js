/* Tag input with suggestions from tags you've already used.
 *
 * Free-text tagging drifts — "interiors", "interior", "Interior" become three
 * tags that never match each other. Surfacing existing tags as tappable chips
 * makes reusing one cheaper than retyping it, which is what actually keeps the
 * vocabulary tidy.
 *
 * Used by both the post-upload annotation panel and the detail view. */

import { api } from './api.js';

/** Tag list is shared across every field and refetched only when it changes. */
let tagCache = null;
let inFlight = null;

async function loadTags() {
  if (tagCache) return tagCache;
  if (!inFlight) {
    inFlight = api.tags()
      .then((tags) => { tagCache = tags; return tags; })
      .catch(() => [])                       // suggestions are a nicety, never fatal
      .finally(() => { inFlight = null; });
  }
  return inFlight;
}

/** Call after saving — a new tag should show up as a suggestion next time. */
export function invalidateTags() {
  tagCache = null;
}

/* The parsing below is the fiddly part — exported so it can be unit-tested in
 * Node without a DOM. */

/** Same normalization the server applies, so comparisons agree with storage. */
export function normalizeTag(raw) {
  return String(raw).trim().replace(/\s+/g, ' ').toLowerCase();
}

/** "blue, wea" -> { tags: ['blue'], partial: 'wea' } */
export function parseTags(value) {
  const parts = String(value).split(',');
  const partial = normalizeTag(parts.pop() ?? '');
  const tags = parts.map(normalizeTag).filter(Boolean);
  return { tags, partial };
}

export function serializeTags(tags) {
  return tags.length ? tags.join(', ') + ', ' : '';
}

/**
 * Add or remove `name` in a raw field value. Pure — returns the new value.
 *
 * A half-typed partial is dropped when the tapped chip completes it ("wea" +
 * tap "weaving"), but preserved when it's unrelated, so tapping a chip never
 * silently eats something you were in the middle of typing.
 */
export function toggleTag(value, name) {
  const { tags, partial } = parseTags(value);
  const chosen = new Set(tags);

  if (chosen.has(name)) chosen.delete(name);
  else chosen.add(name);

  const keepPartial = partial && !name.startsWith(partial) ? partial : '';
  return serializeTags([...chosen]) + keepPartial;
}

const normalize = normalizeTag;
const parse = parseTags;

/**
 * Attach suggestions to a tags input. Returns a detach function.
 *
 * The input stays the source of truth — you can still type anything, including
 * brand-new tags. The chips are a shortcut, not a constraint.
 */
export function attachTagField(input, { limit = 18 } = {}) {
  if (!input || input.dataset.tagfield === 'on') return () => {};
  input.dataset.tagfield = 'on';

  const wrap = document.createElement('div');
  wrap.className = 'tag-suggest';
  wrap.hidden = true;
  input.insertAdjacentElement('afterend', wrap);

  let all = [];

  function currentTags() {
    const { tags, partial } = parse(input.value);
    return partial ? [...tags, partial] : tags;
  }

  function toggle(name) {
    input.value = toggleTag(input.value, name);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
    render();
  }

  function render() {
    const { partial } = parse(input.value);
    const chosen = new Set(currentTags());

    const matches = all
      .filter((t) => !partial || t.name.includes(partial))
      .slice(0, limit);

    if (!matches.length) {
      wrap.hidden = true;
      wrap.replaceChildren();
      return;
    }

    wrap.replaceChildren();
    for (const tag of matches) {
      const chip = document.createElement('button');
      chip.type = 'button';                  // never submit the surrounding form
      chip.className = 'chip chip-suggest' + (chosen.has(tag.name) ? ' is-active' : '');
      chip.textContent = tag.name;
      chip.setAttribute('aria-pressed', String(chosen.has(tag.name)));

      // mousedown, not click: click fires after blur, which would close the
      // suggestions before the tap registers.
      chip.addEventListener('mousedown', (e) => { e.preventDefault(); toggle(tag.name); });
      chip.addEventListener('click', (e) => e.preventDefault());
      wrap.append(chip);
    }
    wrap.hidden = false;
  }

  const onInput = () => render();
  input.addEventListener('input', onInput);
  input.addEventListener('focus', onInput);

  loadTags().then((tags) => {
    all = tags.filter((t) => t.name);
    render();
  });

  return function detach() {
    input.removeEventListener('input', onInput);
    input.removeEventListener('focus', onInput);
    wrap.remove();
    delete input.dataset.tagfield;
  };
}
