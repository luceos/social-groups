import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import avatar from 'flarum/common/helpers/avatar';
import username from 'flarum/common/helpers/username';
import Link from 'flarum/common/components/Link';

export default class MemberList extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.members = [];
    this.loading = true;
    this.error = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadMembers();
  }

  loadMembers() {
    const { groupId } = this.attrs;

    app.store
      .find('social-groups', groupId, { include: 'users' })
      .then((group) => {
        const users = group.users ? group.users() : [];
        this.members = Array.isArray(users) ? users : (users ? [users] : []);
        this.loading = false;
        m.redraw();
      })
      .catch((err) => {
        this.error = err;
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return m('div.MemberList', [
      m(
        'div.MemberList-title',
        app.translator.trans('ernestdefoe-social-groups.forum.group.members_section')
      ),

      this.loading
        ? m('div.MemberList-loading', [m(LoadingIndicator, { size: 'small', display: 'block' })])
        : this.error
        ? m('div.MemberList-error', 'Could not load members.')
        : this.members.length === 0
        ? m('div.MemberList-empty', 'No members yet.')
        : m(
            'div.MemberList-grid',
            this.members.slice(0, 24).map((user) =>
              m(
                Link,
                {
                  href: app.route('user', { username: user.slug() || user.username() }),
                  class: 'MemberList-avatar',
                  'data-username': user.displayName(),
                  title: user.displayName(),
                },
                avatar(user, { className: 'MemberList-avatarImg' })
              )
            )
          ),

      this.members.length > 24
        ? m('div.MemberList-more', `+${this.members.length - 24} more`)
        : null,
    ]);
  }
}
