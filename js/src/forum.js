import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import HeaderPrimary from 'flarum/forum/components/HeaderPrimary';
import SocialGroup from './forum/models/SocialGroup';
import GroupsPage from './forum/components/GroupsPage';
import GroupPage from './forum/components/GroupPage';

app.initializers.add('ernestdefoe-social-groups', () => {
  // Register the model
  app.store.models['social-groups'] = SocialGroup;

  // Register client-side routes
  app.routes['ernestdefoe-social-groups.index'] = {
    path: '/groups',
    component: GroupsPage,
  };

  app.routes['ernestdefoe-social-groups.show'] = {
    path: '/groups/:slug',
    component: GroupPage,
  };

  // Add "Groups" link to the primary navigation header
  extend(HeaderPrimary.prototype, 'items', function (items) {
    items.add(
      'social-groups',
      m(
        'a.SocialGroups-navLink',
        {
          href: app.route('ernestdefoe-social-groups.index'),
          class: m.route.get() && m.route.get().startsWith('/groups') ? 'active' : '',
          onclick: (e) => {
            e.preventDefault();
            m.route.set(app.route('ernestdefoe-social-groups.index'));
          },
        },
        [m('i.fas.fa-users'), m('span', ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.title'))]
      ),
      30
    );
  });
});
