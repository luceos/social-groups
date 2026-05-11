import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/utils/humanTime';

export default class GroupDiscussionThread extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.discussion  = null;
    this.posts       = [];
    this.loading     = true;
    this.error       = null;
    this.replyText   = '';
    this.submitting  = false;
    this.replyError  = null;
    this.editingId   = null;
    this.editText    = '';
    this.deletingId  = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();
  }

  onupdate(vnode) {
    if (vnode.attrs.discussionId !== this.attrs.discussionId) {
      this.load();
    }
  }

  load() {
    const discussionId = this.attrs.discussionId;
    this.loading = true;
    this.error   = null;

    fetch(`${app.forum.attribute('apiUrl')}/sg-posts/${discussionId}`, {
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((data) => {
        this.discussion = data.discussion;
        this.posts      = data.data || [];
        this.loading    = false;
        document.title  = `${this.discussion.title} — ${app.forum.attribute('title')}`;
        m.redraw();
      })
      .catch((err) => {
        this.error   = err.message;
        this.loading = false;
        m.redraw();
      });
  }

  submitReply() {
    const content = this.replyText.trim();
    if (!content || this.submitting) return;

    this.submitting = true;
    this.replyError = null;

    fetch(`${app.forum.attribute('apiUrl')}/sg-posts`, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ discussionId: this.discussion.id, content }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((post) => {
        this.posts.push(post);
        if (this.discussion) this.discussion.commentCount = (this.discussion.commentCount || 0) + 1;
        this.replyText  = '';
        this.submitting = false;
        m.redraw();
        // Scroll to bottom
        requestAnimationFrame(() => {
          const el = document.querySelector('.SGThread-posts');
          if (el) el.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'end' });
        });
      })
      .catch((err) => {
        this.replyError = err.message;
        this.submitting = false;
        m.redraw();
      });
  }

  startEdit(post) {
    this.editingId = post.id;
    this.editText  = post.content;
  }

  cancelEdit() {
    this.editingId = null;
    this.editText  = '';
  }

  saveEdit(post) {
    const content = this.editText.trim();
    if (!content) return;

    fetch(`${app.forum.attribute('apiUrl')}/sg-posts/${post.id}`, {
      method:  'PATCH',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ content }),
    })
      .then((r) => r.json())
      .then((updated) => {
        const idx = this.posts.findIndex((p) => p.id === post.id);
        if (idx !== -1) this.posts[idx] = updated;
        this.cancelEdit();
        m.redraw();
      });
  }

  deletePost(post) {
    if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete_post_confirm'))) return;
    this.deletingId = post.id;

    fetch(`${app.forum.attribute('apiUrl')}/sg-posts/${post.id}`, {
      method:  'DELETE',
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    }).then(() => {
      this.posts = this.posts.filter((p) => p.id !== post.id);
      if (this.discussion) this.discussion.commentCount = Math.max(0, (this.discussion.commentCount || 1) - 1);
      this.deletingId = null;
      m.redraw();
    }).catch(() => {
      this.deletingId = null;
      m.redraw();
    });
  }

  view() {
    const { slug } = this.attrs;
    const actor    = app.session.user;

    return m('.SGThread', [
      // Breadcrumb / back button
      m('.SGThread-back.container', [
        m('a.SGThread-backLink', {
          href: app.route('ernestdefoe-social-groups.show', { slug }),
          onclick: (e) => { e.preventDefault(); m.route.set(app.route('ernestdefoe-social-groups.show', { slug })); },
        }, [m('i.fas.fa-arrow-left'), ' ',
            app.translator.trans('ernestdefoe-social-groups.forum.discussions.back')]),
      ]),

      this.loading
        ? m('.SGThread-loading', m(LoadingIndicator, { display: 'block' }))
        : this.error
        ? m('.SGThread-error.container', this.error)
        : m('.SGThread-body.container', [
            // Discussion title
            m('.SGThread-header', [
              m('h1.SGThread-title', this.discussion.title),
              m('.SGThread-meta', [
                m('span', app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_count', { count: this.discussion.commentCount })),
                this.discussion.isLocked
                  ? m('span.SGThread-locked', [m('i.fas.fa-lock'), ' ',
                      app.translator.trans('ernestdefoe-social-groups.forum.discussions.locked')])
                  : null,
              ]),
            ]),

            // Posts
            m('.SGThread-posts',
              this.posts.map((post) => this.viewPost(post))
            ),

            // Reply composer
            actor && !this.discussion.isLocked
              ? m('.SGThread-composer', [
                  m('.SGThread-composerAvatar', [
                    actor.attribute('avatarUrl')
                      ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
                      : m('span.SGThread-composerInitial', (actor.attribute('displayName') || '?')[0].toUpperCase()),
                  ]),
                  m('.SGThread-composerInput', [
                    this.replyError
                      ? m('.Alert.Alert--error', this.replyError)
                      : null,
                    m('textarea.FormControl.SGThread-textarea', {
                      placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_placeholder'),
                      value:       this.replyText,
                      oninput:     (e) => { this.replyText = e.target.value; },
                      rows:        3,
                      disabled:    this.submitting,
                    }),
                    m('.SGThread-composerActions', [
                      m(Button, {
                        class:    'Button Button--primary',
                        loading:  this.submitting,
                        disabled: this.submitting || !this.replyText.trim(),
                        onclick:  () => this.submitReply(),
                      }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_button')),
                    ]),
                  ]),
                ])
              : null,
          ]),
    ]);
  }

  viewPost(post) {
    const isEditing  = this.editingId === post.id;
    const isDeleting = this.deletingId === post.id;

    return m('.SGThread-post', { key: post.id, class: isDeleting ? 'is-deleting' : '' }, [
      // Avatar
      m('.SGThread-postAvatar', [
        post.user && post.user.avatarUrl
          ? m('img', { src: post.user.avatarUrl, alt: post.user.displayName })
          : m('span.SGThread-postInitial',
              (post.user?.displayName || '?')[0].toUpperCase()),
      ]),

      m('.SGThread-postMain', [
        // Header
        m('.SGThread-postHeader', [
          m('span.SGThread-postAuthor', post.user?.displayName || ''),
          m('span.SGThread-postTime', { title: post.createdAt },
            humanTime(new Date(post.createdAt))),
          post.updatedAt !== post.createdAt
            ? m('span.SGThread-postEdited',
                app.translator.trans('ernestdefoe-social-groups.forum.discussions.edited'))
            : null,
        ]),

        // Body
        isEditing
          ? m('.SGThread-postEdit', [
              m('textarea.FormControl.SGThread-editTextarea', {
                value:   this.editText,
                oninput: (e) => { this.editText = e.target.value; },
                rows:    4,
              }),
              m('.SGThread-editActions', [
                m(Button, {
                  class:   'Button Button--primary Button--sm',
                  onclick: () => this.saveEdit(post),
                  disabled: !this.editText.trim(),
                }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.save_edit')),
                m(Button, {
                  class:   'Button Button--sm',
                  onclick: () => this.cancelEdit(),
                }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.cancel_edit')),
              ]),
            ])
          : m('.SGThread-postContent', post.content),

        // Actions
        !isEditing && (post.canEdit || post.canDelete)
          ? m('.SGThread-postActions', [
              post.canEdit
                ? m('button.SGThread-actionBtn', { onclick: () => this.startEdit(post) },
                    [m('i.fas.fa-pencil-alt'), ' ',
                     app.translator.trans('ernestdefoe-social-groups.forum.discussions.edit')])
                : null,
              post.canDelete
                ? m('button.SGThread-actionBtn.SGThread-actionBtn--danger', {
                    onclick: () => this.deletePost(post),
                  }, [m('i.fas.fa-trash'), ' ',
                      app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete_post')])
                : null,
            ])
          : null,
      ]),
    ]);
  }
}
