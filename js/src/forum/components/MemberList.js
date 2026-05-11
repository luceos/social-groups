import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import InviteUserModal from './InviteUserModal';

export default class MemberList extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.members   = [];
    this.loading   = true;
    this.error     = null;
    this.actioning = {}; // userId → 'promote'|'demote'
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadMembers();
  }

  loadMembers() {
    const { groupId } = this.attrs;
    this.loading = true;

    fetch(`${app.forum.attribute('apiUrl')}/social-groups/${groupId}/members`, {
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.json())
      .then((data) => {
        this.members = data.data || [];
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.error   = true;
        this.loading = false;
        m.redraw();
      });
  }

  promote(member) {
    this.actioning[member.userId] = 'promote';
    const { groupId } = this.attrs;

    fetch(`${app.forum.attribute('apiUrl')}/social-groups/${groupId}/members/${member.userId}/promote`, {
      method:  'POST',
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then(() => {
        const idx = this.members.findIndex((m) => m.userId === member.userId);
        if (idx !== -1) this.members[idx] = { ...this.members[idx], role: 'admin' };
        delete this.actioning[member.userId];
        m.redraw();
      })
      .catch(() => {
        delete this.actioning[member.userId];
        m.redraw();
      });
  }

  demote(member) {
    this.actioning[member.userId] = 'demote';
    const { groupId } = this.attrs;

    fetch(`${app.forum.attribute('apiUrl')}/social-groups/${groupId}/members/${member.userId}/demote`, {
      method:  'POST',
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then(() => {
        const idx = this.members.findIndex((m) => m.userId === member.userId);
        if (idx !== -1) this.members[idx] = { ...this.members[idx], role: 'member' };
        delete this.actioning[member.userId];
        m.redraw();
      })
      .catch(() => {
        delete this.actioning[member.userId];
        m.redraw();
      });
  }

  openInvite() {
    app.modal.show(InviteUserModal, {
      groupId:   this.attrs.groupId,
      onInvited: (data) => {
        this.members.push({
          userId:      data.userId,
          displayName: data.displayName,
          avatarUrl:   data.avatarUrl,
          slug:        data.slug,
          role:        'member',
          canModerate: true,
        });
        m.redraw();
      },
    });
  }

  view() {
    const { isCreator } = this.attrs;

    return m('div.MemberList', [
      m('div.MemberList-header', [
        m('div.MemberList-title',
          app.translator.trans('ernestdefoe-social-groups.forum.group.members_section')),
        isCreator
          ? m(Button, {
              class:   'Button Button--sm Button--primary MemberList-inviteBtn',
              'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.invite.title'),
              onclick: () => this.openInvite(),
            }, [m('i.fas.fa-user-plus'), ' ',
                app.translator.trans('ernestdefoe-social-groups.forum.invite.button')])
          : null,
      ]),

      this.loading
        ? m('div.MemberList-loading', m(LoadingIndicator, { size: 'small', display: 'block' }))
        : this.error
        ? m('div.MemberList-error', 'Could not load members.')
        : this.members.length === 0
        ? m('div.MemberList-empty', 'No members yet.')
        : m('div.MemberList-list',
            this.members.map((member) => this.renderMember(member, isCreator))
          ),

      this.members.length > 0
        ? m('div.MemberList-count', `${this.members.length} member${this.members.length === 1 ? '' : 's'}`)
        : null,
    ]);
  }

  renderMember(member, isCreator) {
    const profileUrl = app.route('user', { username: member.slug });
    const acting     = this.actioning[member.userId];
    const canModerate = isCreator && member.role !== 'creator';

    return m('div.MemberList-row', { key: member.userId }, [
      // Avatar + name
      m(Link, { href: profileUrl, class: 'MemberList-userLink' }, [
        m('div.MemberList-avatar', [
          member.avatarUrl
            ? m('img', { src: member.avatarUrl, alt: member.displayName })
            : m('span.MemberList-avatarInitial', (member.displayName || '?')[0].toUpperCase()),
        ]),
        m('div.MemberList-info', [
          m('span.MemberList-name', member.displayName),
          member.role !== 'member'
            ? m('span.MemberList-role', {
                class: `MemberList-role--${member.role}`,
              }, member.role === 'creator'
                ? app.translator.trans('ernestdefoe-social-groups.forum.group.role_creator')
                : app.translator.trans('ernestdefoe-social-groups.forum.group.role_admin'))
            : null,
        ]),
      ]),

      // Moderation buttons (creator only, non-creator members)
      canModerate
        ? m('div.MemberList-actions', [
            member.role === 'member'
              ? m(Button, {
                  class:       'Button Button--sm MemberList-promoteBtn',
                  'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.group.promote_member'),
                  loading:     acting === 'promote',
                  disabled:    !!acting,
                  onclick:     () => this.promote(member),
                }, m('i.fas.fa-shield-alt'))
              : m(Button, {
                  class:       'Button Button--sm MemberList-demoteBtn',
                  'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.group.demote_member'),
                  loading:     acting === 'demote',
                  disabled:    !!acting,
                  onclick:     () => this.demote(member),
                }, m('i.fas.fa-user')),
          ])
        : null,
    ]);
  }
}
