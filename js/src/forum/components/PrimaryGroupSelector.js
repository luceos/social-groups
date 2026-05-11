import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * Shown in the user account settings page so members can choose
 * which social group displays as their badge on posts.
 */
export default class PrimaryGroupSelector extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups   = null;
    this.loading  = true;
    this.saving   = false;
    this.selected = app.session.user
      ? String(app.session.user.attribute('sgPrimaryGroupId') || '')
      : '';
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadGroups();
  }

  loadGroups() {
    if (!app.session.user) { this.loading = false; return; }

    // Fetch all groups and keep only the ones the current user is a member of.
    // (The API resource sets isMember per-actor so client-side filtering is safe.)
    app.store
      .find('social-groups', { 'page[limit]': 100 })
      .then((groups) => {
        this.groups  = groups.filter((g) => g.isMember());
        this.loading = false;
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
    this.selected = groupId;

    app
      .request({
        method: 'POST',
        url:    `${app.apiUrl()}/social-groups/primary`,
        body:   { groupId: groupId || null },
      })
      .then((data) => {
        // Patch the local user model so the badge updates immediately
        if (app.session.user) {
          app.session.user.pushData({
            attributes: {
              sgPrimaryGroupName:  data.primaryGroupName  || null,
              sgPrimaryGroupColor: data.primaryGroupColor || null,
              sgPrimaryGroupSlug:  data.primaryGroupSlug  || null,
            },
          });
        }
        this.saving = false;
        m.redraw();
      })
      .catch(() => {
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
      m('label.SG-PrimaryGroupSelector-label', [
        m('strong', app.translator.trans('ernestdefoe-social-groups.forum.settings.primary_group_label')),
        m('span.helpText', app.translator.trans('ernestdefoe-social-groups.forum.settings.primary_group_help')),
      ]),

      groups.length === 0
        ? m('em.SG-PrimaryGroupSelector-empty',
            app.translator.trans('ernestdefoe-social-groups.forum.settings.primary_group_empty'))
        : m('div.SG-PrimaryGroupSelector-list', [
            // "None" option
            m(
              'label.SG-PrimaryGroupSelector-row',
              { class: !this.selected ? 'active' : '' },
              [
                m('input[type=radio]', {
                  name:     'sg-primary-group',
                  value:    '',
                  checked:  !this.selected,
                  disabled: this.saving,
                  onchange: () => this.save(null),
                }),
                m('span.SG-PrimaryGroupSelector-none',
                  app.translator.trans('ernestdefoe-social-groups.forum.settings.primary_group_none')),
              ]
            ),

            ...groups.map((group) =>
              m(
                'label.SG-PrimaryGroupSelector-row',
                { class: this.selected === String(group.id()) ? 'active' : '', key: group.id() },
                [
                  m('input[type=radio]', {
                    name:     'sg-primary-group',
                    value:    String(group.id()),
                    checked:  this.selected === String(group.id()),
                    disabled: this.saving,
                    onchange: () => this.save(group.id()),
                  }),
                  m('span.SG-PrimaryGroupSelector-pill', {
                    style: `background: ${group.color() || '#4A90E2'}`,
                  }),
                  m('span.SG-PrimaryGroupSelector-name', group.name()),
                ]
              )
            ),
          ]),

      this.saving ? m('span.SG-PrimaryGroupSelector-saving', 'Saving…') : null,
    ]);
  }
}
