import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import GroupCard from './GroupCard';
import CreateGroupModal from './CreateGroupModal';

export default class GroupsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups      = null;
    this.loading     = true;
    this.searchValue = '';
    this.searchTimer = null;
    this.error       = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    document.title = `${app.translator.trans('ernestdefoe-social-groups.forum.groups.title')} — ${app.forum.attribute('title')}`;
    this.loadGroups();
  }

  loadGroups(q) {
    this.loading = true;
    m.redraw();

    const params = { 'page[limit]': 50 };
    if (q) params['filter[q]'] = q;

    app.store
      .find('social-groups', params)
      .then((groups) => {
        this.groups  = groups;
        this.loading = false;
        m.redraw();
      })
      .catch((err) => {
        this.error   = err;
        this.loading = false;
        m.redraw();
      });
  }

  onSearch(value) {
    this.searchValue = value;
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      this.loadGroups(value.trim() || undefined);
    }, 300);
    m.redraw();
  }

  view() {
    const canCreate = app.session.user && app.data.canCreateSocialGroup;

    return m('div.GroupsPage', [
      m('div.container', [
        // Header
        m('div.GroupsPage-header', [
          m('h2', app.translator.trans('ernestdefoe-social-groups.forum.groups.title')),
          m('div.GroupsPage-actions', [
            // Search bar
            m('input.GroupsPage-search', {
              type:        'text',
              placeholder: app.translator.trans('ernestdefoe-social-groups.forum.groups.search_placeholder'),
              value:       this.searchValue,
              oninput:     (e) => this.onSearch(e.target.value),
            }),

            // Create group button
            canCreate
              ? m(Button, {
                  class:   'Button Button--primary',
                  icon:    'fas fa-plus',
                  onclick: () => app.modal.show(CreateGroupModal, {
                    onCreated: (group) => {
                      if (!this.groups) this.groups = [];
                      this.groups = [group, ...this.groups];
                      m.redraw();
                    },
                  }),
                }, app.translator.trans('ernestdefoe-social-groups.forum.groups.create_button'))
              : null,
          ]),
        ]),

        // Content
        this.loading
          ? m('div.GroupsPage-loading', m(LoadingIndicator, { display: 'block' }))
          : this.error
          ? m('div.GroupsPage-error', 'Failed to load groups. Please try again.')
          : !this.groups || this.groups.length === 0
          ? m('div.GroupsPage-empty', [
              m('i.fas.fa-users'),
              m('h3', app.translator.trans('ernestdefoe-social-groups.forum.groups.empty_title')),
              m('p', app.translator.trans('ernestdefoe-social-groups.forum.groups.empty_text')),
              canCreate
                ? m(Button, {
                    class:   'Button Button--primary',
                    onclick: () => app.modal.show(CreateGroupModal, {
                      onCreated: (group) => {
                        this.groups = [group];
                        m.redraw();
                      },
                    }),
                  }, app.translator.trans('ernestdefoe-social-groups.forum.groups.create_button'))
                : null,
            ])
          : m('div.GroupsPage-grid',
              this.groups.map((group) => m(GroupCard, { group, key: group.id() }))
            ),
      ]),
    ]);
  }
}
