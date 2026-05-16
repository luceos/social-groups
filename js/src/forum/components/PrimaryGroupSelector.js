import { apiBase } from '../utils/api';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * Shown in the user account settings page so members can choose
 * which social group displays as their badge on their profile.
 */
export default class PrimaryGroupSelector extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups   = null;
    this.loading  = true;
    this.saving   = false;
    this.selected = '';   // group id string, or '' for None
    this.error    = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadGroups();
  }

  loadGroups() {
    if (!app.session.user) { this.loading = false; return; }

    fetch(`${apiBase()}/sg-user-groups/${app.session.user.id()}`, {
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.ok ? r.json() : Promise.reject())
      .then((data) => {
        this.groups = data.data || [];
        // Restore saved selection from isPrimary flag returned by the API
        const primary = this.groups.find((g) => g.isPrimary);
        this.selected = primary ? String(primary.id) : '';
        this.loading  = false;
        m.redraw();
      })
      .catch(() => {
        this.groups  = [];
        this.loading = false;
        m.redraw();
      });
  }

  save(groupId) {
    this.saving   = true;
    this.selected = groupId ? String(groupId) : '';
    this.error    = null;
    m.redraw();

    fetch(`${apiBase()}/sg-primary-group`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ groupId: groupId || null }),
    })
      .then((r) => r.ok ? r.json() : r.json().then((e) => { throw new Error(e.error || 'Failed'); }))
      .then(() => {
        // Reflect new isPrimary state locally so the badge updates without a reload
        if (this.groups) {
          this.groups.forEach((g) => { g.isPrimary = String(g.id) === this.selected; });
        }
        this.saving = false;
        m.redraw();
      })
      .catch((err) => {
        this.error  = err.message || 'Failed to save.';
        this.saving = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) {
      return m('.SG-PrimaryGroupSelector', m(LoadingIndicator, { size: 'small' }));
    }

    const groups = this.groups || [];

    return m('.SG-PrimaryGroupSelector', [
      m('.SG-PrimaryGroupSelector-header', [
        m('strong', 'Profile Group Badge'),
        m('span.helpText', 'Choose which group displays on your profile card.'),
      ]),

      this.error ? m('.Alert.Alert--error', { style: 'margin:8px 0' }, this.error) : null,

      groups.length === 0
        ? m('em.SG-PrimaryGroupSelector-empty', "You haven't joined any groups yet.")
        : m('.SG-PrimaryGroupSelector-list', [
            // "None" option — key required so all siblings are consistently keyed
            m('label.SG-PrimaryGroupSelector-row', { key: 'none', class: !this.selected ? 'active' : '' }, [
              m('input[type=radio]', {
                name:     'sg-primary-group',
                value:    '',
                checked:  !this.selected,
                disabled: this.saving,
                onchange: () => this.save(null),
              }),
              m('span.SG-PrimaryGroupSelector-none', 'None'),
            ]),

            ...groups.map((group) =>
              m('label.SG-PrimaryGroupSelector-row', {
                class: this.selected === String(group.id) ? 'active' : '',
                key:   group.id,
              }, [
                m('input[type=radio]', {
                  name:     'sg-primary-group',
                  value:    String(group.id),
                  checked:  this.selected === String(group.id),
                  disabled: this.saving,
                  onchange: () => this.save(group.id),
                }),
                group.imageUrl
                  ? m('img.SG-PrimaryGroupSelector-img', {
                      src:   group.imageUrl,
                      alt:   '',
                      style: 'width:75px;height:75px;object-fit:cover;border-radius:6px;flex-shrink:0',
                    })
                  : m('span.SG-PrimaryGroupSelector-pill', {
                      style: `background:${group.color || '#4A90E2'}`,
                    }),
                m('span.SG-PrimaryGroupSelector-name', group.name),
              ])
            ),
          ]),

      this.saving ? m('span.SG-PrimaryGroupSelector-saving', [m('i.fas.fa-spinner.fa-spin'), ' Saving…']) : null,
    ]);
  }
}
