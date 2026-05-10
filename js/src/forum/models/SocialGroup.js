import Model from 'flarum/common/Model';

export default class SocialGroup extends Model {
  name() {
    return Model.attribute('name')(this);
  }

  slug() {
    return Model.attribute('slug')(this);
  }

  description() {
    return Model.attribute('description')(this);
  }

  color() {
    return Model.attribute('color')(this);
  }

  imageUrl() {
    return Model.attribute('imageUrl')(this);
  }

  bannerUrl() {
    return Model.attribute('bannerUrl')(this);
  }

  isPrivate() {
    return Model.attribute('isPrivate')(this);
  }

  memberCount() {
    return Model.attribute('memberCount')(this);
  }

  createdAt() {
    return Model.attribute('createdAt', Model.transformDate)(this);
  }

  canEdit() {
    return Model.attribute('canEdit')(this);
  }

  isMember() {
    return Model.attribute('isMember')(this);
  }

  isCreator() {
    return Model.attribute('isCreator')(this);
  }

  user() {
    return Model.hasOne('user')(this);
  }
}
