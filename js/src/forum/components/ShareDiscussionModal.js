import { apiBase } from '../utils/api';
import app from 'flarum/forum/app';
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

  title() { return 'Share Post'; }

  content() {
    return m('.Modal-body', [
      this.error ? m('.Alert.Alert--error', { style: 'margin-bottom:12px' }, this.error) : null,

      m('p.ShareDiscussionModal-label', 'Share to which group?'),

      this.loadingGroups
        ? m('.ShareDiscussionModal-loading', m(LoadingIndicator, { display: 'block' }))
        : !this.groups || this.groups.length === 0
        ? m('p.ShareDiscussionModal-empty', 'You are not a member of any other groups.')
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
        placeholder: 'Add a comment… (optional)',
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
        }, 'Share'),
        m(Button, {
          class:   'Button',
          onclick: () => app.modal.close(),
        }, 'Cancel'),
      ]),
    ]);
  }

  submit() {
    if (!this.selectedGroupId || this.submitting) return;

    this.submitting = true;
    this.error      = null;
    m.redraw();

    fetch(`${apiBase()}/sg-discussions/${this.attrs.discussionId}/share`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({
        targetGroupId: this.selectedGroupId,
        content:       this.comment.trim(),
      }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((data) => {
        this.submitting = false;
        app.modal.close();
        if (this.attrs.onShared) this.attrs.onShared(data);
        m.redraw();
      })
      .catch((err) => {
        this.error      = err.message;
        this.submitting = false;
        m.redraw();
      });
  }
}
