import { apiBase } from '../utils/api';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';

export default class GroupMediaGallery extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.items   = null;
    this.loading = true;
    this.page    = 1;
    this.pages   = 1;
    this.total   = 0;

    this.lightboxIndex = null;
    this._brokenIndexes = new Set();

    this.uploading    = false;
    this.uploadError  = null;
    this._pendingFiles = [];
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();
    this._onKey = (e) => {
      if (this.lightboxIndex === null) return;
      if (e.key === 'Escape')      { this.lightboxIndex = null; m.redraw(); }
      if (e.key === 'ArrowRight')  { this.lightboxNext(); }
      if (e.key === 'ArrowLeft')   { this.lightboxPrev(); }
    };
    document.addEventListener('keydown', this._onKey);
  }

  onremove() {
    document.removeEventListener('keydown', this._onKey);
  }

  onupdate(vnode) {
    if (vnode.attrs.groupId !== this.attrs.groupId) {
      this.items   = null;
      this.loading = true;
      this.page    = 1;
      this.load();
    }
  }

  load(page = 1) {
    this.loading = true;
    this.page    = page;

    fetch(`${apiBase()}/sg-media/${this.attrs.groupId}?page=${page}`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.json())
      .then((data) => {
        this.items   = data.data  || [];
        this.total   = data.total || 0;
        this.pages   = data.pages || 1;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.items   = [];
        this.loading = false;
        m.redraw();
      });
  }

  // ── Upload ──────────────────────────────────────────────────────────────────

  handleUploadFiles(files) {
    if (!files.length || this.uploading) return;
    this.uploading   = true;
    this.uploadError = null;
    m.redraw();

    const uploads = Array.from(files).map((file) => {
      const fd = new FormData();
      fd.append('files[]', file);
      return fetch(`${apiBase()}/fof/upload`, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
        body:        fd,
      })
        .then((r) => {
          if (!r.ok) return r.json().then((e) => { throw new Error(e.errors?.[0]?.detail || e.error || 'Upload failed'); });
          return r.json();
        })
        .then((data) => {
          const fileData = Array.isArray(data.data) ? data.data[0] : data.data;
          return fileData?.attributes?.bbcode || `[upl-file uuid="${fileData?.attributes?.uuid || fileData?.id}"][/upl-file]`;
        });
    });

    Promise.all(uploads)
      .then((bbcodes) => {
        const content = bbcodes.join('\n');
        return fetch(`${apiBase()}/sg-discussions`, {
          method:      'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': app.session.csrfToken || '',
          },
          body: JSON.stringify({
            groupId: this.attrs.groupId,
            content,
          }),
        });
      })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Failed to save to gallery'); });
        return r.json();
      })
      .then(() => {
        this.uploading = false;
        this._brokenIndexes = new Set();
        this.load(1);
      })
      .catch((err) => {
        this.uploading   = false;
        this.uploadError = err.message || 'Upload failed.';
        m.redraw();
      });
  }

  // ── Lightbox ────────────────────────────────────────────────────────────────

  lightboxOpen(index) {
    this.lightboxIndex = index;
    m.redraw();
  }

  lightboxClose() {
    this.lightboxIndex = null;
    m.redraw();
  }

  lightboxNext() {
    if (this.lightboxIndex === null || !this.items) return;
    let next = (this.lightboxIndex + 1) % this.items.length;
    const start = next;
    while (this._brokenIndexes.has(next)) {
      next = (next + 1) % this.items.length;
      if (next === start) break;
    }
    this.lightboxIndex = next;
    m.redraw();
  }

  lightboxPrev() {
    if (this.lightboxIndex === null || !this.items) return;
    let prev = (this.lightboxIndex - 1 + this.items.length) % this.items.length;
    const start = prev;
    while (this._brokenIndexes.has(prev)) {
      prev = (prev - 1 + this.items.length) % this.items.length;
      if (prev === start) break;
    }
    this.lightboxIndex = prev;
    m.redraw();
  }

  // ── Views ───────────────────────────────────────────────────────────────────

  view() {
    return m('.SGMedia', [
      this.viewGallery(),
      this.lightboxIndex !== null ? this.viewLightbox() : null,
    ]);
  }

  viewGallery() {
    const { isMember } = this.attrs;
    const actor = app.session.user;

    return [
      // Upload bar — visible to members
      actor && isMember
        ? m('.SGMedia-uploadBar', [
            this.uploadError
              ? m('.Alert.Alert--error.SGMedia-uploadError', [
                  this.uploadError,
                  m('button.SGMedia-uploadErrorDismiss', { onclick: () => { this.uploadError = null; m.redraw(); } }, '×'),
                ])
              : null,
            m('label.Button.Button--primary.SGMedia-uploadBtn', {
              class: this.uploading ? 'disabled' : '',
              title: 'Upload images to gallery',
            }, [
              m('input[type=file]', {
                accept:   'image/*',
                multiple: true,
                style:    'display:none',
                disabled: this.uploading,
                onchange: (e) => {
                  if (e.target.files.length) this.handleUploadFiles(e.target.files);
                  e.target.value = '';
                },
              }),
              this.uploading
                ? [m('i.fas.fa-spinner.fa-spin'), ' Uploading…']
                : [m('i.fas.fa-upload'), ' Upload Images'],
            ]),
          ])
        : null,

      this.loading
        ? m('.SGMedia-loading', m(LoadingIndicator, { display: 'block' }))
        : !this.items || this.items.length === 0
        ? m('.SGMedia-empty', [
            m('i.fas.fa-photo-video'),
            m('p', 'No media found in this group yet.'),
          ])
        : [
            m('.SGMedia-grid',
              this.items.map((item, i) =>
                m('.SGMedia-thumb', {
                  key:     `${item.postId}-${i}`,
                  onclick: () => this.lightboxOpen(i),
                }, [
                  m('img', {
                    src:     item.url,
                    alt:     '',
                    loading: 'lazy',
                    onerror: (e) => {
                      this._brokenIndexes.add(i);
                      e.target.closest('.SGMedia-thumb').style.display = 'none';
                    },
                  }),
                ])
              )
            ),
            this.pages > 1
              ? m('.SGMedia-pagination', [
                  m(Button, {
                    class:    'Button',
                    disabled: this.page <= 1,
                    onclick:  () => this.load(this.page - 1),
                  }, m('i.fas.fa-chevron-left')),
                  m('span.SGMedia-pageInfo', `${this.page} / ${this.pages}`),
                  m(Button, {
                    class:    'Button',
                    disabled: this.page >= this.pages,
                    onclick:  () => this.load(this.page + 1),
                  }, m('i.fas.fa-chevron-right')),
                ])
              : null,
          ],
    ];
  }

  viewLightbox() {
    const item = this.items[this.lightboxIndex];
    if (!item) return null;

    return m('.SGMedia-lightbox', {
      onclick: (e) => { if (e.target === e.currentTarget) this.lightboxClose(); },
    }, [
      m('button.SGMedia-lightboxClose', {
        onclick: () => this.lightboxClose(),
        title:   'Close',
      }, m('i.fas.fa-times')),

      this.items.length > 1
        ? m('button.SGMedia-lightboxPrev', {
            onclick: (e) => { e.stopPropagation(); this.lightboxPrev(); },
            title:   'Previous',
          }, m('i.fas.fa-chevron-left'))
        : null,

      m('.SGMedia-lightboxContent', [
        m('img.SGMedia-lightboxImg', {
          src: item.url,
          alt: '',
        }),
        item.user
          ? m('.SGMedia-lightboxMeta', [
              item.user.avatarUrl
                ? m('img.SGMedia-lightboxAvatar', { src: item.user.avatarUrl, alt: '' })
                : m('span.SGMedia-lightboxInitial', (item.user.displayName || '?')[0].toUpperCase()),
              m('span.SGMedia-lightboxAuthor', item.user.displayName),
              m('a.SGMedia-lightboxThread', {
                href:    app.route('ernestdefoe-social-groups.discussion', {
                  slug:         this.attrs.groupSlug,
                  discussionId: item.discussionId,
                }),
                onclick: (e) => {
                  e.preventDefault();
                  this.lightboxClose();
                  m.route.set(app.route('ernestdefoe-social-groups.discussion', {
                    slug:         this.attrs.groupSlug,
                    discussionId: item.discussionId,
                  }));
                },
              }, [m('i.fas.fa-external-link-alt'), ' View post']),
            ])
          : null,
        m('.SGMedia-lightboxCounter', (() => {
          const visible = this.items.length - this._brokenIndexes.size;
          const pos = this.items.slice(0, this.lightboxIndex + 1).filter((_, j) => !this._brokenIndexes.has(j)).length;
          return `${pos} / ${visible}`;
        })()),
      ]),

      this.items.length > 1
        ? m('button.SGMedia-lightboxNext', {
            onclick: (e) => { e.stopPropagation(); this.lightboxNext(); },
            title:   'Next',
          }, m('i.fas.fa-chevron-right'))
        : null,
    ]);
  }
}
