import { apiGet, apiPost, apiDelete } from '../utils/api';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * Shown inside the group sidebar for the creator/admins when there are
 * pending join requests (approval-required groups).
 */
export default class JoinRequestsPanel extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.requests  = null;
    this.loading   = true;
    this.actioning = {};
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();
  }

  load() {
    const groupId = this.attrs.groupId;
    this.loading  = true;

    apiGet(`/social-groups/${groupId}/requests`)
      .then((data) => {
        this.requests = data.data || [];
        this.loading  = false;
        m.redraw();
      })
      .catch(() => {
        this.requests = [];
        this.loading  = false;
        m.redraw();
      });
  }

  approve(request) {
    this.actioning[request.id] = 'approve';
    const groupId = this.attrs.groupId;

    apiPost(`/social-groups/${groupId}/requests/${request.id}/approve`)
      .then((data) => {
        this.requests = this.requests.filter((r) => r.id !== request.id);
        delete this.actioning[request.id];
        if (this.attrs.onApproved) this.attrs.onApproved(data.memberCount);
        m.redraw();
      })
      .catch(() => {
        delete this.actioning[request.id];
        m.redraw();
      });
  }

  reject(request) {
    this.actioning[request.id] = 'reject';
    const groupId = this.attrs.groupId;

    apiDelete(`/social-groups/${groupId}/requests/${request.id}`)
      .then(() => {
        this.requests = this.requests.filter((r) => r.id !== request.id);
        delete this.actioning[request.id];
        m.redraw();
      })
      .catch(() => {
        delete this.actioning[request.id];
        m.redraw();
      });
  }

  view() {
    if (this.loading) {
      return m('.SGJoinRequests', m(LoadingIndicator, { size: 'small' }));
    }

    if (!this.requests || this.requests.length === 0) return null;

    return m('.SGJoinRequests', [
      m('.SGJoinRequests-header', [
        m('span.SGJoinRequests-title',
          app.translator.trans('ernestdefoe-social-groups.forum.requests.title')),
        m('span.SGJoinRequests-badge', this.requests.length),
      ]),
      m('.SGJoinRequests-list',
        this.requests.map((req) =>
          m('.SGJoinRequests-row', { key: req.id }, [
            m('.SGJoinRequests-user', [
              req.user.avatarUrl
                ? m('img.SGJoinRequests-avatar', { src: req.user.avatarUrl, alt: req.user.displayName })
                : m('span.SGJoinRequests-avatarInitial', (req.user.displayName || '?')[0].toUpperCase()),
              m('span.SGJoinRequests-name', req.user.displayName),
            ]),
            m('.SGJoinRequests-actions', [
              m(Button, {
                class:    'Button Button--primary Button--sm SGJoinRequests-approveBtn',
                loading:  this.actioning[req.id] === 'approve',
                disabled: !!this.actioning[req.id],
                onclick:  () => this.approve(req),
              }, m('i.fa-solid.fa-check')),
              m(Button, {
                class:    'Button Button--sm SGJoinRequests-rejectBtn',
                loading:  this.actioning[req.id] === 'reject',
                disabled: !!this.actioning[req.id],
                onclick:  () => this.reject(req),
              }, m('i.fa-solid.fa-xmark')),
            ]),
          ])
        )
      ),
    ]);
  }
}
