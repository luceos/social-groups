import Model from 'flarum/common/Model';

/**
 * Store model for the `social-group-join-requests` resource. The
 * requester's display name + avatar are denormalised onto the row, so
 * projection reads them as attributes; `user` is included so the store
 * also caches the underlying User.
 */
export default class SocialGroupJoinRequest extends Model {
  groupId() {
    return this.attribute('groupId');
  }

  userId() {
    return this.attribute('userId');
  }

  displayName() {
    return this.attribute('displayName');
  }

  avatarUrl() {
    return this.attribute('avatarUrl');
  }

  createdAt() {
    return this.attribute('createdAt', Model.transformDate);
  }

  user() {
    return Model.hasOne('user').call(this);
  }
}
