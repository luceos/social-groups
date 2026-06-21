import app from 'flarum/forum/app';

/**
 * Lightweight markdown formatting toolbar for the plain-textarea composers
 * used across the feed and thread views. The group composers are intentionally
 * not the full Flarum TextEditor (no SPA composer dock), so this gives members
 * the formatting affordances they expected (bold/italic/link/list/quote/code)
 * without pulling in the whole editor. Content still flows through the same
 * server-side formatter, so the markdown renders identically to a normal post.
 *
 * The toolbar is rendered as a sibling of the textarea inside a `.SGMd-field`
 * wrapper; each button locates its textarea via `closest('.SGMd-field')` so the
 * helper stays decoupled from any single component's DOM (and never reaches
 * across the page to the wrong textarea — see CLAUDE.md §40.3).
 */
const ACTIONS = [
  { key: 'bold',    icon: 'fa-bold',          before: '**',  after: '**',          ph: 'bold text' },
  { key: 'italic',  icon: 'fa-italic',        before: '_',   after: '_',           ph: 'italic text' },
  { key: 'strike',  icon: 'fa-strikethrough', before: '~~',  after: '~~',          ph: 'strikethrough' },
  { key: 'link',    icon: 'fa-link',          before: '[',   after: '](https://)', ph: 'link text' },
  { key: 'quote',   icon: 'fa-quote-right',   before: '> ',  after: '',            ph: 'quote', line: true },
  { key: 'list',    icon: 'fa-list-ul',       before: '- ',  after: '',            ph: 'list item', line: true },
  { key: 'code',    icon: 'fa-code',          before: '`',   after: '`',           ph: 'code' },
];

/**
 * Wraps (or line-prefixes) the textarea's current selection with the action's
 * markdown, writes the result back to the element, and restores a sensible
 * selection so the user can keep typing over the placeholder.
 */
export function applyMarkdown(el, action, onChange) {
  if (!el) return;
  const value = el.value;
  const start = el.selectionStart ?? value.length;
  const end   = el.selectionEnd ?? value.length;
  const selected = value.slice(start, end) || action.ph;

  let insert;
  let selOffset;
  if (action.line) {
    // Prefix every selected line (so multi-line quotes/lists work).
    const block = selected.replace(/^/gm, action.before);
    insert    = block;
    selOffset = action.before.length;
  } else {
    insert    = action.before + selected + action.after;
    selOffset = action.before.length;
  }

  const next = value.slice(0, start) + insert + value.slice(end);
  el.value = next;
  onChange(next, el);

  requestAnimationFrame(() => {
    el.focus();
    const s = start + selOffset;
    el.setSelectionRange(s, s + selected.length);
  });
}

/**
 * Renders the button row.
 *
 *   onChange(nextValue, el)  — called after each insertion; the element's
 *                              `.value` is already updated, so callers usually
 *                              just route it back into component state.
 *   disabled                 — greys the toolbar out while submitting.
 */
export function MarkdownToolbar({ onChange, disabled = false }) {
  return m('.SGMd-toolbar', ACTIONS.map((a) =>
    m('button.SGMd-btn', {
      type:     'button',
      title:    app.translator.trans('ernestdefoe-social-groups.forum.composer.md_' + a.key),
      disabled,
      // Keep the textarea's focus + selection when the button is pressed.
      onmousedown: (e) => e.preventDefault(),
      onclick: (e) => {
        e.preventDefault();
        const field = e.target.closest('.SGMd-field');
        const el    = field && field.querySelector('textarea');
        applyMarkdown(el, a, onChange);
      },
    }, m('i.fa-solid.' + a.icon))
  ));
}
