import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

/**
 * Translated reaction label, resolved lazily so the translator's locale
 * data is guaranteed to be loaded by the time a label is rendered.
 */
function reactionLabel(localeKey) {
  return extractText(app.translator.trans(`ernestdefoe-social-groups.forum.reactions.${localeKey}`));
}

/**
 * Canonical reaction set shared by the discussion-level picker and the
 * inline-comment picker. Keys are persisted on the server; emoji + label
 * are render-time only. The `heart` key maps to the `love` locale key.
 */
export const REACTIONS = [
  { key: 'like',  emoji: '👍', get label() { return reactionLabel('like'); } },
  { key: 'heart', emoji: '❤️', get label() { return reactionLabel('love'); } },
  { key: 'haha',  emoji: '😂', get label() { return reactionLabel('haha'); } },
  { key: 'wow',   emoji: '😮', get label() { return reactionLabel('wow'); } },
  { key: 'sad',   emoji: '😢', get label() { return reactionLabel('sad'); } },
  { key: 'angry', emoji: '😡', get label() { return reactionLabel('angry'); } },
];

/**
 * Render the 6-emoji popover. Stateless: the caller owns whether the
 * popover is open and passes `onPick` to react.
 *
 * `wrapperClass` distinguishes the discussion-level wrap from the
 * comment-level wrap so the document click-handler in GroupFeed can
 * tell which picker the user is interacting with.
 *
 *   attrs = { actorReaction, onPick(key), wrapperClass }
 */
export function ReactionPicker(attrs) {
  return m('.SGFeed-reactionPicker', { class: attrs.wrapperClass || '' },
    REACTIONS.map((r) =>
      m('button.SGFeed-pickerBtn', {
        key:     r.key,
        title:   r.label,
        class:   attrs.actorReaction === r.key ? 'is-active' : '',
        onclick: (e) => {
          e.stopPropagation();
          attrs.onPick(r.key);
        },
      }, [
        m('span.SGFeed-pickerEmoji', r.emoji),
        m('span.SGFeed-pickerLabel', r.label),
      ])
    )
  );
}

/**
 * Render the "[emoji] Like" / "React" button next to the picker.
 * Receives the actor's current reaction (or null) and two callbacks:
 * one for clearing the active reaction, one for opening the picker.
 *
 *   attrs = { actorReaction, onClear(), onOpen() }
 */
export function ReactionButton(attrs) {
  const active = attrs.actorReaction
    ? REACTIONS.find((r) => r.key === attrs.actorReaction)
    : null;

  return m('button.SGFeed-reactBtn', {
    class:   active ? 'SGFeed-reactBtn--active' : '',
    onclick: (e) => {
      e.stopPropagation();
      if (active) attrs.onClear();
      else        attrs.onOpen();
    },
  }, active
      ? [active.emoji, ' ', active.label]
      : [
          m('i.fa-solid.fa-face-grin-beam'),
          ' ',
          app.translator.trans('ernestdefoe-social-groups.forum.discussions.react'),
        ]);
}

/**
 * Compact emoji stack + count used in the stat bar above the action bar.
 *
 *   attrs = { reactions: {key: count}, max: 3 }
 */
export function ReactionStat(reactions, max = 3) {
  const total = Object.values(reactions || {}).reduce((s, c) => s + Number(c), 0);
  if (total <= 0) return { total, view: null };

  const topEmojis = Object.entries(reactions)
    .filter(([, c]) => Number(c) > 0)
    .sort(([, a], [, b]) => Number(b) - Number(a))
    .slice(0, max)
    .map(([key]) => REACTIONS.find((r) => r.key === key)?.emoji || '👍');

  return { total, topEmojis };
}
