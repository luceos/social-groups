import Model from 'flarum/common/Model';

/**
 * Store model for the `social-group-posts` resource. Registered in
 * forum.js so thread posts (and their included user/discussion relations)
 * flow through app.store rather than ad-hoc app.request() fetches.
 */
export default class SocialGroupPost extends Model {
  discussionId() {
    return this.attribute('discussionId');
  }

  groupId() {
    return this.attribute('groupId');
  }

  content() {
    return this.attribute('content');
  }

  contentParsed() {
    return this.attribute('contentParsed');
  }

  reactions() {
    return this.attribute('reactions');
  }

  actorReaction() {
    return this.attribute('actorReaction');
  }

  linkPreview() {
    return this.attribute('linkPreview');
  }

  parentPostId() {
    return this.attribute('parentPostId');
  }

  isPinned() {
    return this.attribute('isPinned');
  }

  canEdit() {
    return this.attribute('canEdit');
  }

  canDelete() {
    return this.attribute('canDelete');
  }

  canPin() {
    return this.attribute('canPin');
  }

  createdAt() {
    return this.attribute('createdAt', Model.transformDate);
  }

  updatedAt() {
    return this.attribute('updatedAt', Model.transformDate);
  }

  user() {
    return Model.hasOne('user')(this);
  }

  discussion() {
    return Model.hasOne('discussion')(this);
  }
}
