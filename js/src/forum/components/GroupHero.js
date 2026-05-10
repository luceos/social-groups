import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

export default class GroupHero extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.joining = false;
  }

  view() {
    const { group, onEdit, onJoin, onLeave } = this.attrs;
    if (!group) return null;

    const name = group.name() || '';
    const color = group.color() || '#4A90E2';
    const imageUrl = group.imageUrl();
    const bannerUrl = group.bannerUrl();
    const memberCount = group.memberCount() || 0;
    const initial = name.charAt(0).toUpperCase();
    const isMember = group.isMember();
    const isCreator = group.isCreator();
    const canEdit = group.canEdit();

    return m('div.GroupHero', [
      // Full-width banner
      m('div.GroupHero-banner', {
        style: bannerUrl
          ? `background-image: url('${bannerUrl}')`
          : `background: linear-gradient(135deg, ${color}, ${this.complementaryColor(color)})`,
      }),

      // Info bar beneath banner
      m('div.GroupHero-info', [
        m('div.GroupHero-info-inner', [
          // Group avatar (overlaps banner)
          m(
            'div.GroupHero-avatar',
            { style: imageUrl ? '' : `background: ${color}` },
            imageUrl ? m('img', { src: imageUrl, alt: name }) : initial
          ),

          // Name + meta
          m('div.GroupHero-text', [
            m('h1.GroupHero-name', name),
            m('div.GroupHero-meta', [
              m('span', [
                m('i.fas.fa-users'),
                ` ${memberCount} ${memberCount === 1 ? 'member' : 'members'}`,
              ]),
              group.isPrivate()
                ? m('span', [m('i.fas.fa-lock'), ' Private'])
                : m('span', [m('i.fas.fa-globe'), ' Public']),
            ]),
          ]),

          // Action buttons
          m('div.GroupHero-actions', [
            canEdit
              ? m(
                  Button,
                  {
                    class: 'Button Button--default',
                    icon: 'fas fa-edit',
                    onclick: onEdit,
                  },
                  app.translator.trans('ernestdefoe-social-groups.forum.group.edit')
                )
              : null,

            app.session.user && !isCreator
              ? m(
                  Button,
                  {
                    class: `Button ${isMember ? 'Button--default' : 'Button--primary'}`,
                    loading: this.joining,
                    onclick: () => this.toggleMembership(group, onJoin, onLeave),
                  },
                  isMember
                    ? [m('i.fas.fa-sign-out-alt'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.leave')]
                    : [m('i.fas.fa-sign-in-alt'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.join')]
                )
              : null,
          ]),
        ]),
      ]),
    ]);
  }

  toggleMembership(group, onJoin, onLeave) {
    if (this.joining) return;
    this.joining = true;

    const isMember = group.isMember();
    const method = isMember ? 'DELETE' : 'POST';
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
        group.pushData({
          attributes: {
            isMember: data.isMember,
            memberCount: data.memberCount,
          },
        });
        this.joining = false;
        if (data.isMember && onJoin) onJoin(data);
        if (!data.isMember && onLeave) onLeave(data);
        m.redraw();
      })
      .catch(() => {
        this.joining = false;
        m.redraw();
      });
  }

  complementaryColor(hex) {
    const map = {
      '#4A90E2': '#7b5ea7',
      '#7b5ea7': '#4A90E2',
      '#e2574a': '#7b5ea7',
      '#e2a24a': '#e2574a',
      '#4ae28a': '#4A90E2',
      '#4ae2d4': '#4ae28a',
    };
    return map[hex] || '#7b5ea7';
  }
}
