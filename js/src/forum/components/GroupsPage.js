import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import GroupCard from './GroupCard';
import CreateGroupModal from './CreateGroupModal';
import { apiPatch } from '../utils/api';

export default class GroupsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.allGroups   = null; // current result set from server
    this.loading     = true;
    this.searchValue = '';
    this.error       = null;
    this._searchTimer = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    document.title = `${app.translator.trans('ernestdefoe-social-groups.forum.groups.title')} — ${app.forum.attribute('title')}`;
    this.loadGroups();
  }

  onremove(vnode) {
    if (this._searchTimer) clearTimeout(this._searchTimer);
    super.onremove(vnode);
  }

  loadGroups() {
    this.loading = true;
    m.redraw();

    // Server-side search: SocialGroupResource.scope() honors filter[q]
    // and pushes a LIKE into SQL. The previous approach (page[limit]=200
    // + JS filter) silently dropped matches past row 200 and shipped the
    // whole list on every page load.
    const params = { 'page[limit]': 60 };
    const q = this.searchValue.trim();
    if (q) params['filter[q]'] = q;

    app.store
      .find('social-groups', params)
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
    // Filtering happens server-side now — just expose what we loaded.
    return this.allGroups;
  }

  toggleFeature(group) {
    const was = group.isFeatured();
    group.pushData({ attributes: { isFeatured: !was } });
    m.redraw();

    apiPatch(`/social-groups/${group.id()}/feature`)
      .then((data) => {
        group.pushData({ attributes: { isFeatured: data.isFeatured } });
        m.redraw();
      })
      .catch(() => {
        group.pushData({ attributes: { isFeatured: was } });
        m.redraw();
      });
  }

  onSearch(value) {
    this.searchValue = value;
    m.redraw();

    // Debounce the server request — 280 ms is short enough to feel
    // live while still merging keystrokes into a single query.
    if (this._searchTimer) clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => {
      this.loadGroups();
    }, 280);
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
                  icon:    'fa-solid fa-plus',
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
              m('i.fa-solid.fa-users'),
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
          : (() => {
              const groups   = this.filteredGroups;
              const featured = groups.filter((g) => g.isFeatured());
              const regular  = groups.filter((g) => !g.isFeatured());
              return [
                featured.length
                  ? m('div.GroupsPage-featured', [
                      m('h3.GroupsPage-featuredHeading', [m('i.fa-solid.fa-star'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.groups.featured_heading')]),
                      m('div.GroupsPage-grid.GroupsPage-grid--featured',
                        featured.map((group) => m(GroupCard, {
                          group,
                          key:           group.id(),
                          onToggleFeature: () => this.toggleFeature(group),
                        }))
                      ),
                    ])
                  : null,
                regular.length
                  ? m('div.GroupsPage-grid',
                      regular.map((group) => m(GroupCard, {
                        group,
                        key:           group.id(),
                        onToggleFeature: () => this.toggleFeature(group),
                      }))
                    )
                  : null,
              ];
            })(),
      ]),
    ]);
  }
}
