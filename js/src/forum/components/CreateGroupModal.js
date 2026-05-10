import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Stream from 'flarum/common/utils/Stream';
import ImageUploadButton from './ImageUploadButton';

const PRESET_COLORS = ['#4A90E2', '#7b5ea7', '#e2574a', '#e2a24a', '#4ae28a', '#e24a8a'];

export default class CreateGroupModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.name = Stream('');
    this.description = Stream('');
    this.color = Stream(PRESET_COLORS[0]);
    this.isPrivate = Stream(false);
    this.submitting = false;
    this.errors = {};

    // Refs to image upload buttons for deferred upload
    this.avatarButtonRef = null;
    this.bannerButtonRef = null;

    // Pending file data
    this.pendingAvatarFile = null;
    this.pendingBannerFile = null;
    this.avatarPreviewUrl = null;
    this.bannerPreviewUrl = null;
  }

  className() {
    return 'CreateGroupModal Modal--medium';
  }

  title() {
    return app.translator.trans('ernestdefoe-social-groups.forum.create_modal.title');
  }

  content() {
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

      // Image uploads
      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.image_label')),
        m('div.GroupModal-uploadRow', [
          m(ImageUploadButton, {
            type: 'image',
            groupId: null,
            currentUrl: this.avatarPreviewUrl,
            label: app.translator.trans('ernestdefoe-social-groups.forum.create_modal.image_label'),
            onFileSelected: (file, previewUrl) => {
              this.pendingAvatarFile = file;
              this.avatarPreviewUrl = previewUrl;
              m.redraw();
            },
            onUpload: (url) => {
              this.avatarPreviewUrl = url;
            },
          }),
        ]),
      ]),

      m('div.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.create_modal.banner_label')),
        m(ImageUploadButton, {
          type: 'banner',
          groupId: null,
          currentUrl: this.bannerPreviewUrl,
          label: app.translator.trans('ernestdefoe-social-groups.forum.create_modal.banner_label'),
          onFileSelected: (file, previewUrl) => {
            this.pendingBannerFile = file;
            this.bannerPreviewUrl = previewUrl;
            m.redraw();
          },
          onUpload: (url) => {
            this.bannerPreviewUrl = url;
          },
        }),
      ]),

      // Submit
      m('div.Form-group', [
        m(
          Button,
          {
            class: 'Button Button--primary Button--block',
            loading: this.submitting,
            onclick: () => this.submit(),
          },
          app.translator.trans('ernestdefoe-social-groups.forum.create_modal.submit')
        ),
      ]),
    ]);
  }

  submit() {
    this.errors = {};
    const name = this.name().trim();

    if (!name) {
      this.errors.name = 'Group name is required.';
      m.redraw();
      return;
    }

    this.submitting = true;

    app.store
      .createRecord('social-groups')
      .save({
        name,
        description: this.description().trim() || null,
        color: this.color(),
        isPrivate: this.isPrivate(),
      })
      .then((group) => {
        // Upload pending images after the group is created
        const uploads = [];

        if (this.pendingAvatarFile) {
          uploads.push(this.uploadFile(this.pendingAvatarFile, group.id(), 'image'));
        }
        if (this.pendingBannerFile) {
          uploads.push(this.uploadFile(this.pendingBannerFile, group.id(), 'banner'));
        }

        return Promise.all(uploads).then((results) => {
          // Update group with image URLs if uploads succeeded
          const updateData = {};
          results.forEach(({ type, url }) => {
            if (type === 'image') updateData.imageUrl = url;
            if (type === 'banner') updateData.bannerUrl = url;
          });

          if (Object.keys(updateData).length > 0) {
            group.pushData({ attributes: updateData });
          }

          return group;
        });
      })
      .then((group) => {
        this.submitting = false;
        // Pass newly created group back to parent via callback
        if (this.attrs.onCreated) {
          this.attrs.onCreated(group);
        }
        this.hide();
      })
      .catch((err) => {
        this.submitting = false;
        console.error('Create group error:', err);
        m.redraw();
      });
  }

  uploadFile(file, groupId, type) {
    const formData = new FormData();
    formData.append('file', file);
    const endpoint = type === 'banner' ? 'banner' : 'image';

    return fetch(`${app.forum.attribute('apiUrl')}/social-groups/${groupId}/${endpoint}`, {
      method: 'POST',
      headers: {
        'X-CSRF-Token': app.session.csrfToken,
        Authorization: `Token ${app.session.token}`,
      },
      body: formData,
    })
      .then((res) => res.json())
      .then((data) => ({ type, url: data.url }));
  }
}
