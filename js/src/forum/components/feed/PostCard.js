import app from 'flarum/forum/app';
import humanTime from 'flarum/common/utils/humanTime';
import { viewPostLinkPreview } from '../../utils/linkPreview';
import { MarkdownToolbar } from '../../utils/markdownToolbar';
import { ReactionPicker, ReactionButton, ReactionStat } from './reactions';
import ShareDiscussionModal from '../ShareDiscussionModal';

/**
 * Single discussion row in the feed. Stateless from PostCard's
 * perspective — every piece of UI state (menu open, picker open, reply
 * text, expanded-comments flag) lives on GroupFeed because most of them
 * are single-active across the whole list (only one menu, one picker,
 * one mention dropdown at a time).
 *
 *   attrs = {
 *     discussion: SGDiscussion,
 *     groupId:    number,
 *     groupSlug:  string,
 *     isMember:   boolean,
 *
 *     // Single-active UI state slices
 *     menuOpen:        boolean,
 *     pickerOpen:      boolean,
 *     commentsExpanded: boolean,
 *     deleting:        boolean,
 *     replyText:       string,
 *     replyBusy:       boolean,
 *
 *     // Callbacks
 *     onMenuToggle(),
 *     onTogglePicker(),
 *     onReact(key),
 *     onClearReaction(),
 *     onToggleComments(),
 *     onPin(),
 *     onDelete(),
 *     onVotePoll(optionId),
 *     onReplyChange(text),
 *     onReplyInput(e),                // for mention detection
 *     onReplyKeydown(e),
 *     onReplySubmit(),
 *     mentionDropdown,                 // pre-rendered vnode | null
 *     inlineComments,                  // pre-rendered vnode | null
 *   }
 */
