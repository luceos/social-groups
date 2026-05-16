import { apiBase } from '../utils/api';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import EditGroupModal from './EditGroupModal';

const COLORS = ['#4A90E2', '#7b5ea7', '#e2574a', '#e2a24a', '#4ae28a', '#4ae2d4'];

export default class GroupCard extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.joining     = false;
    this.isMember    = this.attrs.group.isMember();
    this.isPending   = this.attrs.group.isPending();
    this.memberCount = this.attrs.group.memberCount();
    this.kebabOpen   = false;

    this.closeKebab = () => {
      if (this.kebabOpen) {
        this.kebabOpen = false;
        m.redraw();
      }
    };
  }

  oncreate(vnode) {
    document.addEventListener('click', this.closeKebab);
  }

  onremove(vnode) {
    document.removeEventListener('click', this.closeKebab);
  }

  view() {
    const { group } = this.attrs;
    const slug        = group.slug();
    const name        = group.name() || '';
    const description = group.description() || '';
    const color       = group.color() || '#4A90E2';
    const imageUrl    = group.imageUrl();
    const bannerUrl   = group.bannerUrl();
    const initial     = name.charAt(0).toUpperCase();
    const href        = app.route('ernestdefoe-social-groups.show', { slug });
    const isApproval  = group.membershipType() === 'approval';
    const canEdit     = group.canEdit();

    return m(
      'div.GroupCard',
      {
        onclick: (e) => {
          if (e.target.closest('.GroupCard-joinBtn') || e.target.closest('.GroupCard-kebab')) return;
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
            m('i.fa-solid.fa-users'),
            m('span', ' ' + app.translator.trans('ernestdefoe-social-groups.forum.groups.members_count', { count: this.memberCount })),
            group.isPrivate() ? m('span.GroupCard-private', [m('i.fa-solid.fa-lock'), ' Private']) : null,
            isApproval ? m('span.GroupCard-approval', [m('i.fa-solid.fa-user-check'), ' Approval']) : null,
            group.isFeatured() ? m('span.GroupCard-featured', [m('i.fa-solid.fa-star'), ' Featured']) : null,
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
            m('div.GroupCard-footerRight', [
              app.session.user && !group.isCreator()
                ? this.renderJoinButton(group, isApproval)
                : null,
              canEdit ? this.renderKebabMenu(group) : null,
            ]),
          ]),
        ]),
      ]
    );
  }

  renderKebabMenu(group) {
    const canFeature = group.canFeature();

    return m('div.GroupCard-kebab', [
      m('button.GroupCard-kebabBtn', {
        type: 'button',
        title: app.translator.trans('ernestdefoe-social-groups.forum.group.options'),
        onclick: (e) => {
          e.stopPropagation();
          this.kebabOpen = !this.kebabOpen;
          m.redraw();
        },
      }, m('i.fa-solid.fa-ellipsis-vertical')),

      this.kebabOpen
        ? m('div.GroupCard-kebabMenu', [
            canFeature
              ? m('button.GroupCard-kebabItem', {
                  type: 'button',
                  onclick: (e) => {
                    e.stopPropagation();
                    this.kebabOpen = false;
                    if (this.attrs.onToggleFeature) this.attrs.onToggleFeature();
                  },
                }, group.isFeatured()
                    ? [m('i.fa-solid.fa-star'), ' Unfeature group']
                    : [m('i.fa-regular.fa-star'), ' Feature group'])
              : null,
            m('button.GroupCard-kebabItem', {
              type: 'button',
              onclick: (e) => {
                e.stopPropagation();
                this.kebabOpen = false;
                app.modal.show(EditGroupModal, {
                  group,
                  onSaved: () => m.redraw(),
                  onDeleted: () => m.route.set(app.route('ernestdefoe-social-groups.index')),
                });
              },
            }, [m('i.fa-solid.fa-pencil'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.group.edit')]),

            m('button.GroupCard-kebabItem.GroupCard-kebabItem--danger', {
              type: 'button',
              onclick: (e) => {
                e.stopPropagation();
                this.kebabOpen = false;
                if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.group.delete_confirm'))) return;
                group.delete().then(() => {
                  if (this.attrs.onGroupDeleted) {
                    this.attrs.onGroupDeleted(group);
                  } else {
                    m.route.set(app.route('ernestdefoe-social-groups.index'));
                  }
                });
              },
            }, [m('i.fa-solid.fa-trash'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.group.delete')]),
          ])
        : null,
    ]);
  }

  renderJoinButton(group, isApproval) {
    if (this.isMember) {
      return m(
        Button,
        {
          class: 'GroupCard-joinBtn Button Button--default',
          loading: this.joining,
          onclick: (e) => { e.stopPropagation(); this.toggleMembership(group, isApproval); },
        },
        app.translator.trans('ernestdefoe-social-groups.forum.groups.leave')
      );
    }

    if (isApproval && this.isPending) {
      return m(
        Button,
        {
          class: 'GroupCard-joinBtn Button Button--default GroupCard-joinBtn--pending',
          loading: this.joining,
          onclick: (e) => { e.stopPropagation(); this.cancelRequest(group); },
        },
        [m('i.fa-solid.fa-clock'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.pending')]
      );
    }

    return m(
      Button,
      {
        class: 'GroupCard-joinBtn Button Button--primary',
        loading: this.joining,
        onclick: (e) => { e.stopPropagation(); this.toggleMembership(group, isApproval); },
      },
      isApproval
        ? [m('i.fa-solid.fa-user-plus'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.request_to_join')]
        : app.translator.trans('ernestdefoe-social-groups.forum.groups.join')
    );
  }

  toggleMembership(group, isApproval) {
    if (this.joining) return;
    this.joining = true;

    const url = this.isMember
      ? `${apiBase()}/social-groups/${group.id()}/leave`
      : `${apiBase()}/social-groups/${group.id()}/join`;

    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken,
      },
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.status === 'pending') {
          this.isPending = true;
          group.pushData({ attributes: { isPending: true } });
        } else {
          this.isMember    = data.isMember ?? !this.isMember;
          this.isPending   = false;
          this.memberCount = data.memberCount ?? this.memberCount;
          group.pushData({ attributes: { isMember: this.isMember, memberCount: this.memberCount, isPending: false } });
        }
        this.joining = false;
        m.redraw();
      })
      .catch(() => {
        this.joining = false;
        m.redraw();
      });
  }

  cancelRequest(group) {
    if (this.joining) return;
    this.joining = true;

    fetch(`${apiBase()}/social-groups/${group.id()}/leave`, {
      method: 'POST',
      headers: { 'X-CSRF-Token': app.session.csrfToken },
    })
      .then(() => {
        this.isPending = false;
        group.pushData({ attributes: { isPending: false } });
        this.joining = false;
        m.redraw();
      })
      .catch(() => {
        this.joining = false;
        m.redraw();
      });
  }

  darken(hex) {
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
