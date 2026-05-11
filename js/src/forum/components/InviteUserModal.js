import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';

export default class InviteUserModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.username = Stream('');
    this.loading  = false;
    this.error    = null;
    this.success  = null;
  }

  className() {
    return 'Modal--small InviteUserModal';
  }

  title() {
    return app.translator.trans('ernestdefoe-social-groups.forum.invite.title');
  }

  content() {
    return m('.Modal-body', [
      this.error
        ? m('.Alert.Alert--error', [m('i.fas.fa-exclamation-circle'), ' ', this.error])
        : null,

      this.success
        ? m('.Alert.Alert--success', [m('i.fas.fa-check-circle'), ' ', this.success])
        : null,

      m('.Form-group', [
        m('label', app.translator.trans('ernestdefoe-social-groups.forum.invite.username_label')),
        m('input.FormControl', {
          type:        'text',
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.invite.username_placeholder'),
          value:       this.username(),
          oninput:     (e) => {
            this.username(e.target.value);
            this.error   = null;
            this.success = null;
          },
          disabled:  this.loading,
          autofocus: true,
        }),
      ]),

      m('.Form-group.InviteUserModal-actions', [
        m(Button, {
          class:    'Button Button--primary',
          loading:  this.loading,
          disabled: this.loading || !this.username().trim(),
          onclick:  () => this.submit(),
        }, app.translator.trans('ernestdefoe-social-groups.forum.invite.submit')),
      ]),
    ]);
  }

  submit() {
    const username = this.username().trim();
    if (!username || this.loading) return;

    this.loading = true;
    this.error   = null;
    this.success = null;

    fetch(`${app.forum.attribute('apiUrl')}/social-groups/${this.attrs.groupId}/invite`, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ username }),
    })
      .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
      .then(({ ok, data }) => {
        this.loading = false;
        if (!ok) {
          this.error = data.error || app.translator.trans('ernestdefoe-social-groups.forum.invite.error_generic');
        } else {
          this.success  = app.translator.trans('ernestdefoe-social-groups.forum.invite.success', { username: data.displayName });
          this.username('');
          if (this.attrs.onInvited) this.attrs.onInvited(data);
        }
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.error   = app.translator.trans('ernestdefoe-social-groups.forum.invite.error_generic');
        m.redraw();
      });
  }
}
