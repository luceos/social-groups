import app from 'flarum/forum/app';
import { extend as flarumExtend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserCard from 'flarum/forum/components/UserCard';
import LinkButton from 'flarum/common/components/LinkButton';
import SocialGroup from './forum/models/SocialGroup';
import GroupsPage from './forum/components/GroupsPage';
import GroupPage from './forum/components/GroupPage';
import GroupDiscussionThread from './forum/components/GroupDiscussionThread';
import SocialGroupNewPostNotification from './forum/components/SocialGroupNewPostNotification';
import SocialGroupNewReplyNotification from './forum/components/SocialGroupNewReplyNotification';
import UserGroupBadges from './forum/components/UserGroupBadges';
import PrimaryGroupSelector from './forum/components/PrimaryGroupSelector';

app.initializers.add('ernestdefoe-social-groups', (app) => {
  // NOTE: priority -10 ensures this initializer runs after flarum/realtime's
  // (which registers at the default priority 0).  Without this, app.realtime
  // is undefined when the Realtime extender below calls extend(app, id) and
  // the WebSocket event registrations silently fail.
  // ── flarum/realtime integration (optional) ────────────────────────────────
  // Bridge WebSocket public-channel events to DOM CustomEvents so individual
  // components can subscribe/unsubscribe cleanly without direct Pusher access.
  // Calling rtExtender.extend(app, id) directly is equivalent to returning the
  // extender from the module export — without triggering the autoExportLoader.
  try {
    const mod = require('flarum-realtime/forum/extenders/Realtime');
    const RealtimeExtender = mod?.default ?? mod;
    if (RealtimeExtender) {
      new RealtimeExtender()
        .onPublicChannelEvent('sg-post-created', (data) => {
          document.dispatchEvent(new CustomEvent('sg:post-created', { detail: data }));
        })
        .onPublicChannelEvent('sg-typing', (data) => {
          document.dispatchEvent(new CustomEvent('sg:typing', { detail: data }));
        })
        .extend(app, 'ernestdefoe-social-groups');
    }
  } catch (_) {
    // flarum/realtime not installed — live updates silently disabled.
  }
  app.store.models['social-groups'] = SocialGroup;

  // Notification components
  app.notificationComponents.socialGroupNewPost  = SocialGroupNewPostNotification;
  app.notificationComponents.socialGroupNewReply = SocialGroupNewReplyNotification;

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

  // ── User card group badges + primary group selector ───────────────────────
  flarumExtend(UserCard.prototype, 'profileItems', function (items) {
    const user = this.attrs.user;
    if (!user || !user.id() || !items || typeof items.add !== 'function') return;

    // Always show group badges on every profile card
    items.add('social-group-badges', m(UserGroupBadges, { userId: user.id() }), -10);

    // Show the primary group selector only on the current user's own card
    if (app.session.user && String(app.session.user.id()) === String(user.id())) {
      items.add('sg-primary-group-selector', m(PrimaryGroupSelector), -20);
    }
  });

  // ── Primary group selector in account settings (Flarum 2 SettingsPage) ────
  // SettingsPage may or may not exist depending on Flarum version. Guard with
  // try/require so a missing component never crashes the whole extension.
  try {
    const mod = require('flarum/forum/components/SettingsPage');
    const SettingsPage = mod?.default ?? mod;
    if (SettingsPage?.prototype) {
      flarumExtend(SettingsPage.prototype, 'settingsItems', function (items) {
        if (app.session.user) {
          items.add('sg-primary-group', m(PrimaryGroupSelector), 10);
        }
      });
    }
  } catch (_) {
    // SettingsPage not available in this Flarum build — selector is on the profile card instead
  }

  // ── Sidebar navigation link ────────────────────────────────────────────────
  flarumExtend(IndexSidebar.prototype, 'navItems', function (items) {
    items.add(
      'social-groups',
      m(
        LinkButton,
        {
          href: app.route('ernestdefoe-social-groups.index'),
          icon: 'fa-solid fa-users',
        },
        app.translator.trans('ernestdefoe-social-groups.forum.groups.title')
      ),
      90
    );
  });
}, -10);
