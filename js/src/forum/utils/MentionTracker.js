import { listMembers } from './api';
import { MentionDropdown } from '../components/feed/MentionDropdown';

/**
 * @mention state + UI for a single feed/composer. Extracted from
 * `GroupFeed` to keep the component focused on feed rendering instead
 * of carrying five `mention*` properties plus three methods of its own.
 *
 * Owns:
 *   - members cache (lazy-loaded once on first @-trigger)
 *   - active mention context: which textarea, what query, where the
 *     cursor is so a click on the dropdown can splice the displayName
 *     back into the right field
 *
 * The host component supplies a `setText(discId, value)` callback so
 * the tracker can update whichever piece of state it lives next to —
 * top-of-feed composer text vs. per-discussion reply text — without
 * the tracker needing to know that layout.
 *
 *   const tracker = new MentionTracker({
 *     groupId,
 *     setText: (discId, value) => {
 *       if (discId === 'feed') this.postText = value;
 *       else this.replyTexts[discId] = value;
 *     },
 *   });
 *
 *   <textarea oninput={(e) => tracker.onInput('feed', e)} />
 *   { tracker.viewDropdown('feed') }
 *
 * Call tracker.closeIfOutside(eventTarget) from the document click
 * handler to dismiss the dropdown when the user clicks elsewhere.
 */
export default class MentionTracker {
  constructor({ groupId, setText }) {
    this.groupId    = groupId;
    this.setText    = setText;

    this.members         = null;
    this.membersLoading  = false;

    this.query   = null;
    this.discId  = null;
    this._ta     = null;
  }

  loadMembers() {
    if (this.members !== null || this.membersLoading) return;
    this.membersLoading = true;
    listMembers(this.groupId)
      .then((data) => {
        this.members        = data.data || [];
        this.membersLoading = false;
        m.redraw();
      })
      .catch(() => {
        this.members        = [];
        this.membersLoading = false;
      });
  }

  /**
   * oninput hook — detects whether the cursor sits right after an
   * `@word` token and, if so, opens (or updates) the dropdown.
   */
  onInput(discId, e) {
    const ta     = e.target;
    const pos    = ta.selectionStart;
    const before = ta.value.slice(0, pos);
    const match  = before.match(/@([\w-]*)$/);
    if (match) {
      this.query  = match[1];
      this.discId = discId;
      this._ta    = ta;
      this.loadMembers();
    } else if (this.discId === discId) {
      this.query  = null;
      this.discId = null;
    }
  }

  /**
   * Dropdown selection handler — splices `@DisplayName ` into the
   * active textarea over the `@word` the user was typing, then
   * restores the caret to the end of the inserted name.
   */
  select(member) {
    const ta = this._ta;
    if (!ta) return;

    const pos    = ta.selectionStart;
    const text   = ta.value;
    const before = text.slice(0, pos);
    const match  = before.match(/@([\w-]*)$/);
    if (!match) { this.query = null; return; }

    const start    = pos - match[0].length;
    const inserted = '@' + member.displayName + ' ';
    const newText  = text.slice(0, start) + inserted + text.slice(pos);

    this.setText(this.discId, newText);

    const wasDiscId = this.discId;
    this.query  = null;
    this.discId = null;
    m.redraw();

    requestAnimationFrame(() => {
      if (!ta.isConnected) return;
      const newPos = start + inserted.length;
      ta.value = newText;
      ta.focus();
      ta.setSelectionRange(newPos, newPos);
    });

    return wasDiscId;
  }

  /** Render the dropdown vnode (or null) for a given textarea slot. */
  viewDropdown(discId) {
    if (this.discId !== discId) return null;

    return MentionDropdown({
      members:  this.members,
      loading:  this.membersLoading,
      query:    this.query,
      onSelect: (member) => this.select(member),
    });
  }

  /**
   * Document-click handler hook. Closes the dropdown when the click
   * lands outside it. The caller is responsible for `m.redraw()`
   * since it usually batches several dismissals (menus, pickers) in
   * one handler.
   *
   * @returns {boolean} true if state changed, false otherwise
   */
  closeIfOutside(target) {
    if (this.query === null) return false;
    if (target.closest && target.closest('.SGFeed-mentionDropdown')) return false;
    this.query  = null;
    this.discId = null;
    return true;
  }

  /** Close the dropdown e.g. on Escape. */
  close() {
    if (this.query === null) return false;
    this.query  = null;
    this.discId = null;
    return true;
  }
}
