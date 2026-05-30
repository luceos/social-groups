import Model from 'flarum/common/Model';

/**
 * Store model for the `social-group-members` resource. The display name,
 * avatar, and slug are denormalised onto the member row by the resource,
 * so projection reads them as attributes; the `user` relation is still
 * included so the store caches the underlying User too.
 */
export default class SocialGroupMember extends Model {
  groupId() {
    return this.attribute('groupId');
  }

  userId() {
    return this.attribute('userId');
  }

  role() {
    return this.attribute('role');
  }

  displayName() {
    return this.attribute('displayName');
  }

  avatarUrl() {
    return this.attribute('avatarUrl');
  }

  slug() {
    return this.attribute('slug');
  }

  joinedAt() {
    return this.attribute('joinedAt', Model.transformDate);
  }

  canModerate() {
    return this.attribute('canModerate');
  }

  canRemove() {
    return this.attribute('canRemove');
  }

  user() {
    return Model.hasOne('user')(this);
  }
}
