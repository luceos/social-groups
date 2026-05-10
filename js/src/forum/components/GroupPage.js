import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import GroupHero from './GroupHero';
import MemberList from './MemberList';
import EditGroupModal from './EditGroupModal';

export default class GroupPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.group = null;
    this.loading = true;
    this.error = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadGroup(vnode.attrs.slug);
  }

  onupdate(vnode) {
    // Re-load if slug changes (navigation between groups)
    if (vnode.attrs.slug !== this.currentSlug) {
      this.group = null;
      this.loading = true;
      this.loadGroup(vnode.attrs.slug);
    }
  }

  loadGroup(slug) {
    this.currentSlug = slug;

    app.store
      .find('social-groups', { filter: { slug }, include: 'user', 'page[limit]': 1 })
      .then((results) => {
        const groups = Array.isArray(results) ? results : [results];
        const group = groups.find((g) => g.slug() === slug) || groups[0];

        if (!group) {
          this.error = 'Group not found.';
          this.loading = false;
          m.redraw();
          return;
        }

        document.title = `${group.name()} — ${app.forum.attribute('title')}`;
        this.group = group;
        this.loading = false;
        m.redraw();
      })
      .catch((err) => {
        this.error = err?.message || 'Failed to load group.';
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) {
      return m('div.GroupPage', [
        m('div.GroupPage-loading', [m(LoadingIndicator, { display: 'block' })]),
      ]);
    }

    if (this.error || !this.group) {
      return m('div.GroupPage', [
        m('div.container', [
          m('div.GroupPage-error', [
            m('i.fas.fa-exclamation-triangle'),
            m('p', this.error || 'Group not found.'),
            m(
              'a',
              { href: app.route('ernestdefoe-social-groups.index'), onclick: (e) => { e.preventDefault(); m.route.set(app.route('ernestdefoe-social-groups.index')); } },
              '← Back to groups'
            ),
          ]),
        ]),
      ]);
    }

    const { group } = this;
    const description = group.description();

    return m('div.GroupPage', [
      // Hero section with banner, avatar, name, join/edit buttons
      m(GroupHero, {
        group,
        onEdit: () =>
          app.modal.show(EditGroupModal, {
            group,
            onSaved: () => m.redraw(),
            onDeleted: () => {
              m.route.set(app.route('ernestdefoe-social-groups.index'));
            },
          }),
        onJoin: () => m.redraw(),
        onLeave: () => m.redraw(),
      }),

      // Body: description + sidebar
      m('div.GroupPage-body', [
        // Main content
        m('div.GroupPage-content', [
          m(
            'div.GroupPage-description',
            description
              ? description
              : m('em.GroupPage-noDescription', app.translator.trans('ernestdefoe-social-groups.forum.group.description_placeholder'))
          ),
        ]),

        // Sidebar with members
        m('div.GroupPage-sidebar', [
          m(MemberList, { groupId: group.id() }),
        ]),
      ]),
    ]);
  }
}
