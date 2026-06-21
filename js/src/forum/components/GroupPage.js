import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import GroupHero from './GroupHero';
import GroupFeed from './GroupFeed';
import GroupMediaGallery from './GroupMediaGallery';
import GroupAnalyticsPanel from './GroupAnalyticsPanel';
import MemberList from './MemberList';
import JoinRequestsPanel from './JoinRequestsPanel';
import EditGroupModal from './EditGroupModal';

export default class GroupPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.group     = null;
    this.loading   = true;
    this.error     = null;
    this.activeTab = 'posts';
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadGroup(vnode.attrs.slug);
  }

  onupdate(vnode) {
    if (vnode.attrs.slug !== this.attrs.slug) {
      this.group   = null;
      this.loading = true;
      this.error   = null;
      this.loadGroup(vnode.attrs.slug);
    }
  }

  loadGroup(slug) {
    // GET /api/social-groups/{slug} (the resource's find() override treats a
    // non-numeric id as a slug lookup). Use app.request directly — rather than
    // app.store.find — so we can pass an errorHandler and render our own inline
    // "not found" message instead of Flarum's global error modal. An unknown or
    // now-private group is an expected state on this page, not a crash.
    // (Reported: a spurious "The requested resource was not found." modal.)
    app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/social-groups/${encodeURIComponent(slug)}`,
        params: { include: 'user' },
        errorHandler: () => {},
      })
      .then((payload) => {
        let group = null;
        try {
          group = app.store.pushPayload(payload);
        } catch (e) {
          group = null;
        }
        if (!group && payload && payload.data && payload.data.id) {
          group = app.store.getById('social-groups', payload.data.id);
        }
        this.group   = group || null;
        this.loading = false;
        if (this.group) {
          document.title = `${this.group.name()} — ${app.forum.attribute('title')}`;
        }
        m.redraw();
      })
      .catch(() => {
        this.error   = app.translator.trans('ernestdefoe-social-groups.forum.group.load_error');
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) {
      return m('.GroupPage', m('.GroupPage-loading', m(LoadingIndicator, { display: 'block' })));
    }

    if (this.error || !this.group) {
      return m('.GroupPage', m('.container', m('p.GroupPage-error', this.error || app.translator.trans('ernestdefoe-social-groups.forum.group.not_found'))));
    }

    const group      = this.group;
    const isMember   = group.isMember();
    const isCreator  = group.isCreator();
    const canEdit    = group.canEdit();
    const isApproval = group.membershipType() === 'approval';

    return m('.GroupPage', [
      // Hero: banner + avatar + name + join/edit buttons
      m(GroupHero, {
        group,
        onJoin:  () => m.redraw(),
        onLeave: () => m.redraw(),
        onEdit:  () => app.modal.show(EditGroupModal, {
          group,
          onSaved: (updated) => { group.pushData({ attributes: updated }); m.redraw(); },
        }),
      }),

      // Two-column body
      m('.GroupPage-body', [
        // Main column — tabs + content
        m('.GroupPage-main', [
          m('.GroupPage-tabs', [
            m('button.GroupPage-tab', {
              class:   this.activeTab === 'posts' ? 'is-active' : '',
              onclick: () => { this.activeTab = 'posts'; m.redraw(); },
            }, [m('i.fa-solid.fa-bars-staggered'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.group.tab_posts')]),
            m('button.GroupPage-tab', {
              class:   this.activeTab === 'media' ? 'is-active' : '',
              onclick: () => { this.activeTab = 'media'; m.redraw(); },
            }, [m('i.fa-solid.fa-photo-film'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.group.tab_media')]),
          ]),
          this.activeTab === 'media'
            ? m(GroupMediaGallery, {
                groupId:   group.id(),
                groupSlug: group.slug(),
                isMember,
              })
            : m(GroupFeed, {
                groupId:   group.id(),
                groupSlug: group.slug(),
                isMember,
              }),
        ]),

        // Sidebar — join requests (approval) + about + members
        m('.GroupPage-sidebar', [
          // Join requests panel — creator/admins only on approval groups
          (isCreator || canEdit) && isApproval
            ? m(JoinRequestsPanel, {
                groupId:    group.id(),
                onApproved: () => {
                  // Optimistic +1: approve always grows the count
                  // (the server skips if user was already a member,
                  // which JoinRequestsPanel wouldn't have shown anyway).
                  const cur = group.memberCount() || 0;
                  group.pushData({ attributes: { memberCount: cur + 1 } });
                  m.redraw();
                },
              })
            : null,

          m('.GroupPage-aboutCard', [
            m('.GroupPage-aboutCard-title',
              app.translator.trans('ernestdefoe-social-groups.forum.group.about_title')),
            group.description()
              ? m('p.GroupPage-aboutCard-text', group.description())
              : m('p.GroupPage-aboutCard-empty',
                  app.translator.trans('ernestdefoe-social-groups.forum.group.description_placeholder')),
            group.isPrivate()
              ? m('.GroupPage-privateTag', [m('i.fa-solid.fa-lock'), ' ',
                  app.translator.trans('ernestdefoe-social-groups.forum.groups.private')])
              : null,
            isApproval
              ? m('.GroupPage-approvalTag', [m('i.fa-solid.fa-user-check'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.approval_required')])
              : null,
          ]),

          canEdit || isCreator
            ? m(GroupAnalyticsPanel, {
                groupId:   group.id(),
                groupSlug: group.slug(),
              })
            : null,

          m(MemberList, {
            groupId:   group.id(),
            isCreator,
          }),
        ]),
      ]),
    ]);
  }
}
