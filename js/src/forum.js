import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserCard from 'flarum/forum/components/UserCard';
import SettingsPage from 'flarum/forum/components/SettingsPage';
import LinkButton from 'flarum/common/components/LinkButton';
import Link from 'flarum/common/components/Link';
import SocialGroup from './forum/models/SocialGroup';
import GroupsPage from './forum/components/GroupsPage';
import GroupPage from './forum/components/GroupPage';
import GroupDiscussionThread from './forum/components/GroupDiscussionThread';
import SocialGroupNewPostNotification from './forum/components/SocialGroupNewPostNotification';
import SocialGroupNewReplyNotification from './forum/components/SocialGroupNewReplyNotification';
import UserGroupBadges from './forum/components/UserGroupBadges';
import { apiBase } from './forum/utils/api';

// ── Settings: Social Groups section ──────────────────────────────────────────

class SocialGroupsSettings {
  oninit(vnode) {
    this.groups   = null;
    this.loading  = true;
    this.saving   = false;
    this.featured = null;
  }

  oncreate() {
    const user = app.session.user;
    if (!user) return;

    // Pre-load current preference
    this.featured = user.attribute('preferences')?.sgFeaturedGroupId ?? null;

    fetch(`${apiBase()}/sg-user-groups/${user.id()}`, {
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.ok ? r.json() : Promise.reject())
      .then((data) => {
        this.groups  = data.data || [];
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.groups  = [];
        this.loading = false;
        m.redraw();
      });
  }

  saveFeatured(groupId) {
    this.saving   = true;
    this.featured = groupId;
    m.redraw();

    app.session.user
      .savePreferences({ sgFeaturedGroupId: groupId || null })
      .then(() => { this.saving = false; m.redraw(); })
      .catch(() => { this.saving = false; m.redraw(); });
  }

  view() {
    if (this.loading) return m('.SGSettings-loading', m('i.fas.fa-spinner.fa-spin'));

    const groups = this.groups || [];

    return m('.SGSettings', [
      groups.length === 0
        ? m('p.SGSettings-empty', [
            "You haven't joined any groups yet. ",
            m(Link, {
              href: app.route('ernestdefoe-social-groups.index'),
            }, 'Browse groups'),
            '.',
          ])
        : [
            m('p.SGSettings-hint', 'Select a featured group to highlight on your profile.'),
            m('.SGSettings-list',
              groups.map((group) =>
                m('label.SGSettings-item', { key: group.id }, [
                  m('input[type=radio]', {
                    name:    'sgFeaturedGroup',
                    value:   group.id,
                    checked: String(this.featured) === String(group.id),
                    disabled: this.saving,
                    onchange: () => this.saveFeatured(group.id),
                  }),
                  group.imageUrl
                    ? m('img.SGSettings-groupImg', { src: group.imageUrl, alt: '' })
                    : m('span.SGSettings-groupInitial',
                        { style: `background:${group.color || '#4A90E2'}` },
                        (group.name || '?')[0].toUpperCase()),
                  m('span.SGSettings-groupName', group.name),
                  m(Link, {
                    class: 'SGSettings-groupLink',
                    href:  app.route('ernestdefoe-social-groups.show', { slug: group.slug }),
                  }, m('i.fas.fa-external-link-alt')),
                ])
              )
            ),
            this.featured
              ? m('button.Button.SGSettings-clearBtn', {
                  disabled: this.saving,
                  onclick:  () => this.saveFeatured(null),
                }, this.saving ? m('i.fas.fa-spinner.fa-spin') : 'Clear featured group')
              : null,
          ],
    ]);
  }
}

// ─────────────────────────────────────────────────────────────────────────────

app.initializers.add('ernestdefoe-social-groups', () => {
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

  // ── User card group badges ─────────────────────────────────────────────────
  extend(UserCard.prototype, 'profileItems', function (items) {
    const user = this.attrs.user;
    if (user && user.id() && items && typeof items.add === 'function') {
      items.add(
        'social-group-badges',
        m(UserGroupBadges, { userId: user.id() }),
        -10
      );
    }
  });

  // ── Settings: Social Groups section ───────────────────────────────────────
  extend(SettingsPage.prototype, 'settingsItems', function (items) {
    if (!app.session.user || typeof items.add !== 'function') return;
    items.add(
      'social-groups',
      m('.Form-group', [
        m('h4', [m('i.fas.fa-users'), ' Social Groups']),
        m(SocialGroupsSettings),
      ]),
      -90
    );
  });

  // ── Sidebar navigation link ────────────────────────────────────────────────
  extend(IndexSidebar.prototype, 'navItems', function (items) {
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
      90
    );
  });
});
