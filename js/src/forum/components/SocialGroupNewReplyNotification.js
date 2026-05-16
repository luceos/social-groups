import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

export default class SocialGroupNewReplyNotification extends Notification {
  icon() {
    return 'fa-solid fa-reply';
  }

  href() {
    const content = this.attrs.notification.attribute('content');
    if (!content?.groupSlug || !content?.discussionId) return '#';
    return app.route('ernestdefoe-social-groups.discussion', {
      slug:         content.groupSlug,
      discussionId: content.discussionId,
    });
  }

  content() {
    const content = this.attrs.notification.attribute('content');
    return app.translator.trans(
      'ernestdefoe-social-groups.forum.notifications.new_reply',
      { discussionTitle: m('strong', content?.discussionTitle || '') }
    );
  }
}
