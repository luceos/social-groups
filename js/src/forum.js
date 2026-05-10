import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import PostUser from 'flarum/forum/components/PostUser';
import SettingsPage from 'flarum/forum/components/SettingsPage';
import LinkButton from 'flarum/common/components/LinkButton';
import SocialGroup from './forum/models/SocialGroup';
import GroupsPage from './forum/components/GroupsPage';
import GroupPage from './forum/components/GroupPage';
import GroupDiscussionThread from './forum/components/GroupDiscussionThread';
import GroupBadge from './forum/components/GroupBadge';
import PrimaryGroupSelector from './forum/components/PrimaryGroupSelector';

app.initializers.add('ernestdefoe-social-groups', () => {
  app.store.models['social-groups'] = SocialGroup;

  // Routes
  app.routes['ernestdefoe-social-groups.index'] = {
    path: '/groups',
    component: GroupsPage,
  };

  app.routes['ernestdefoe-social-groups.show'] = {
    path: '/groups/:slug',
    component: GroupPage,
  };

  app.routes['ernestdefoe-social-groups.discussion'] = {
    path: '/groups/:slug/d/:discussionId',
    component: GroupDiscussionThread,
  };

  // ── Sidebar navigation link (alongside All Discussions, Tags, etc.) ────────
  extend(IndexSidebar.prototype, 'items', function (items) {
    items.add(
      'social-groups',
      m(
        LinkButton,
        {
          href: app.route('ernestdefoe-social-groups.index'),
          icon: 'fas fa-users',
        },
        app.translator.trans('ernestdefoe-social-groups.forum.groups.title')
      ),
      30
    );
  });

  // ── Group badge on posts ───────────────────────────────────────────────────
  extend(PostUser.prototype, 'view', function (vnode) {
    const user = this.attrs.post && this.attrs.post.user && this.attrs.post.user();
    if (!user) return;

    const name  = user.attribute('sgPrimaryGroupName');
    const color = user.attribute('sgPrimaryGroupColor');
    const slug  = user.attribute('sgPrimaryGroupSlug');

    if (!name || !slug) return;

    if (vnode && vnode.children) {
      vnode.children.push(m(GroupBadge, { name, color, slug }));
    }
  });

  // ── Primary group selector in account settings ─────────────────────────────
  extend(SettingsPage.prototype, 'settingsItems', function (items) {
    items.add('sg-primary-group', m(PrimaryGroupSelector), 5);
  });
});
