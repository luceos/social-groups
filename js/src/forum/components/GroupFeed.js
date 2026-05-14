import { apiBase } from '../utils/api';
import { pastedImages, handleFiles, removeUpload, revokeAll, viewUploadChips } from '../utils/uploads';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/utils/humanTime';

export default class GroupFeed extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.discussions  = null;
    this.loading      = true;
    this.page         = 1;
    this.pages        = 1;
    this.total        = 0;
    this.deleting     = null;
    this.openMenuId   = null;

    // Composer state
    this.postText       = '';
    this.postSubmitting = false;
    this.postError      = null;
    this.postFocused    = false;
    this.postUploads    = [];

    // Per-post comment reply state: { [discussionId]: text }
    this.replyTexts     = {};
    this.replySubmitting = {};

    this.pickerDiscId = null;
    this._pickerTimer = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();

    this._closeMenu = (e) => {
      if (this.openMenuId !== null && !e.target.closest('.SGFeed-postMenu')) {
        this.openMenuId = null;
        m.redraw();
      }
    };
    document.addEventListener('click', this._closeMenu);
  }

  onupdate(vnode) {
    if (vnode.attrs.groupId !== this.attrs.groupId) {
      this.discussions = null;
      this.loading     = true;
      this.page        = 1;
      this.load();
    }
  }

  onremove() {
    document.removeEventListener('click', this._closeMenu);
    revokeAll(this.postUploads);
    clearTimeout(this._pickerTimer);
  }

  load(page = 1) {
    const groupId = this.attrs.groupId;
    this.loading  = true;
    this.page     = page;

    fetch(`${apiBase()}/sg-discussions/${groupId}?page=${page}`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.json())
      .then((data) => {
        this.discussions = data.data || [];
        this.total       = data.total || 0;
        this.pages       = data.pages || 1;
        this.loading     = false;
        m.redraw();
      })
      .catch(() => {
        this.discussions = [];
        this.loading     = false;
        m.redraw();
      });
  }

  static REACTIONS = [
    { key: 'like',  emoji: '👍', label: 'Like' },
    { key: 'heart', emoji: '❤️', label: 'Love' },
    { key: 'haha',  emoji: '😂', label: 'Haha' },
    { key: 'wow',   emoji: '😮', label: 'Wow' },
    { key: 'sad',   emoji: '😢', label: 'Sad' },
    { key: 'angry', emoji: '😡', label: 'Angry' },
  ];

  // ── Reaction picker ───────────────────────────────────────────────────────

  openPicker(discId) {
    clearTimeout(this._pickerTimer);
    this._pickerTimer = setTimeout(() => { this.pickerDiscId = discId; m.redraw(); }, 500);
  }

  closePicker() {
    clearTimeout(this._pickerTimer);
    this._pickerTimer = setTimeout(() => { this.pickerDiscId = null; m.redraw(); }, 300);
  }

  keepPicker() {
    clearTimeout(this._pickerTimer);
  }

  toggleReaction(d, reactionKey) {
    if (!app.session.user || !d.firstPost) return;
    const fp = d.firstPost;

    const prevReaction  = fp.actorReaction;
    const prevReactions = { ...(fp.reactions || {}) };
    const nextReaction  = prevReaction === reactionKey ? null : reactionKey;

    fp.actorReaction = nextReaction;
    const updated = { ...prevReactions };
    if (prevReaction) { updated[prevReaction] = Math.max(0, (updated[prevReaction] || 0) - 1); if (!updated[prevReaction]) delete updated[prevReaction]; }
    if (nextReaction) { updated[nextReaction] = (updated[nextReaction] || 0) + 1; }
    fp.reactions      = updated;
    this.pickerDiscId = null;
    m.redraw();

    const method = nextReaction ? 'POST' : 'DELETE';
    fetch(`${apiBase()}/sg-posts/${fp.id}/react`, {
      method,
      credentials: 'same-origin',
      headers: {
        ...(nextReaction ? { 'Content-Type': 'application/json' } : {}),
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: nextReaction ? JSON.stringify({ reaction: nextReaction }) : undefined,
    })
      .then((r) => r.json())
      .then((data) => {
        fp.reactions     = data.reactions || {};
        fp.actorReaction = data.actorReaction || null;
        m.redraw();
      })
      .catch(() => {
        fp.reactions     = prevReactions;
        fp.actorReaction = prevReaction;
        m.redraw();
      });
  }

  // ── Create post (discussion + first post) ────────────────────────────────

  submitPost() {
    const content = this.postText.trim();
    if (!content || this.postSubmitting) return;

    this.postSubmitting = true;
    this.postError      = null;

    fetch(`${apiBase()}/sg-discussions`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ groupId: this.attrs.groupId, content }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((d) => {
        this.discussions    = [d, ...(this.discussions || [])];
        this.total++;
        this.postText       = '';
        this.postFocused    = false;
        this.postSubmitting = false;
        revokeAll(this.postUploads);
        this.postUploads    = [];
        m.redraw();
      })
      .catch((err) => {
        this.postError      = err.message;
        this.postSubmitting = false;
        m.redraw();
      });
  }

  // ── Add a reply comment to an existing discussion ────────────────────────

  submitReply(d) {
    const content = (this.replyTexts[d.id] || '').trim();
    if (!content || this.replySubmitting[d.id]) return;

    this.replySubmitting[d.id] = true;

    fetch(`${apiBase()}/sg-posts`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ discussionId: d.id, content }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then(() => {
        d.commentCount = (d.commentCount || 0) + 1;
        this.replyTexts[d.id]     = '';
        this.replySubmitting[d.id] = false;
        m.redraw();
      })
      .catch(() => {
        this.replySubmitting[d.id] = false;
        m.redraw();
      });
  }

  // ── Delete discussion ────────────────────────────────────────────────────

  deleteDiscussion(d) {
    if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete_confirm'))) return;
    this.deleting   = d.id;
    this.openMenuId = null;

    fetch(`${apiBase()}/sg-discussions/${d.id}`, {
      method:      'DELETE',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    }).then(() => {
      this.discussions = this.discussions.filter((x) => x.id !== d.id);
      this.total       = Math.max(0, this.total - 1);
      this.deleting    = null;
      m.redraw();
    }).catch(() => {
      this.deleting = null;
      m.redraw();
    });
  }

  openThread(d) {
    m.route.set(app.route('ernestdefoe-social-groups.discussion', {
      slug:         this.attrs.groupSlug,
      discussionId: d.id,
    }));
  }

  // ── Views ─────────────────────────────────────────────────────────────────

  view() {
    const { isMember } = this.attrs;
    const actor        = app.session.user;

    return m('.SGFeed', [
      // Composer
      actor && isMember ? this.viewComposer(actor) : null,

      // Feed
      this.loading
        ? m('.SGFeed-loading', m(LoadingIndicator, { display: 'block' }))
        : !this.discussions || this.discussions.length === 0
        ? m('.SGFeed-empty', [
            m('i.fas.fa-stream'),
            m('p', app.translator.trans('ernestdefoe-social-groups.forum.discussions.empty')),
          ])
        : [
            m('.SGFeed-list',
              this.discussions.map((d) => this.viewPostCard(d))
            ),
            this.pages > 1
              ? m('.SGFeed-pagination', [
                  m(Button, {
                    class:    'Button',
                    disabled: this.page <= 1,
                    onclick:  () => this.load(this.page - 1),
                    'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.discussions.prev_page'),
                  }, m('i.fas.fa-chevron-left')),
                  m('span.SGFeed-pageInfo', `${this.page} / ${this.pages}`),
                  m(Button, {
                    class:    'Button',
                    disabled: this.page >= this.pages,
                    onclick:  () => this.load(this.page + 1),
                    'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.discussions.next_page'),
                  }, m('i.fas.fa-chevron-right')),
                ])
              : null,
          ],
    ]);
  }

  viewComposer(actor) {
    const expanded = this.postFocused || this.postText.trim().length > 0;

    return m('.SGFeed-composer', [
      m('.SGFeed-composerAvatar', [
        actor.attribute('avatarUrl')
          ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
          : m('span.SGFeed-composerInitial', (actor.attribute('displayName') || '?')[0].toUpperCase()),
      ]),
      m('.SGFeed-composerRight', [
        this.postError ? m('.Alert.Alert--error', { style: 'margin-bottom:8px' }, this.postError) : null,
        m('textarea.SGFeed-composerTextarea', {
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.feed_placeholder'),
          value:       this.postText,
          rows:        expanded ? 3 : 1,
          onfocus:     () => { this.postFocused = true; m.redraw(); },
          oninput:     (e) => {
            this.postText = e.target.value;
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
          },
          onpaste: (e) => {
            const imgs = pastedImages(e);
            if (imgs.length) { e.preventDefault(); handleFiles(this, imgs, 'postUploads', 'postText'); }
          },
          disabled: this.postSubmitting,
        }),
        viewUploadChips(this.postUploads, (id) => removeUpload(this, id, 'postUploads', 'postText')),
        expanded
          ? m('.SGFeed-composerActions', [
              m('label.SGFeed-composerAttach', {
                title: app.translator.trans('ernestdefoe-social-groups.forum.discussions.upload_image'),
              }, [
                m('input[type=file]', {
                  accept:   'image/*',
                  multiple: true,
                  style:    'display:none',
                  disabled: this.postSubmitting,
                  onchange: (e) => {
                    if (e.target.files.length) handleFiles(this, Array.from(e.target.files), 'postUploads', 'postText');
                    e.target.value = '';
                  },
                }),
                m('i.fas.fa-paperclip'),
              ]),
              m('button.SGFeed-cancelBtn', {
                onclick: () => {
                  revokeAll(this.postUploads);
                  this.postUploads = [];
                  this.postText    = '';
                  this.postFocused = false;
                  m.redraw();
                },
              }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.cancel_edit')),
              m('button.SGFeed-postBtn', {
                disabled: this.postSubmitting || (!this.postText.trim() && !this.postUploads.length) || this.postUploads.some((u) => u.uploading),
                onclick:  () => this.submitPost(),
              }, this.postSubmitting
                  ? m('i.fas.fa-spinner.fa-spin')
                  : app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_button')),
            ])
          : null,
      ]),
    ]);
  }

  viewPostCard(d) {
    const fp         = d.firstPost;
    const isDeleting = this.deleting === d.id;
    const menuOpen   = this.openMenuId === d.id;
    const actor      = app.session.user;
    const replyText  = this.replyTexts[d.id] || '';
    const replyBusy  = !!this.replySubmitting[d.id];

    // Use firstPost user if available, fall back to discussion user
    const postUser = fp?.user || d.user;
    const postTime = fp?.createdAt || d.createdAt;

    return m('.SGFeed-post', { key: d.id, class: isDeleting ? 'is-deleting' : '' }, [

      // Header: avatar + name/time + menu
      m('.SGFeed-postHeader', [
        m('.SGFeed-postAvatar', [
          postUser?.avatarUrl
            ? m('img', { src: postUser.avatarUrl, alt: postUser.displayName })
            : m('span.SGFeed-postInitial', (postUser?.displayName || '?')[0].toUpperCase()),
        ]),
        m('.SGFeed-postMeta', [
          m('span.SGFeed-postAuthor', postUser?.displayName || ''),
          m('span.SGFeed-postTime', { title: postTime }, humanTime(new Date(postTime))),
        ]),
        d.canDelete
          ? m('.SGFeed-postMenu', [
              m('button.SGFeed-postMenuBtn', {
                onclick: (e) => {
                  e.stopPropagation();
                  this.openMenuId = menuOpen ? null : d.id;
                  m.redraw();
                },
              }, m('i.fas.fa-ellipsis-h')),
              menuOpen
                ? m('.SGFeed-postDropdown', [
                    m('button.SGFeed-dropdownItem.SGFeed-dropdownItem--danger', {
                      onclick: () => this.deleteDiscussion(d),
                    }, [m('i.fas.fa-trash'), ' ',
                        app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete')]),
                  ])
                : null,
            ])
          : null,
      ]),

      // Post body content
      fp
        ? m('.SGFeed-postContent', m.trust(fp.contentParsed))
        : m('.SGFeed-postContent', m('.SGFeed-noContent', d.title)),

      // Reaction count + comment count stat bar
      (() => {
        const reactions  = fp?.reactions || {};
        const totalReact = Object.values(reactions).reduce((s, c) => s + Number(c), 0);
        const hasComments = d.commentCount > 1;
        if (!totalReact && !hasComments) return null;

        const topEmojis = Object.entries(reactions)
          .filter(([, c]) => Number(c) > 0)
          .sort(([, a], [, b]) => Number(b) - Number(a))
          .slice(0, 3)
          .map(([key]) => GroupFeed.REACTIONS.find((r) => r.key === key)?.emoji || '👍');

        return m('.SGFeed-postStatBar', [
          totalReact > 0
            ? m('span.SGFeed-statLikes', [
                topEmojis.map((emoji) => m('span.SGFeed-reactionEmoji', emoji)),
                ' ', totalReact,
              ])
            : null,
          totalReact > 0 && hasComments ? m('span.SGFeed-statDot', '·') : null,
          hasComments
            ? m('button.SGFeed-statComments', { onclick: () => this.openThread(d) },
                app.translator.trans('ernestdefoe-social-groups.forum.discussions.comments_count', { count: d.commentCount - 1 }))
            : null,
        ]);
      })(),

      // Reaction | Comment action bar
      m('.SGFeed-postActionBar', [
        actor && fp
          ? m('.SGFeed-reactWrap', {
              onmouseenter: () => this.openPicker(d.id),
              onmouseleave: () => this.closePicker(),
            }, [
              this.pickerDiscId === d.id
                ? m('.SGFeed-reactionPicker', {
                    onmouseenter: () => this.keepPicker(),
                    onmouseleave: () => this.closePicker(),
                  }, GroupFeed.REACTIONS.map((r) =>
                    m('button.SGFeed-pickerBtn', {
                      key:     r.key,
                      title:   r.label,
                      class:   fp.actorReaction === r.key ? 'is-active' : '',
                      onclick: () => this.toggleReaction(d, r.key),
                    }, [m('span.SGFeed-pickerEmoji', r.emoji), m('span.SGFeed-pickerLabel', r.label)])
                  ))
                : null,
              (() => {
                const active = fp.actorReaction
                  ? GroupFeed.REACTIONS.find((r) => r.key === fp.actorReaction)
                  : null;
                return m('button.SGFeed-likeBtn', {
                  class:   active ? 'SGFeed-likeBtn--liked' : '',
                  onclick: () => this.toggleReaction(d, fp.actorReaction || 'like'),
                }, active
                    ? [active.emoji, ' ', active.label]
                    : [m('i.fas.fa-thumbs-up'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.discussions.like')]);
              })(),
            ])
          : null,
        m('button.SGFeed-commentBtn', {
          onclick: () => this.openThread(d),
        }, [m('i.fas.fa-comment'), ' ',
            app.translator.trans('ernestdefoe-social-groups.forum.discussions.view_comments')]),
      ]),

      // Inline reply composer (for quick replies; posts to the discussion)
      actor && this.attrs.isMember && !d.isLocked
        ? m('.SGFeed-replyRow', [
            m('.SGFeed-replyAvatar', [
              actor.attribute('avatarUrl')
                ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
                : m('span', (actor.attribute('displayName') || '?')[0].toUpperCase()),
            ]),
            m('.SGFeed-replyInputWrap', [
              m('textarea.SGFeed-replyInput', {
                placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_placeholder'),
                value:       replyText,
                rows:        1,
                disabled:    replyBusy,
                oninput:     (e) => {
                  this.replyTexts[d.id] = e.target.value;
                  e.target.style.height = 'auto';
                  e.target.style.height = e.target.scrollHeight + 'px';
                },
                onkeydown: (e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.submitReply(d);
                  }
                },
              }),
              m('button.SGFeed-replySendBtn', {
                disabled: replyBusy || !replyText.trim(),
                onclick:  () => this.submitReply(d),
                title:    'Post comment',
              }, replyBusy
                  ? m('i.fas.fa-spinner.fa-spin')
                  : m('i.fas.fa-paper-plane')),
            ]),
          ])
        : null,
    ]);
  }
}
