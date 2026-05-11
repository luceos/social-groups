import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import GroupHero from './GroupHero';
import GroupDiscussionList from './GroupDiscussionList';
import MemberList from './MemberList';
import JoinRequestsPanel from './JoinRequestsPanel';
import EditGroupModal from './EditGroupModal';

export default class GroupPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.group   = null;
    this.loading = true;
    this.error   = null;
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
    // Use the Show endpoint (GET /api/social-groups/{slug}) so we avoid the
    // JSON:API server rejecting non-standard query parameters.  The resource's
    // find() override treats non-numeric IDs as slug lookups.
    app.store
      .find('social-groups', slug, {
        include: 'user',
      })
      .then((group) => {
        this.group   = group || null;
        this.loading = false;
        if (group) {
          document.title = `${group.name()} — ${app.forum.attribute('title')}`;
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
        // Main column — discussion feed
        m('.GroupPage-main', [
          m(GroupDiscussionList, {
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
                onApproved: (memberCount) => {
                  group.pushData({ attributes: { memberCount } });
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
              ? m('.GroupPage-privateTag', [m('i.fas.fa-lock'), ' ',
                  app.translator.trans('ernestdefoe-social-groups.forum.groups.private')])
              : null,
            isApproval
              ? m('.GroupPage-approvalTag', [m('i.fas.fa-user-check'), ' Approval required'])
              : null,
          ]),

          m(MemberList, {
            groupId:   group.id(),
            isCreator,
          }),
        ]),
      ]),
    ]);
  }
}
