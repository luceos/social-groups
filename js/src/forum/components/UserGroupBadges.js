import { apiGet } from '../utils/api';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';

export default class UserGroupBadges extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups  = null;
    this.loading = true;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();
  }

  onupdate(vnode) {
    if (vnode.attrs.userId !== this.attrs.userId) {
      this.groups  = null;
      this.loading = true;
      this.load();
    }
  }

  load() {
    apiGet(`/sg-user-groups/${this.attrs.userId}`)
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

  view() {
    if (this.loading || !this.groups || this.groups.length === 0) return m('span');

    // Show only the primary group if one has been selected; otherwise show all.
    const primary = this.groups.find((g) => g.isPrimary);
    const display = primary ? [primary] : this.groups;

    return m('.UserGroupBadges', [
      m('.UserGroupBadges-label', [m('i.fa-solid.fa-users'), ' Groups']),
      m('.UserGroupBadges-list',
        display.map((group) =>
          m(Link, {
            key:   group.id,
            href:  app.route('ernestdefoe-social-groups.show', { slug: group.slug }),
            class: 'UserGroupBadges-badge',
            title: group.name,
          }, [
            group.imageUrl
              ? m('img.UserGroupBadges-img', { src: group.imageUrl, alt: '' })
              : m('span.UserGroupBadges-initial',
                  { style: `background:${group.color || '#4A90E2'}` },
                  (group.name || '?')[0].toUpperCase()),
            m('span.UserGroupBadges-name', group.name),
          ])
        )
      ),
    ]);
  }
}
