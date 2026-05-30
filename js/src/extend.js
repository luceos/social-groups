import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';

export default [
  new Admin()
    .setting(() => ({
      setting: 'ernestdefoe-social-groups.max_image_bytes',
      type: 'number',
      min: 0,
      placeholder: 5242880,
      label: app.translator.trans('ernestdefoe-social-groups.admin.settings.max_image_bytes_label'),
      help: app.translator.trans('ernestdefoe-social-groups.admin.settings.max_image_bytes_help'),
    }))
    .permission(
      () => ({
        icon: 'fa-solid fa-users',
        label: app.translator.trans('ernestdefoe-social-groups.admin.permissions.create_groups'),
        permission: 'ernestdefoe-social-groups.create',
      }),
      'start',
      90
    )
    .permission(
      () => ({
        icon: 'fa-solid fa-shield',
        label: app.translator.trans('ernestdefoe-social-groups.admin.permissions.moderate_groups'),
        permission: 'ernestdefoe-social-groups.moderate',
      }),
      'moderate',
      90
    ),
];
