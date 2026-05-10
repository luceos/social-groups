import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import GroupCard from './GroupCard';
import CreateGroupModal from './CreateGroupModal';

export default class GroupsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups = null;
    this.loading = true;
    this.searchValue = '';
    this.error = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    document.title = `${app.translator.trans('ernestdefoe-social-groups.forum.groups.title')} — ${app.forum.attribute('title')}`;
    this.loadGroups();
  }

  loadGroups() {
    this.loading = true;
    app.store
      .find('social-groups', { sort: '-createdAt', 'page[limit]': 50 })
      .then((groups) => {
        this.groups = groups;
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
    const canCreate = app.session.user && app.forum.attribute('canCreateSocialGroup');

    const filtered = this.groups
      ? this.groups.filter((g) => {
          if (!this.searchValue) return true;
          const q = this.searchValue.toLowerCase();
          return (
            (g.name() || '').toLowerCase().includes(q) ||
            (g.description() || '').toLowerCase().includes(q)
          );
        })
      : [];

    return m('div.GroupsPage', [
      m('div.container', [
        // Header
        m('div.GroupsPage-header', [
          m('h2', app.translator.trans('ernestdefoe-social-groups.forum.groups.title')),
          m('div.GroupsPage-actions', [
            // Search bar (only if groups exist)
            this.groups && this.groups.length > 0
              ? m('input.GroupsPage-search', {
                  type: 'text',
                  placeholder: app.translator.trans('ernestdefoe-social-groups.forum.groups.search_placeholder'),
                  value: this.searchValue,
                  oninput: (e) => {
                    this.searchValue = e.target.value;
                    m.redraw();
                  },
                })
              : null,

            // Create group button
            canCreate
              ? m(
                  Button,
                  {
                    class: 'Button Button--primary',
                    icon: 'fas fa-plus',
                    onclick: () =>
                      app.modal.show(CreateGroupModal, {
                        onCreated: (group) => {
                          if (!this.groups) this.groups = [];
                          this.groups = [group, ...this.groups];
                          m.redraw();
                        },
                      }),
                  },
                  [m('i.fas.fa-plus'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.create_button')]
                )
              : null,
          ]),
        ]),

        // Content
        this.loading
          ? m('div.GroupsPage-loading', [m(LoadingIndicator, { display: 'block' })])
          : this.error
          ? m('div.GroupsPage-error', 'Failed to load groups. Please try again.')
          : filtered.length === 0
          ? m('div.GroupsPage-empty', [
              m('i.fas.fa-users'),
              m('h3', app.translator.trans('ernestdefoe-social-groups.forum.groups.empty_title')),
              m('p', app.translator.trans('ernestdefoe-social-groups.forum.groups.empty_text')),
              canCreate
                ? m(
                    Button,
                    {
                      class: 'Button Button--primary',
                      onclick: () =>
                        app.modal.show(CreateGroupModal, {
                          onCreated: (group) => {
                            this.groups = [group];
                            m.redraw();
                          },
                        }),
                    },
                    app.translator.trans('ernestdefoe-social-groups.forum.groups.create_button')
                  )
                : null,
            ])
          : m('div.GroupsPage-grid', filtered.map((group) => m(GroupCard, { group, key: group.id() }))),
      ]),
    ]);
  }
}
