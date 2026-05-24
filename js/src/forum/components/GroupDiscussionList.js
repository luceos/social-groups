import { listDiscussions, deleteDiscussion as apiDeleteDiscussion } from '../utils/api';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import CreateDiscussionModal from './CreateDiscussionModal';
import humanTime from 'flarum/common/utils/humanTime';

export default class GroupDiscussionList extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.discussions = null;
    this.loading     = true;
    this.total       = 0;
    this.page        = 1;
    this.pages       = 1;
    this.deleting    = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();
  }

  onupdate(vnode) {
    // Reload when the groupId changes (navigating between groups)
    if (vnode.attrs.groupId !== this.attrs.groupId) {
      this.discussions = null;
      this.loading     = true;
      this.page        = 1;
      this.load();
    }
  }

  load(page = 1) {
    const groupId = this.attrs.groupId;
    this.loading  = true;
    this.page     = page;

    listDiscussions(groupId, { page })
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

  openDiscussion(d) {
    const slug = this.attrs.groupSlug;
    m.route.set(app.route('ernestdefoe-social-groups.discussion', { slug, discussionId: d.id }));
  }

  deleteDiscussion(d) {
    if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete_confirm'))) return;
    this.deleting = d.id;

    apiDeleteDiscussion(d.id)
      .then(() => {
        this.discussions = this.discussions.filter((x) => x.id !== d.id);
        this.total       = Math.max(0, this.total - 1);
        this.deleting    = null;
        m.redraw();
      })
      .catch(() => {
        this.deleting = null;
        m.redraw();
      });
  }

  view() {
    const { groupId, groupSlug, isMember } = this.attrs;

    return m('.SGDiscussionList', [
      // Header row
      m('.SGDiscussionList-header', [
        m('h3.SGDiscussionList-title',
          app.translator.trans('ernestdefoe-social-groups.forum.discussions.title')),
        isMember
          ? m(Button, {
              class: 'Button Button--primary SGDiscussionList-newBtn',
              icon:  'fa-solid fa-plus',
              onclick: () => app.modal.show(CreateDiscussionModal, {
                groupId,
                onCreated: (d) => {
                  this.discussions = [d, ...(this.discussions || [])];
                  this.total++;
                  m.redraw();
                },
              }),
            }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.new_button'))
          : null,
      ]),

      // Body
      this.loading
        ? m('.SGDiscussionList-loading', m(LoadingIndicator, { display: 'block' }))
        : !this.discussions || this.discussions.length === 0
        ? m('.SGDiscussionList-empty', [
            m('i.fa-solid.fa-comments'),
            m('p', app.translator.trans('ernestdefoe-social-groups.forum.discussions.empty')),
            isMember
              ? m(Button, {
                  class: 'Button Button--primary',
                  onclick: () => app.modal.show(CreateDiscussionModal, {
                    groupId,
                    onCreated: (d) => {
                      this.discussions = [d];
                      this.total = 1;
                      m.redraw();
                    },
                  }),
                }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.start_button'))
              : null,
          ])
        : [
            m('.SGDiscussionList-items',
              this.discussions.map((d) => this.viewItem(d))
            ),
            // Pagination
            this.pages > 1
              ? m('.SGDiscussionList-pagination', [
                  m(Button, {
                    class: 'Button',
                    'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.discussions.prev_page'),
                    disabled: this.page <= 1,
                    onclick: () => this.load(this.page - 1),
                  }, m('i.fa-solid.fa-chevron-left')),
                  m('span.SGDiscussionList-pageInfo',
                    `${this.page} / ${this.pages}`),
                  m(Button, {
                    class: 'Button',
                    'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.discussions.next_page'),
                    disabled: this.page >= this.pages,
                    onclick: () => this.load(this.page + 1),
                  }, m('i.fa-solid.fa-chevron-right')),
                ])
              : null,
          ],
    ]);
  }

  viewItem(d) {
    const actor    = app.session.user;
    const actorId  = actor ? actor.id() : null;
    const canDelete = d.canDelete || (actor && actor.attribute('isAdmin'));

    return m('.SGDiscussionList-item', {
      key:     d.id,
      onclick: (e) => {
        if (e.target.closest('.SGDiscussionList-delete')) return;
        this.openDiscussion(d);
      },
    }, [
      // Avatar
      m('.SGDiscussionList-avatar',
        d.user && d.user.avatarUrl
          ? m('img', { src: d.user.avatarUrl, alt: d.user.displayName })
          : m('span.SGDiscussionList-avatarInitial',
              (d.user?.displayName || '?')[0].toUpperCase())
      ),

      // Main content
      m('.SGDiscussionList-content', [
        m('.SGDiscussionList-itemTitle', d.title),
        m('.SGDiscussionList-meta', [
          m('span', d.user?.displayName || ''),
          m('span.SGDiscussionList-dot', '·'),
          m('span', { title: d.createdAt }, humanTime(new Date(d.createdAt))),
        ]),
      ]),

      // Stats
      m('.SGDiscussionList-stats', [
        m('span.SGDiscussionList-replies', [
          m('i.fa-solid.fa-reply'),
          ' ', d.commentCount,
        ]),
        d.lastPostedAt
          ? m('span.SGDiscussionList-lastReply', { title: d.lastPostedAt },
              humanTime(new Date(d.lastPostedAt)))
          : null,
      ]),

      // Delete
      canDelete
        ? m('button.SGDiscussionList-delete', {
            title:   app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete'),
            disabled: this.deleting === d.id,
            onclick:  (e) => { e.stopPropagation(); this.deleteDiscussion(d); },
          }, m('i.fa-solid.fa-trash'))
        : null,
    ]);
  }
}
