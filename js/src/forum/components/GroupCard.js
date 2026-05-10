import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';

const COLORS = ['#4A90E2', '#7b5ea7', '#e2574a', '#e2a24a', '#4ae28a', '#4ae2d4'];

export default class GroupCard extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.joining = false;
    this.isMember = this.attrs.group.isMember();
    this.memberCount = this.attrs.group.memberCount();
  }

  view() {
    const { group } = this.attrs;
    const slug = group.slug();
    const name = group.name() || '';
    const description = group.description() || '';
    const color = group.color() || '#4A90E2';
    const imageUrl = group.imageUrl();
    const bannerUrl = group.bannerUrl();
    const initial = name.charAt(0).toUpperCase();
    const href = app.route('ernestdefoe-social-groups.show', { slug });

    return m(
      'div.GroupCard',
      {
        onclick: (e) => {
          if (e.target.closest('.GroupCard-joinBtn')) return;
          m.route.set(href);
        },
      },
      [
        // Banner
        m(
          'div.GroupCard-banner',
          {
            style: bannerUrl
              ? `background-image: url('${bannerUrl}')`
              : `background: linear-gradient(135deg, ${color}, ${this.darken(color)})`,
          },
          [
            // Avatar
            m(
              'div.GroupCard-avatar',
              { style: imageUrl ? '' : `background: ${color}` },
              imageUrl ? m('img', { src: imageUrl, alt: name }) : initial
            ),
          ]
        ),

        // Body
        m('div.GroupCard-body', [
          m('div.GroupCard-name', name),
          m('div.GroupCard-meta', [
            m('i.fas.fa-users'),
            m('span', ` ${this.memberCount} ${this.memberCount === 1 ? 'member' : 'members'}`),
            group.isPrivate() ? m('span.GroupCard-private', [m('i.fas.fa-lock'), ' Private']) : null,
          ]),
          description
            ? m('div.GroupCard-description', description)
            : m('div.GroupCard-description.GroupCard-description--empty', ''),
          m('div.GroupCard-footer', [
            m(
              Link,
              { href, class: 'GroupCard-viewLink' },
              app.translator.trans('ernestdefoe-social-groups.forum.groups.view')
            ),
            app.session.user && !group.isCreator()
              ? m(
                  Button,
                  {
                    class: `GroupCard-joinBtn Button Button--${this.isMember ? 'default' : 'primary'}`,
                    loading: this.joining,
                    onclick: (e) => {
                      e.stopPropagation();
                      this.toggleMembership(group);
                    },
                  },
                  this.isMember
                    ? app.translator.trans('ernestdefoe-social-groups.forum.groups.leave')
                    : app.translator.trans('ernestdefoe-social-groups.forum.groups.join')
                )
              : null,
          ]),
        ]),
      ]
    );
  }

  toggleMembership(group) {
    if (this.joining) return;
    this.joining = true;

    const method = this.isMember ? 'DELETE' : 'POST';
    const url = `${app.forum.attribute('apiUrl')}/social-groups/${group.id()}/join`;

    fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken,
        Authorization: `Token ${app.session.token}`,
      },
    })
      .then((res) => res.json())
      .then((data) => {
        this.isMember = data.isMember;
        this.memberCount = data.memberCount;
        // Update the model's data so parent re-renders correctly
        group.pushData({ attributes: { isMember: data.isMember, memberCount: data.memberCount } });
        this.joining = false;
        m.redraw();
      })
      .catch(() => {
        this.joining = false;
        m.redraw();
      });
  }

  darken(hex) {
    // Simple darken by shifting to a complementary hue
    const map = {
      '#4A90E2': '#2c5f9e',
      '#7b5ea7': '#543d74',
      '#e2574a': '#9e3c32',
      '#e2a24a': '#9e7032',
      '#4ae28a': '#32a060',
      '#4ae2d4': '#3299b0',
    };
    return map[hex] || '#2c5f9e';
  }
}
