import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import GroupCard from './GroupCard';
import CreateGroupModal from './CreateGroupModal';

export default class GroupsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.allGroups   = null; // full list from server
    this.loading     = true;
    this.searchValue = '';
    this.error       = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    document.title = `${app.translator.trans('ernestdefoe-social-groups.forum.groups.title')} — ${app.forum.attribute('title')}`;
    this.loadGroups();
  }

  loadGroups() {
    this.loading = true;
    m.redraw();

    // Flarum 2's filter[*] param triggers the searcher system which throws
    // for resources that don't implement AbstractSearcher. We load all groups
    // once and filter client-side — instant feedback, no round-trip per keystroke.
    app.store
      .find('social-groups', { 'page[limit]': 200 })
      .then((groups) => {
        this.allGroups = groups;
        this.loading   = false;
        m.redraw();
      })
      .catch((err) => {
        this.error   = err;
        this.loading = false;
        m.redraw();
      });
  }

  get filteredGroups() {
    const q = this.searchValue.trim().toLowerCase();
    if (!q || !this.allGroups) return this.allGroups;
    return this.allGroups.filter((g) =>
      (g.name()        || '').toLowerCase().includes(q) ||
      (g.description() || '').toLowerCase().includes(q)
    );
  }

  onSearch(value) {
    this.searchValue = value;
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
                      this.allGroups = [group, ...(this.allGroups || [])];
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
          ? m('div.GroupsPage-error', app.translator.trans('ernestdefoe-social-groups.forum.groups.load_error'))
          : !this.filteredGroups || this.filteredGroups.length === 0
          ? m('div.GroupsPage-empty', [
              m('i.fas.fa-users'),
              m('h3', app.translator.trans(
                this.searchValue.trim()
                  ? 'ernestdefoe-social-groups.forum.groups.empty_search'
                  : 'ernestdefoe-social-groups.forum.groups.empty_title'
              )),
              !this.searchValue.trim()
                ? m('p', app.translator.trans('ernestdefoe-social-groups.forum.groups.empty_text'))
                : null,
              canCreate && !this.searchValue.trim()
                ? m(Button, {
                    class:   'Button Button--primary',
                    onclick: () => app.modal.show(CreateGroupModal, {
                      onCreated: (group) => {
                        this.allGroups = [group, ...(this.allGroups || [])];
                        m.redraw();
                      },
                    }),
                  }, app.translator.trans('ernestdefoe-social-groups.forum.groups.create_button'))
                : null,
            ])
          : m('div.GroupsPage-grid',
              this.filteredGroups.map((group) => m(GroupCard, { group, key: group.id() }))
            ),
      ]),
    ]);
  }
}
