import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';

export default [
  new Admin()
    .permission(
      () => ({
        icon: 'fas fa-users',
        label: app.translator.trans('ernestdefoe-social-groups.admin.permissions.create_groups'),
        permission: 'ernestdefoe-social-groups.create',
      }),
      'start',
      90
    ),
];