export default {
  view({ attrs }) {
    const d  = attrs.discussion;
    const fp = d.firstPost;
    const t  = (key, params) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`, params);

    return m('.SGFeed-post', {
      key:   d.id,
      class: attrs.deleting ? 'is-deleting' : '',
    }, [
      renderHeader(d, fp, attrs, t),
      attrs.editing ? renderEditForm(d, fp, attrs, t) : renderContent(d, fp),
      !attrs.editing && fp ? viewPostLinkPreview(fp) : null,
      renderSharedFrom(d),
      d.poll ? renderPoll(d, attrs, t) : null,
      renderStatBar(d, fp, attrs, t),
      renderActionBar(d, fp, attrs, t),
      attrs.inlineComments,
      renderReplyRow(d, attrs, t),
    ]);
  },
};

function renderHeader(d, fp, attrs, t) {
  const actor    = app.session.user;
  const postUser = fp?.user || d.user;
  const postTime = fp?.createdAt || d.createdAt;

  return m('.SGFeed-postHeader', [
    m('.SGFeed-postAvatar', [
      postUser?.avatarUrl
        ? m('img', { src: postUser.avatarUrl, alt: postUser.displayName })
        : m('span.SGFeed-postInitial', (postUser?.displayName || '?')[0].toUpperCase()),
    ]),
    m('.SGFeed-postMeta', [
      m('span.SGFeed-postAuthor', postUser?.displayName || ''),
      m('span.SGFeed-postTime', { title: postTime }, humanTime(new Date(postTime))),
      d.isPinned
        ? m('span.SGFeed-pinnedBadge', [m('i.fa-solid.fa-thumbtack'), ' ', t('pinned')])
        : null,
    ]),
    d.canDelete || d.canPin || (fp && fp.canEdit) || (actor && d.canShare)
      ? renderMenu(d, attrs, t)
      : null,
  ]);
}

function renderMenu(d, attrs, t) {
  const actor = app.session.user;
  const fp    = d.firstPost;

  return m('.SGFeed-postMenu', [
    m('button.SGFeed-postMenuBtn', {
      onclick: (e) => { e.stopPropagation(); attrs.onMenuToggle(); },
    }, m('i.fa-solid.fa-ellipsis')),
    attrs.menuOpen
      ? m('.SGFeed-postDropdown', [
          fp && fp.canEdit
            ? m('button.SGFeed-dropdownItem', {
                onclick: () => { attrs.onMenuToggle(); attrs.onStartEdit(); },
              }, [m('i.fa-solid.fa-pen'), ' ', t('edit')])
            : null,
          actor && d.canShare
            ? m('button.SGFeed-dropdownItem', {
                onclick: () => {
                  attrs.onMenuToggle();
                  app.modal.show(ShareDiscussionModal, {
                    discussionId:   d.id,
                    currentGroupId: attrs.groupId,
                  });
                },
              }, [m('i.fa-solid.fa-share'), ' Share post'])
            : null,
          d.canPin
            ? m('button.SGFeed-dropdownItem', {
                onclick: () => attrs.onPin(),
              }, d.isPinned
                  ? [m('i.fa-solid.fa-thumbtack'), ' Unpin post']
                  : [m('i.fa-solid.fa-thumbtack'), ' Pin post'])
            : null,
          d.canDelete
            ? m('button.SGFeed-dropdownItem.SGFeed-dropdownItem--danger', {
                onclick: () => attrs.onDelete(),
              }, [m('i.fa-solid.fa-trash'), ' ', t('delete')])
            : null,
        ])
      : null,
  ]);
}

function renderContent(d, fp) {
  return fp
    ? m('.SGFeed-postContent', m.trust(fp.contentParsed))
    : m('.SGFeed-postContent', m('.SGFeed-noContent', d.title));
}

function renderEditForm(d, fp, attrs, t) {
  return m('.SGFeed-postEdit', [
    attrs.editError
      ? m('.Alert.Alert--error', { style: 'margin-bottom:8px;font-size:.85em' }, attrs.editError)
      : null,
    m('.SGMd-field', [
      MarkdownToolbar({
        onChange: (next) => attrs.onEditChange(next),
        disabled: attrs.editBusy,
      }),
      m('textarea.FormControl.SGFeed-editTextarea', {
        value:    attrs.editText,
        rows:     4,
        disabled: attrs.editBusy,
        oninput:  (e) => attrs.onEditChange(e.target.value),
        onkeydown: (e) => {
          if (e.key === 'Escape') { e.preventDefault(); attrs.onEditCancel(); }
        },
      }),
    ]),
    m('.SGFeed-editActions', [
      m('button.Button.Button--primary.SGFeed-postBtn', {
        disabled: attrs.editBusy || !attrs.editText.trim(),
        onclick:  () => attrs.onEditSave(),
      }, attrs.editBusy
          ? m('i.fa-solid.fa-spinner.fa-spin')
          : t('save_edit')),
      m('button.Button.SGFeed-cancelBtn', {
        disabled: attrs.editBusy,
        onclick:  () => attrs.onEditCancel(),
      }, t('cancel_edit')),
    ]),
  ]);
}

function renderSharedFrom(d) {
  if (!d.sharedFrom) return null;
  const sf = d.sharedFrom;

  return m('a.SGFeed-sharedCard', {
    href:    `/groups/${sf.groupSlug}/d/${sf.discussionId}`,
    onclick: (e) => { e.preventDefault(); m.route.set(`/groups/${sf.groupSlug}/d/${sf.discussionId}`); },
  }, [
    m('.SGFeed-sharedCard-header', [
      sf.user?.avatarUrl
        ? m('img.SGFeed-sharedCard-avatar', { src: sf.user.avatarUrl, alt: '' })
        : m('span.SGFeed-sharedCard-initial', (sf.user?.displayName || '?')[0].toUpperCase()),
      m('span.SGFeed-sharedCard-author', sf.user?.displayName || ''),
      m('span.SGFeed-sharedCard-group', [m('i.fa-solid.fa-users'), ' ', sf.groupName]),
    ]),
    m('.SGFeed-sharedCard-title', sf.title),
    sf.snippet ? m('.SGFeed-sharedCard-snippet', sf.snippet) : null,
  ]);
}

function renderPoll(d, attrs, t) {
  const poll    = d.poll;
  const actor   = app.session.user;
  const ended   = poll.endsAt && new Date(poll.endsAt) < new Date();
  const canVote = actor && !ended;

  return m('.SGFeed-poll', [
    m('.SGFeed-poll-question', [m('i.fa-solid.fa-square-poll-vertical'), ' ', poll.question]),
    m('.SGFeed-poll-options',
      poll.options.map((opt) => {
        const voted = poll.actorVotedOptionIds.includes(opt.id);
        const pct   = poll.totalVotes > 0 ? Math.round((opt.voteCount / poll.totalVotes) * 100) : 0;
        return m('button.SGFeed-poll-option', {
          key:      opt.id,
          class:    voted ? 'is-voted' : '',
          disabled: !canVote,
          onclick:  () => canVote && attrs.onVotePoll(opt.id),
        }, [
          m('.SGFeed-poll-optBar', { style: `width:${pct}%` }),
          m('span.SGFeed-poll-optText', opt.text),
          m('span.SGFeed-poll-optPct', `${pct}%`),
          voted ? m('i.fa-solid.fa-check.SGFeed-poll-check') : null,
        ]);
      })
    ),
    m('.SGFeed-poll-footer', [
      m('span', t('poll_votes', { count: poll.totalVotes })),
      ended ? m('span.SGFeed-poll-ended', ' · ' + t('poll_ended')) : null,
    ]),
  ]);
}

function renderStatBar(d, fp, attrs, t) {
  const stat        = ReactionStat(fp?.reactions);
  const hasComments = d.commentCount > 1;
  if (!stat.total && !hasComments) return null;

  return m('.SGFeed-postStatBar', [
    stat.total > 0
      ? m('span.SGFeed-statLikes', [
          stat.topEmojis.map((emoji) => m('span.SGFeed-reactionEmoji', emoji)),
          ' ', stat.total,
        ])
      : null,
    stat.total > 0 && hasComments ? m('span.SGFeed-statDot', '·') : null,
    hasComments
      ? m('button.SGFeed-statComments', { onclick: () => attrs.onToggleComments() },
          t('comments_count', { count: d.commentCount - 1 }))
      : null,
  ]);
}

function renderActionBar(d, fp, attrs, t) {
  const actor = app.session.user;

  return m('.SGFeed-postActionBar', [
    actor && fp
      ? m('.SGFeed-reactWrap', [
          attrs.pickerOpen
            ? ReactionPicker({
                actorReaction: fp.actorReaction,
                onPick:        (key) => attrs.onReact(key),
              })
            : null,
          ReactionButton({
            actorReaction: fp.actorReaction,
            onClear:       () => attrs.onClearReaction(),
            onOpen:        () => attrs.onTogglePicker(),
          }),
        ])
      : null,
    m('button.SGFeed-commentBtn', {
      class:   attrs.commentsExpanded ? 'is-active' : '',
      onclick: () => attrs.onToggleComments(),
    }, [
      m('i.fa-solid.fa-comment'), ' ',
      attrs.commentsExpanded ? t('hide_comments') : t('view_comments'),
    ]),
  ]);
}

function renderReplyRow(d, attrs, t) {
  const actor = app.session.user;
  if (!actor || d.isLocked) return null;
  if (!attrs.isMember && !d.canReply) return null;

  return m('.SGFeed-replyRow', [
    m('.SGFeed-replyAvatar', [
      actor.attribute('avatarUrl')
        ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
        : m('span', (actor.attribute('displayName') || '?')[0].toUpperCase()),
    ]),
    m('.SGFeed-replyInputWrap', [
      attrs.mentionDropdown,
      m('textarea.SGFeed-replyInput', {
        placeholder: t('reply_placeholder'),
        value:       attrs.replyText,
        rows:        1,
        disabled:    attrs.replyBusy,
        oninput:     (e) => {
          attrs.onReplyChange(e.target.value);
          e.target.style.height = 'auto';
          e.target.style.height = e.target.scrollHeight + 'px';
          attrs.onReplyInput(e);
        },
        onkeydown: (e) => attrs.onReplyKeydown(e),
      }),
      m('button.SGFeed-replySendBtn', {
        disabled: attrs.replyBusy || !attrs.replyText.trim(),
        onclick:  () => attrs.onReplySubmit(),
        title:    t('post_comment'),
      }, attrs.replyBusy
          ? m('i.fa-solid.fa-spinner.fa-spin')
          : m('i.fa-solid.fa-paper-plane')),
    ]),
  ]);
}
