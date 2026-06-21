import app from 'flarum/forum/app';
import { viewUploadChips } from '../../utils/uploads';
import { viewComposerLinkPreview } from '../../utils/linkPreview';
import { PollComposer } from './PollComposer';

/**
 * Top-of-feed composer for creating a new discussion. Stateless from
 * the composer's perspective — parent GroupFeed owns postText,
 * postFocused, postSubmitting, postError, postUploads, linkPreview state,
 * and the poll object. The same `this[key]` mutation contract used by
 * uploads.js / linkPreview.js helpers means parent has to keep the
 * state for those helpers to work without restructuring them.
 *
 *   attrs = {
 *     actor,
 *     postText, postFocused, postSubmitting, postError,
 *     postUploads, hasUploading,
 *     poll,                          // null | poll object (mutated by PollComposer)
 *     mentionDropdown,               // pre-rendered vnode | null
 *
 *     onFocus(),
 *     onTextChange(value),           // also triggers link-preview schedule + mention input upstream
 *     onPaste(e),                    // image paste detector
 *     onKeydown(e),                  // escape handler for mention dropdown
 *     onUploadFiles(files),
 *     onRemoveUpload(id),
 *     onTogglePoll(),
 *     onPollChange(),                // PollComposer mutated the poll object
 *     onCancel(),
 *     onSubmit(),
 *   }
 */
export default {
  view({ attrs }) {
    const a        = attrs.actor;
    const expanded = attrs.postFocused || attrs.postText.trim().length > 0;
    const t        = (key) => app.translator.trans(`ernestdefoe-social-groups.forum.discussions.${key}`);

    return m('.SGFeed-composer', [
      m('.SGFeed-composerAvatar', [
        a.attribute('avatarUrl')
          ? m('img', { src: a.attribute('avatarUrl'), alt: a.attribute('displayName') })
          : m('span.SGFeed-composerInitial', (a.attribute('displayName') || '?')[0].toUpperCase()),
      ]),
      m('.SGFeed-composerRight', [
        attrs.postError
          ? m('.Alert.Alert--error', { style: 'margin-bottom:8px' }, attrs.postError)
          : null,
        m('textarea.SGFeed-composerTextarea', {
          placeholder: t('feed_placeholder'),
          value:       attrs.postText,
          rows:        expanded ? 3 : 1,
          onfocus:     () => attrs.onFocus(),
          oninput:     (e) => {
            attrs.onTextChange(e);
            // Grow to fit, but cap at the CSS max-height (40vh); past that the
            // textarea scrolls (overflow-y:auto) so a big paste stays editable.
            e.target.style.height = 'auto';
            const cap = Math.round(window.innerHeight * 0.4);
            e.target.style.height = Math.min(e.target.scrollHeight, cap) + 'px';
          },
          onkeydown: (e) => attrs.onKeydown(e),
          onpaste:   (e) => attrs.onPaste(e),
          disabled:  attrs.postSubmitting,
        }),
        viewUploadChips(attrs.postUploads, (id) => attrs.onRemoveUpload(id)),
        attrs.linkPreviewVnode,
        attrs.poll ? PollComposer({ poll: attrs.poll, onChange: () => attrs.onPollChange() }) : null,
        attrs.mentionDropdown,
        expanded ? renderActions(attrs, t) : null,
      ]),
    ]);
  },
};

function renderActions(attrs, t) {
  const submitDisabled = attrs.postSubmitting
    || (!attrs.postText.trim() && !attrs.postUploads.length && !attrs.poll)
    || attrs.hasUploading;

  return m('.SGFeed-composerActions', [
    m('label.SGFeed-composerAttach', {
      title: t('upload_image'),
    }, [
      m('input[type=file]', {
        accept:   'image/*',
        multiple: true,
        style:    'display:none',
        disabled: attrs.postSubmitting,
        onchange: (e) => {
          if (e.target.files.length) attrs.onUploadFiles(Array.from(e.target.files));
          e.target.value = '';
        },
      }),
      m('i.fa-solid.fa-paperclip'),
    ]),
    m('button.SGFeed-pollToggle', {
      class:   attrs.poll ? 'is-active' : '',
      title:   attrs.poll ? t('poll_remove') : t('poll_add'),
      onclick: () => attrs.onTogglePoll(),
    }, m('i.fa-solid.fa-square-poll-vertical')),
    m('button.SGFeed-cancelBtn', {
      onclick: () => attrs.onCancel(),
    }, t('cancel_edit')),
    m('button.SGFeed-postBtn', {
      disabled: submitDisabled,
      onclick:  () => attrs.onSubmit(),
    }, attrs.postSubmitting
        ? m('i.fa-solid.fa-spinner.fa-spin')
        : t('reply_button')),
  ]);
}
