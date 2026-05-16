import { apiBase } from '../utils/api';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

export default class GroupHero extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.joining   = false;
    this.isPending = false; // local override after request
  }

  view() {
    const { group, onEdit, onJoin, onLeave } = this.attrs;
    if (!group) return null;

    const name        = group.name() || '';
    const color       = group.color() || '#4A90E2';
    const imageUrl    = group.imageUrl();
    const bannerUrl   = group.bannerUrl();
    const memberCount = group.memberCount() || 0;
    const initial     = name.charAt(0).toUpperCase();
    const isMember    = group.isMember();
    const isCreator   = group.isCreator();
    const canEdit     = group.canEdit();
    const isApproval  = group.membershipType() === 'approval';
    const isPending   = this.isPending || group.isPending();

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
                m('i.fa-solid.fa-users'),
                ' ',
                app.translator.trans('ernestdefoe-social-groups.forum.groups.members_count', { count: memberCount }),
              ]),
              group.isPrivate()
                ? m('span', [m('i.fa-solid.fa-lock'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.private')])
                : m('span', [m('i.fa-solid.fa-globe'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.public')]),
              isApproval
                ? m('span', [m('i.fa-solid.fa-user-check'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.approval_required')])
                : null,
              !group.isPrivate()
                ? m('a.GroupHero-rssLink', {
                    href:   `${app.forum.attribute('baseUrl')}/groups/${group.slug()}/feed.rss`,
                    target: '_blank',
                    rel:    'noopener noreferrer',
                    title:  app.translator.trans('ernestdefoe-social-groups.forum.group.rss_feed'),
                  }, m('i.fa-solid.fa-rss'))
                : null,
            ]),
          ]),

          // Action buttons
          m('div.GroupHero-actions', [
            canEdit
              ? m(Button, {
                  class: 'Button Button--default',
                  icon: 'fa-solid fa-pen-to-square',
                  onclick: onEdit,
                }, app.translator.trans('ernestdefoe-social-groups.forum.group.edit'))
              : null,

            app.session.user && !isCreator
              ? this.renderMembershipButton(group, isMember, isApproval, isPending, onJoin, onLeave)
              : null,
          ]),
        ]),
      ]),
    ]);
  }

  renderMembershipButton(group, isMember, isApproval, isPending, onJoin, onLeave) {
    if (isMember) {
      return m(Button, {
        class: 'Button Button--default',
        loading: this.joining,
        onclick: () => this.doLeave(group, onLeave),
      }, [m('i.fa-solid.fa-arrow-right-from-bracket'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.leave')]);
    }

    if (isApproval && isPending) {
      return m(Button, {
        class: 'Button Button--default GroupHero-pendingBtn',
        loading: this.joining,
        onclick: () => this.cancelRequest(group),
      }, [m('i.fa-solid.fa-clock'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.pending')]);
    }

    if (isApproval) {
      return m(Button, {
        class: 'Button Button--primary',
        loading: this.joining,
        onclick: () => this.doJoin(group, onJoin),
      }, [m('i.fa-solid.fa-user-plus'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.request_to_join')]);
    }

    return m(Button, {
      class: 'Button Button--primary',
      loading: this.joining,
      onclick: () => this.doJoin(group, onJoin),
    }, [m('i.fa-solid.fa-arrow-right-to-bracket'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.join')]);
  }

  doJoin(group, onJoin) {
    if (this.joining) return;
    this.joining = true;

    fetch(`${apiBase()}/social-groups/${group.id()}/join`, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken },
    })
      .then((res) => res.json())
      .then((data) => {
        this.joining = false;
        if (data.status === 'pending') {
          this.isPending = true;
          group.pushData({ attributes: { isPending: true } });
        } else {
          group.pushData({ attributes: { isMember: true, memberCount: data.memberCount, isPending: false } });
          if (onJoin) onJoin(data);
        }
        m.redraw();
      })
      .catch(() => { this.joining = false; m.redraw(); });
  }

  doLeave(group, onLeave) {
    if (this.joining) return;
    this.joining = true;

    fetch(`${apiBase()}/social-groups/${group.id()}/leave`, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken },
    })
      .then((res) => res.json())
      .then((data) => {
        this.joining = false;
        group.pushData({ attributes: { isMember: false, memberCount: data.memberCount } });
        if (onLeave) onLeave(data);
        m.redraw();
      })
      .catch(() => { this.joining = false; m.redraw(); });
  }

  cancelRequest(group) {
    if (this.joining) return;
    this.joining = true;

    fetch(`${apiBase()}/social-groups/${group.id()}/leave`, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken },
    })
      .then(() => {
        this.joining = false;
        this.isPending = false;
        group.pushData({ attributes: { isPending: false } });
        m.redraw();
      })
      .catch(() => { this.joining = false; m.redraw(); });
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
