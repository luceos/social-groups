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

    // Pending upload chips for each composer (reply vs. edit)
    this.uploads     = [];
    this.editUploads = [];
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

  onremove(vnode) {
    super.onremove(vnode);
    this._revokeAll(this.uploads);
    this._revokeAll(this.editUploads);
  }

  _revokeAll(uploads) {
    uploads.forEach((u) => { if (u.previewUrl) URL.revokeObjectURL(u.previewUrl); });
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

  // ── File uploads ─────────────────────────────────────────────────────────
  //
  // uploadsKey: 'uploads' (reply) or 'editUploads' (edit)
  // textKey:    'replyText' or 'editText'

  handleFiles(files, uploadsKey, textKey) {
    for (const file of files) {
      const id         = Math.random().toString(36).slice(2);
      const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;

      this[uploadsKey].push({ id, name: file.name, previewUrl, uploading: true, error: null, uuid: null });
      m.redraw();

      const fd = new FormData();
      fd.append('file', file);

      fetch(`${app.forum.attribute('apiUrl')}/fof/upload`, {
        method:  'POST',
        headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
        body:    fd,
      })
        .then((r) => {
          if (!r.ok) return r.json().then((e) => { throw new Error(e.errors?.[0]?.detail || e.error || 'Upload failed'); });
          return r.json();
        })
        .then((data) => {
          const uuid   = data.data?.attributes?.uuid || data.data?.id;
          const upload = this[uploadsKey].find((u) => u.id === id);
          if (upload) {
            upload.uuid      = uuid;
            upload.uploading = false;
            const tag        = `[upl-file uuid="${uuid}"][/upl-file]`;
            this[textKey]    = this[textKey] ? `${this[textKey]}\n${tag}` : tag;
          }
          m.redraw();
        })
        .catch((err) => {
          const upload = this[uploadsKey].find((u) => u.id === id);
          if (upload) { upload.uploading = false; upload.error = err.message; }
          m.redraw();
        });
    }
  }

  removeUpload(id, uploadsKey, textKey) {
    const upload = this[uploadsKey].find((u) => u.id === id);
    if (!upload) return;
    if (upload.previewUrl) URL.revokeObjectURL(upload.previewUrl);
    if (upload.uuid) {
      const tag      = `[upl-file uuid="${upload.uuid}"][/upl-file]`;
      this[textKey]  = this[textKey].replace(`\n${tag}`, '').replace(tag, '').trim();
    }
    this[uploadsKey] = this[uploadsKey].filter((u) => u.id !== id);
    m.redraw();
  }

  // ── Posts ─────────────────────────────────────────────────────────────────

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
        this._revokeAll(this.uploads);
        this.uploads = [];
        m.redraw();
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
    this._revokeAll(this.editUploads);
    this.editUploads = [];
    this.editingId   = post.id;
    this.editText    = post.content;
  }

  cancelEdit() {
    this._revokeAll(this.editUploads);
    this.editUploads = [];
    this.editingId   = null;
    this.editText    = '';
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
        this._revokeAll(this.editUploads);
        this.editUploads = [];
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

  // ── Views ─────────────────────────────────────────────────────────────────

  view() {
    const { slug } = this.attrs;
    const actor    = app.session.user;

    return m('.SGThread', [
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

            m('.SGThread-posts',
              this.posts.map((post) => this.viewPost(post))
            ),

            actor && !this.discussion.isLocked
              ? m('.SGThread-composer', [
                  m('.SGThread-composerAvatar', [
                    actor.attribute('avatarUrl')
                      ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
                      : m('span.SGThread-composerInitial', (actor.attribute('displayName') || '?')[0].toUpperCase()),
                  ]),
                  m('.SGThread-composerInput', [
                    this.replyError ? m('.Alert.Alert--error', this.replyError) : null,
                    m('textarea.FormControl.SGThread-textarea', {
                      placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_placeholder'),
                      value:       this.replyText,
                      oninput:     (e) => { this.replyText = e.target.value; },
                      rows:        3,
                      disabled:    this.submitting,
                    }),
                    this.uploads.length
                      ? m('.SGThread-uploads', this.uploads.map((u) => this.viewUpload(u, 'uploads', 'replyText')))
                      : null,
                    m('.SGThread-composerActions', [
                      this.viewUploadBtn('uploads', 'replyText', this.submitting),
                      m(Button, {
                        class:    'Button Button--primary',
                        loading:  this.submitting,
                        disabled: this.submitting || !this.replyText.trim() || this.uploads.some((u) => u.uploading),
                        onclick:  () => this.submitReply(),
                      }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_button')),
                    ]),
                  ]),
                ])
              : null,
          ]),
    ]);
  }

  viewUploadBtn(uploadsKey, textKey, disabled) {
    return m('label.SGThread-uploadBtn', {
      title: app.translator.trans('ernestdefoe-social-groups.forum.discussions.upload_image'),
      class: disabled ? 'disabled' : '',
    }, [
      m('input[type=file]', {
        accept:   'image/*,video/*,.pdf,.doc,.docx,.zip',
        multiple: true,
        style:    'display:none',
        disabled,
        onchange: (e) => {
          if (e.target.files.length) this.handleFiles(Array.from(e.target.files), uploadsKey, textKey);
          e.target.value = '';
        },
      }),
      m('i.fas.fa-paperclip'),
    ]);
  }

  viewUpload(u, uploadsKey, textKey) {
    const cls = 'SGThread-upload' +
      (u.error ? '.SGThread-upload--error' : u.uploading ? '.SGThread-upload--loading' : '.SGThread-upload--done');

    return m(cls, { key: u.id }, [
      u.uploading
        ? m('i.fas.fa-spinner.fa-spin.SGThread-uploadSpinner')
        : u.error
        ? m('i.fas.fa-exclamation-circle.SGThread-uploadErrIcon')
        : u.previewUrl
        ? m('img.SGThread-uploadThumb', { src: u.previewUrl, alt: u.name })
        : m('i.fas.fa-file.SGThread-uploadFileIcon'),
      m('span.SGThread-uploadName', u.error ? `${u.name}: ${u.error}` : u.name),
      !u.uploading
        ? m('button.SGThread-uploadRemove', {
            type:    'button',
            title:   app.translator.trans('ernestdefoe-social-groups.forum.discussions.upload_remove'),
            onclick: () => this.removeUpload(u.id, uploadsKey, textKey),
          }, '×')
        : null,
    ]);
  }

  viewPost(post) {
    const isEditing  = this.editingId === post.id;
    const isDeleting = this.deletingId === post.id;

    return m('.SGThread-post', { key: post.id, class: isDeleting ? 'is-deleting' : '' }, [
      m('.SGThread-postAvatar', [
        post.user && post.user.avatarUrl
          ? m('img', { src: post.user.avatarUrl, alt: post.user.displayName })
          : m('span.SGThread-postInitial',
              (post.user?.displayName || '?')[0].toUpperCase()),
      ]),

      m('.SGThread-postMain', [
        m('.SGThread-postHeader', [
          m('span.SGThread-postAuthor', post.user?.displayName || ''),
          m('span.SGThread-postTime', { title: post.createdAt },
            humanTime(new Date(post.createdAt))),
          post.updatedAt !== post.createdAt
            ? m('span.SGThread-postEdited',
                app.translator.trans('ernestdefoe-social-groups.forum.discussions.edited'))
            : null,
        ]),

        isEditing
          ? m('.SGThread-postEdit', [
              m('textarea.FormControl.SGThread-editTextarea', {
                value:   this.editText,
                oninput: (e) => { this.editText = e.target.value; },
                rows:    4,
              }),
              this.editUploads.length
                ? m('.SGThread-uploads', this.editUploads.map((u) => this.viewUpload(u, 'editUploads', 'editText')))
                : null,
              m('.SGThread-editActions', [
                this.viewUploadBtn('editUploads', 'editText', false),
                m(Button, {
                  class:    'Button Button--primary Button--sm',
                  onclick:  () => this.saveEdit(post),
                  disabled: !this.editText.trim() || this.editUploads.some((u) => u.uploading),
                }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.save_edit')),
                m(Button, {
                  class:   'Button Button--sm',
                  onclick: () => this.cancelEdit(),
                }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.cancel_edit')),
              ]),
            ])
          : m('.SGThread-postContent', m.trust(post.contentParsed)),

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
