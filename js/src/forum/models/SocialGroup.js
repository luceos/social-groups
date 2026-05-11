import Model from 'flarum/common/Model';

export default class SocialGroup extends Model {
  name() {
    return this.attribute('name');
  }

  slug() {
    return this.attribute('slug');
  }

  description() {
    return this.attribute('description');
  }

  color() {
    return this.attribute('color');
  }

  imageUrl() {
    return this.attribute('imageUrl');
  }

  bannerUrl() {
    return this.attribute('bannerUrl');
  }

  isPrivate() {
    return this.attribute('isPrivate');
  }

  memberCount() {
    return this.attribute('memberCount');
  }

  createdAt() {
    return this.attribute('createdAt', Model.transformDate);
  }

  canEdit() {
    return this.attribute('canEdit');
  }

  isMember() {
    return this.attribute('isMember');
  }

  isCreator() {
    return this.attribute('isCreator');
  }

  membershipType() {
    return this.attribute('membershipType') || 'open';
  }

  isPending() {
    return this.attribute('isPending') || false;
  }

  pendingRequestCount() {
    return this.attribute('pendingRequestCount') || 0;
  }

  user() {
    return Model.hasOne('user')(this);
  }
}
