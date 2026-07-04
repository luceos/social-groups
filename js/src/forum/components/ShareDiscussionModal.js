import { shareDiscussion } from '../utils/api';
import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';

export default class ShareDiscussionModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups          = null;
    this.loadingGroups   = true;
    this.selectedGroupId = null;
    this.comment         = '';
    this.submitting      = false;
    this.error           = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadGroups();
  }

  loadGroups() {
    app.store
      .find('social-groups', { 'page[limit]': 200 })
      .then((groups) => {
        this.groups = groups.filter(
          (g) => g.isMember() && String(g.id()) !== String(this.attrs.currentGroupId)
        );
        this.loadingGroups = false;
        m.redraw();
      })
      .catch(() => {
        this.groups        = [];
        this.loadingGroups = false;
        m.redraw();
      });
  }

  className() { return 'ShareDiscussionModal Modal--small'; }

  title() { return app.translator.trans('ernestdefoe-social-groups.forum.share_modal.title'); }

  content() {
    return m('.Modal-body', [
      this.error ? m('.Alert.Alert--error', { style: 'margin-bottom:12px' }, this.error) : null,

      m('p.ShareDiscussionModal-label', app.translator.trans('ernestdefoe-social-groups.forum.share_modal.group_label')),

      this.loadingGroups
        ? m('.ShareDiscussionModal-loading', m(LoadingIndicator, { display: 'block' }))
        : !this.groups || this.groups.length === 0
        ? m('p.ShareDiscussionModal-empty', app.translator.trans('ernestdefoe-social-groups.forum.share_modal.empty'))
        : m('.ShareDiscussionModal-groups',
            this.groups.map((g) =>
              m('button.ShareDiscussionModal-group', {
                key:     g.id(),
                class:   String(this.selectedGroupId) === String(g.id()) ? 'is-selected' : '',
                onclick: () => { this.selectedGroupId = g.id(); m.redraw(); },
              }, [
                g.imageUrl()
                  ? m('img.ShareDiscussionModal-groupImg', { src: g.imageUrl(), alt: '' })
                  : m('span.ShareDiscussionModal-groupInitial',
                      { style: `background:${g.color() || '#4A90E2'}` },
                      (g.name() || '?')[0].toUpperCase()),
                m('span.ShareDiscussionModal-groupName', g.name()),
              ])
            )
          ),

      m('textarea.FormControl.ShareDiscussionModal-comment', {
        placeholder: extractText(app.translator.trans('ernestdefoe-social-groups.forum.share_modal.comment_placeholder')),
        value:       this.comment,
        rows:        3,
        disabled:    this.submitting,
        oninput:     (e) => { this.comment = e.target.value; },
      }),

      m('.ShareDiscussionModal-footer', [
        m(Button, {
          class:    'Button Button--primary',
          loading:  this.submitting,
          disabled: !this.selectedGroupId || this.submitting,
          onclick:  () => this.submit(),
        }, app.translator.trans('ernestdefoe-social-groups.forum.share_modal.submit')),
        m(Button, {
          class:   'Button',
          onclick: () => app.modal.close(),
        }, app.translator.trans('ernestdefoe-social-groups.forum.share_modal.cancel')),
      ]),
    ]);
  }

  submit() {
    if (!this.selectedGroupId || this.submitting) return;

    this.submitting = true;
    this.error      = null;
    m.redraw();

    shareDiscussion(this.attrs.discussionId, {
      targetGroupId: this.selectedGroupId,
      content:       this.comment.trim(),
    })
      .then((data) => {
        this.submitting = false;
        app.modal.close();
        if (this.attrs.onShared) this.attrs.onShared(data);
        m.redraw();
      })
      .catch((err) => {
        this.error      = err.response?.error || err.message || extractText(app.translator.trans('ernestdefoe-social-groups.forum.groups.generic_error'));
        this.submitting = false;
        m.redraw();
      });
  }
}
