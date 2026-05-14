import { apiBase } from '../utils/api';
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

    // Per-post comment reply state: { [discussionId]: text }
    this.replyTexts     = {};
    this.replySubmitting = {};
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

  // ── Like toggle ──────────────────────────────────────────────────────────

  toggleLike(d) {
    if (!app.session.user || !d.firstPost) return;
    const fp       = d.firstPost;
    const wasLiked = fp.isLiked;

    fp.isLiked   = !wasLiked;
    fp.likeCount = Math.max(0, (fp.likeCount || 0) + (wasLiked ? -1 : 1));
    m.redraw();

    fetch(`${apiBase()}/sg-posts/${fp.id}/like`, {
      method:      wasLiked ? 'DELETE' : 'POST',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.json())
      .then((data) => {
        fp.likeCount = data.likeCount;
        fp.isLiked   = data.isLiked;
        m.redraw();
      })
      .catch(() => {
        fp.isLiked   = wasLiked;
        fp.likeCount = Math.max(0, (fp.likeCount || 0) + (wasLiked ? 1 : -1));
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
          disabled: this.postSubmitting,
        }),
        expanded
          ? m('.SGFeed-composerActions', [
              m('button.SGFeed-cancelBtn', {
                onclick: () => { this.postText = ''; this.postFocused = false; m.redraw(); },
              }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.cancel_edit')),
              m('button.SGFeed-postBtn', {
                disabled: this.postSubmitting || !this.postText.trim(),
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

      // Like count + comment count stat bar
      (fp?.likeCount > 0 || d.commentCount > 0)
        ? m('.SGFeed-postStatBar', [
            fp?.likeCount > 0
              ? m('span.SGFeed-statLikes', [m('span.SGFeed-likeIcon', '👍'), ' ', fp.likeCount])
              : null,
            fp?.likeCount > 0 && d.commentCount > 0 ? m('span.SGFeed-statDot', '·') : null,
            d.commentCount > 1
              ? m('button.SGFeed-statComments', { onclick: () => this.openThread(d) },
                  app.translator.trans('ernestdefoe-social-groups.forum.discussions.comments_count', { count: d.commentCount - 1 }))
              : null,
          ])
        : null,

      // Like | Comment | View action bar
      m('.SGFeed-postActionBar', [
        actor && fp
          ? m('button.SGFeed-likeBtn', {
              class:   fp.isLiked ? 'SGFeed-likeBtn--liked' : '',
              onclick: () => this.toggleLike(d),
            }, [m('i.fas.fa-thumbs-up'), ' ',
                app.translator.trans('ernestdefoe-social-groups.forum.discussions.like')])
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
