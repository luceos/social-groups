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
    this.lightboxIndex = (this.lightboxIndex + 1) % this.items.length;
    m.redraw();
  }

  lightboxPrev() {
    if (this.lightboxIndex === null || !this.items) return;
    this.lightboxIndex = (this.lightboxIndex - 1 + this.items.length) % this.items.length;
    m.redraw();
  }

  view() {
    return m('.SGMedia', [
      this.viewGallery(),
      this.lightboxIndex !== null ? this.viewLightbox() : null,
    ]);
  }

  viewGallery() {
    if (this.loading) {
      return m('.SGMedia-loading', m(LoadingIndicator, { display: 'block' }));
    }

    if (!this.items || this.items.length === 0) {
      return m('.SGMedia-empty', [
        m('i.fas.fa-photo-video'),
        m('p', 'No media found in this group yet.'),
      ]);
    }

    return [
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
              onerror: (e) => { e.target.closest('.SGMedia-thumb').style.display = 'none'; },
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
        m('.SGMedia-lightboxCounter', `${this.lightboxIndex + 1} / ${this.items.length}`),
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
