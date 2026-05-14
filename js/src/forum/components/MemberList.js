import { apiBase } from '../utils/api';
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
    this.actioning = {}; // userId → 'promote'|'demote'|'remove'
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadMembers();
  }

  loadMembers() {
    const { groupId } = this.attrs;
    this.loading = true;

    fetch(`${apiBase()}/social-groups/${groupId}/members`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
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

    fetch(`${apiBase()}/social-groups/${groupId}/members/${member.userId}/promote`, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((data) => {
        const idx = this.members.findIndex((m) => m.userId === member.userId);
        if (idx !== -1) this.members[idx] = { ...this.members[idx], role: data.role || 'moderator' };
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

    fetch(`${apiBase()}/social-groups/${groupId}/members/${member.userId}/demote`, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((data) => {
        const idx = this.members.findIndex((m) => m.userId === member.userId);
        if (idx !== -1) this.members[idx] = { ...this.members[idx], role: data.role || 'member' };
        delete this.actioning[member.userId];
        m.redraw();
      })
      .catch(() => {
        delete this.actioning[member.userId];
        m.redraw();
      });
  }

  removeMember(member) {
    if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.group.remove_member_confirm', { displayName: member.displayName }))) return;

    this.actioning[member.userId] = 'remove';
    m.redraw();

    fetch(`${apiBase()}/social-groups/${this.attrs.groupId}/members/${member.userId}`, {
      method:      'DELETE',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then(() => {
        this.members = this.members.filter((m) => m.userId !== member.userId);
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
        ? m('div.MemberList-error', app.translator.trans('ernestdefoe-social-groups.forum.group.members_load_error'))
        : this.members.length === 0
        ? m('div.MemberList-empty', app.translator.trans('ernestdefoe-social-groups.forum.group.members_empty'))
        : m('div.MemberList-list',
            this.members.map((member) => this.renderMember(member, isCreator))
          ),

      this.members.length > 0
        ? m('div.MemberList-count', app.translator.trans('ernestdefoe-social-groups.forum.groups.members_count', { count: this.members.length }))
        : null,
    ]);
  }

  renderMember(member, isCreator) {
    const profileUrl  = app.route('user', { username: member.slug });
    const acting      = this.actioning[member.userId];
    const canModerate = isCreator && member.role !== 'creator';
    const canRemove   = member.canRemove;

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

      // Moderation buttons
      canModerate || canRemove
        ? m('div.MemberList-actions', [
            canModerate
              ? (member.role === 'member'
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
                    }, m('i.fas.fa-user')))
              : null,
            canRemove
              ? m(Button, {
                  class:       'Button Button--sm MemberList-removeBtn',
                  'aria-label': 'Remove member',
                  title:        'Remove member',
                  loading:      acting === 'remove',
                  disabled:     !!acting,
                  onclick:      () => this.removeMember(member),
                }, m('i.fas.fa-user-times'))
              : null,
          ])
        : null,
    ]);
  }
}
