import app from 'flarum/forum/app';
import humanTime from 'flarum/common/utils/humanTime';
import { REACTIONS, ReactionPicker, ReactionStat } from './reactions';

const PREVIEW_LIMIT = 3;

/**
 * Inline list of the last few comments under a discussion in the feed.
 * Stateless: parent owns `comments` (loadedComments[d.id]),
 * `loading` (commentsLoading[d.id]), `pickerCommentId` (single-active),
 * and the callbacks that mutate them.
 *
 *   attrs = {
 *     comments: Post[] | undefined,
 *     loading:  boolean,
 *     totalCount: number,                  // comments.length, exposed so parent can decide UI separately
 *     pickerCommentId: number | null,
 *     onPickReaction(post, key),           // toggleCommentReaction
 *     onTogglePicker(postId),              // sets pickerCommentId, redraws
 *     onOpenThread(),                      // routes to the full discussion view
 *   }
 *
 * Returns null when the parent decides the section is collapsed — caller
 * gates on `expandedDiscIds.has(d.id)`. We don't gate here so the
 * component contract stays "render the list state I give you".
 */
export function InlineCommentList(attrs) {
  const t = (key, params) =>
    app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

  if (attrs.loading && !attrs.comments) {
    return m('.SGFeed-comments',
      m('.SGFeed-commentsLoading', m('i.fa-solid.fa-spinner.fa-spin'))
    );
  }

  if (!attrs.comments || attrs.comments.length === 0) {
    return m('.SGFeed-comments',
      m('.SGFeed-commentsEmpty', t('comments_empty'))
    );
  }

  const shown  = attrs.comments.slice(-PREVIEW_LIMIT);
  const hidden = Math.max(0, attrs.comments.length - PREVIEW_LIMIT);
  const actor  = app.session.user;

  return m('.SGFeed-comments', [
    hidden > 0
      ? m('button.SGFeed-viewAllBtn', {
          onclick: () => attrs.onOpenThread(),
        }, t('view_all_comments', { count: attrs.comments.length }))
      : null,
    shown.map((post) => renderComment(post, attrs, actor, t)),
  ]);
}

function renderComment(post, attrs, actor, t) {
  const user        = post.user;
  const actorReact  = post.actorReaction || null;
  const pickerOpen  = attrs.pickerCommentId === post.id;
  const stat        = ReactionStat(post.reactions);
  const activeEmoji = actorReact ? REACTIONS.find((r) => r.key === actorReact) : null;

  return m('.SGFeed-comment', { key: post.id }, [
    m('.SGFeed-commentAvatar', [
      user?.avatarUrl
        ? m('img', { src: user.avatarUrl, alt: user.displayName })
        : m('span.SGFeed-commentInitial', (user?.displayName || '?')[0].toUpperCase()),
    ]),

    m('.SGFeed-commentRight', [
      m('.SGFeed-commentBody', [
        m('span.SGFeed-commentAuthor', user?.displayName || ''),
        m('.SGFeed-commentContent', m.trust(post.contentParsed || post.content || '')),
      ]),

      m('.SGFeed-commentFooter', [
        m('span.SGFeed-commentTime', humanTime(new Date(post.createdAt))),

        actor
          ? m('.SGFeed-commentReactWrap', [
              pickerOpen
                ? ReactionPicker({
                    actorReaction: actorReact,
                    wrapperClass:  'SGFeed-commentPicker',
                    onPick:        (key) => attrs.onPickReaction(post, key),
                  })
                : null,
              m('button.SGFeed-commentReactBtn', {
                class:   activeEmoji ? 'is-active' : '',
                title:   activeEmoji
                           ? t('remove_reaction', { emoji: activeEmoji.label })
                           : t('react'),
                onclick: (e) => {
                  e.stopPropagation();
                  if (activeEmoji) {
                    attrs.onPickReaction(post, actorReact);
                  } else {
                    attrs.onTogglePicker(pickerOpen ? null : post.id);
                  }
                },
              }, activeEmoji
                  ? [activeEmoji.emoji, ' ', activeEmoji.label]
                  : [m('i.fa-solid.fa-face-grin-beam'), ' ', t('react')]),
            ])
          : null,

        stat.total > 0
          ? m('span.SGFeed-commentReactStat', [
              stat.topEmojis.map((emoji) => m('span.SGFeed-commentReactEmoji', emoji)),
              ' ',
              stat.total,
            ])
          : null,
      ]),
    ]),
  ]);
}
