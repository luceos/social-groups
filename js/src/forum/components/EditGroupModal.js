import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Stream from 'flarum/common/utils/Stream';
import ImageUploadButton from './ImageUploadButton';

const PRESET_COLORS = ['#4A90E2', '#7b5ea7', '#e2574a', '#e2a24a', '#4ae28a', '#e24a8a'];

export default class EditGroupModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    const { group } = this.attrs;

    this.name           = Stream(group.name() || '');
    this.description    = Stream(group.description() || '');
    this.color          = Stream(group.color() || PRESET_COLORS[0]);
    this.isPrivate      = Stream(group.isPrivate() || false);
    this.membershipType = Stream(group.membershipType() || 'open');
    this.submitting     = false;
    this.errors = {};
    this.deleting = false;

    this.avatarUrl = group.imageUrl() || null;
    this.bannerUrl = group.bannerUrl() || null;
  }

  className() {
    return 'EditGroupModal Modal--medium';
  }

  title() {
    return app.translator.trans('ernestdefoe-social-groups.forum.edit_modal.title');
  }

  content() {
    const { group } = this.attrs;

    return m('div.Modal-body', [
      // Name
      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.name_label')),
        m('input.FormControl', {
          type: 'text',
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.create_modal.name_placeholder'),
          value: this.name(),
          oninput: (e) => this.name(e.target.value),
          maxlength: 100,
          class: this.errors.name ? 'is-invalid' : '',
        }),
        this.errors.name ? m('div.help-block', this.errors.name) : null,
      ]),

      // Description
      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.description_label')),
        m('textarea.FormControl', {
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.create_modal.description_placeholder'),
          value: this.description(),
          oninput: (e) => this.description(e.target.value),
          rows: 4,
          maxlength: 2000,
        }),
      ]),

      // Color picker
      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.color_label')),
        m(
          'div.GroupModal-colorPicker',
          PRESET_COLORS.map((c) =>
            m('div.GroupModal-colorSwatch', {
              style: `background: ${c}`,
              class: this.color() === c ? 'active' : '',
              onclick: () => this.color(c),
              title: c,
            })
          )
        ),
      ]),

      // Private toggle
      m('div.Form-group', [
        m(Switch, {
          state: this.isPrivate(),
          onchange: (val) => this.isPrivate(val),
        }, app.translator.trans('ernestdefoe-social-groups.forum.create_modal.private_label')),
        m('p.helpText', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.private_help')),
      ]),

      // Membership type
      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.membership_type_label')),
        m('div.GroupModal-membershipType', [
          m('label.GroupModal-membershipOption', [
            m('input', {
              type: 'radio', name: 'edit_membership_type', value: 'open',
              checked: this.membershipType() === 'open',
              onchange: () => this.membershipType('open'),
            }),
            m('span', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.membership_type_open')),
          ]),
          m('label.GroupModal-membershipOption', [
            m('input', {
              type: 'radio', name: 'edit_membership_type', value: 'approval',
              checked: this.membershipType() === 'approval',
              onchange: () => this.membershipType('approval'),
            }),
            m('span', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.membership_type_approval')),
          ]),
        ]),
      ]),

      // Image uploads
      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.image_label')),
        m('div.GroupModal-uploadRow', [
          m(ImageUploadButton, {
            type: 'image',
            groupId: group.id(),
            currentUrl: this.avatarUrl,
            label: app.translator.trans('ernestdefoe-social-groups.forum.create_modal.image_label'),
            onUpload: (url) => {
              this.avatarUrl = url;
              group.pushData({ attributes: { imageUrl: url } });
              m.redraw();
            },
          }),
        ]),
      ]),

      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.banner_label')),
        m(ImageUploadButton, {
          type: 'banner',
          groupId: group.id(),
          currentUrl: this.bannerUrl,
          label: app.translator.trans('ernestdefoe-social-groups.forum.create_modal.banner_label'),
          onUpload: (url) => {
            this.bannerUrl = url;
            group.pushData({ attributes: { bannerUrl: url } });
            m.redraw();
          },
        }),
      ]),

      // Submit / Delete
      m('div.Form-group.EditGroupModal-actions', [
        m(
          Button,
          {
            class: 'Button Button--primary',
            loading: this.submitting,
            onclick: () => this.submit(),
          },
          app.translator.trans('ernestdefoe-social-groups.forum.edit_modal.submit')
        ),
        m(
          Button,
          {
            class: 'Button Button--danger',
            loading: this.deleting,
            onclick: () => this.deleteGroup(),
          },
          app.translator.trans('ernestdefoe-social-groups.forum.group.delete')
        ),
      ]),
    ]);
  }

  submit() {
    this.errors = {};
    const name = this.name().trim();

    if (!name) {
      this.errors.name = app.translator.trans('ernestdefoe-social-groups.forum.create_modal.name_required');
      m.redraw();
      return;
    }

    this.submitting = true;
    const { group } = this.attrs;

    group
      .save({
        name,
        description:    this.description().trim() || null,
        color:          this.color(),
        isPrivate:      this.isPrivate(),
        membershipType: this.membershipType(),
      })
      .then(() => {
        this.submitting = false;
        if (this.attrs.onSaved) {
          this.attrs.onSaved(group);
        }
        this.hide();
      })
      .catch((err) => {
        this.submitting = false;
        console.error('Edit group error:', err);
        m.redraw();
      });
  }

  deleteGroup() {
    if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.group.delete_confirm'))) {
      return;
    }

    this.deleting = true;
    const { group } = this.attrs;

    group
      .delete()
      .then(() => {
        this.deleting = false;
        this.hide();
        if (this.attrs.onDeleted) {
          this.attrs.onDeleted();
        }
        m.route.set(app.route('ernestdefoe-social-groups.index'));
      })
      .catch(() => {
        this.deleting = false;
        m.redraw();
      });
  }
}
