import { apiBase } from '../utils/api';
import { pastedImages } from '../utils/uploads';
import { scheduleLinkPreview, clearLinkPreview, viewComposerLinkPreview, viewPostLinkPreview } from '../utils/linkPreview';
import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/utils/humanTime';

export default class GroupDiscussionThread extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.discussion  = null;
    this.posts       = [];
    this.loading     = true;
    this.error       = null;
    this.replyText   = '';
    this.submitting  = false;
    this.replyError  = null;
    this.editingId   = null;
    this.editText    = '';
    this.editError   = null;
    this.deletingId  = null;
    this.openMenuId  = null;

    this.uploads     = [];
    this.editUploads = [];

    this.pickerPostId = null;

    this.linkPreview    = null;
    this.previewLoading = false;
    this.previewUrl     = null;
    this._previewTimer  = null;
    this._dismissedUrls = new Set();

    this.replyingToId          = null;
    this.inlineReplyText       = '';
    this.inlineReplySubmitting = false;

    // ── Realtime state ────────────────────────────────────────────────────
    // Set of post IDs we have already rendered — prevents double-injection
    // when the same post arrives both from the POST response and the WebSocket.
    this._seenPostIds   = new Set();
    // Map of userId → { displayName, avatarUrl, at } for typing indicators.
    this._typingUsers   = new Map();
    this._typingTimer   = null;
    // Throttle: timestamp of last typing-status request sent to the server.
    this._lastTypingSent = 0;
    this._isTyping       = false;
    // Flag cleared in onremove so stale event handlers become no-ops.
    this._rtActive       = false;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();
    this._rtActive = true;
    // Realtime subscription has to wait for load() to resolve, because
    // the per-group private channel name depends on this.discussion.groupId.

    this._closeMenu = (e) => {
      if (this.openMenuId !== null && !e.target.closest('.SGThread-postMenu')) {
        this.openMenuId = null;
        m.redraw();
      }
      if (this.pickerPostId !== null && !e.target.closest('.SGThread-reactWrap')) {
        this.pickerPostId = null;
        m.redraw();
      }
    };
    document.addEventListener('click', this._closeMenu);
  }

  // ── Realtime setup / teardown ──────────────────────────────────────────────

  _setupRealtime() {
    // Idempotent: called after load() resolves; guard against double-subscribe
    // on retries / refresh after error.
    if (this._rtChannel || !this.discussion || !this.discussion.groupId) return;

    const channelName = 'private-sg-group.' + this.discussion.groupId;

    // sg-post-created now carries only IDs.  Refresh the thread in the
    // background and the seen-set dedupe in load() handles ordering.
    const handlePost = (data) => {
      if (!this._rtActive) return;
      if (!data || !data.id) return;
      if (String(data.discussionId) !== String(this.attrs.discussionId)) return;
      if (this._seenPostIds.has(data.id)) return;
      this._refreshSilently();
    };

    const handleTyping = (data) => {
      if (!this._rtActive) return;
      const { discussionId, userId, displayName, avatarUrl, isTyping } = data || {};
      if (!discussionId || !userId) return;
      if (String(discussionId) !== String(this.attrs.discussionId)) return;
      // Never show the current user as typing to themselves.
      if (app.session.user && String(userId) === String(app.session.user.id())) return;

      if (isTyping) {
        this._typingUsers.set(String(userId), { displayName, avatarUrl, at: Date.now() });
      } else {
        this._typingUsers.delete(String(userId));
      }
      m.redraw();

      // Auto-expire stale typing indicators (safety net for missed "stopped" events).
      clearTimeout(this._typingTimer);
      this._typingTimer = setTimeout(() => {
        const cutoff = Date.now() - 4000;
        let changed = false;
        for (const [uid, entry] of this._typingUsers.entries()) {
          if (entry.at < cutoff) { this._typingUsers.delete(uid); changed = true; }
        }
        if (changed) m.redraw();
      }, 4500);
    };

    // ── Subscribe to the per-group private channel ────────────────────────
    // Pusher's protocol gates `private-*` subscriptions through flarum/
    // realtime's auth endpoint, which is what enforces group membership at
    // the WebSocket layer.  Try the raw Pusher client first (most common
    // shape), then fall back to a `subscribePrivate` helper if the extender
    // exposes one.  No subscription = no live updates, but no leak either.
    const pusher = app.realtime?.pusher || app.pusher || null;
    if (pusher && typeof pusher.subscribe === 'function') {
      try {
        const ch = pusher.subscribe(channelName);
        ch.bind('sg-post-created', handlePost);
        ch.bind('sg-typing',       handleTyping);
        this._rtChannel     = ch;
        this._rtChannelName = channelName;
        this._rtPusher      = pusher;
      } catch (_) {
        this._rtChannel = null;
      }
    } else if (typeof app.realtime?.subscribePrivate === 'function') {
      try {
        const ch = app.realtime.subscribePrivate(channelName);
        ch.bind?.('sg-post-created', handlePost);
        ch.bind?.('sg-typing',       handleTyping);
        this._rtChannel     = ch;
        this._rtChannelName = channelName;
      } catch (_) {
        this._rtChannel = null;
      }
    }
  }

  // Background refetch — no loading flicker, no scroll jump.  load() reseeds
  // the seen-set and the diff is rendered transparently on the next redraw.
  _refreshSilently() {
    const discussionId = this.attrs.discussionId;
    fetch(`${apiBase()}/sg-thread-posts/${discussionId}`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!data || !this._rtActive) return;
        if (String(discussionId) !== String(this.attrs.discussionId)) return;
        this.discussion   = data.discussion ?? this.discussion;
        this.posts        = data.data || [];
        this._seenPostIds = new Set(this.posts.map((p) => p.id));
        m.redraw();
      })
      .catch(() => { /* transient — next event triggers another refresh */ });
  }

  _teardownRealtime() {
    this._rtActive = false;
    if (this._rtChannel) {
      try {
        this._rtChannel.unbind?.('sg-post-created');
        this._rtChannel.unbind?.('sg-typing');
      } catch (_) {}
      try {
        if (this._rtPusher?.unsubscribe && this._rtChannelName) {
          this._rtPusher.unsubscribe(this._rtChannelName);
        } else if (typeof app.realtime?.unsubscribe === 'function' && this._rtChannelName) {
          app.realtime.unsubscribe(this._rtChannelName);
        }
      } catch (_) {}
      this._rtChannel = null;
    }
    clearTimeout(this._typingTimer);
    // Tell the server we stopped typing (fire-and-forget).
    if (this._isTyping) this._sendTyping(false);
  }

  // Throttled typing-status sender.  At most one request per 2 s while typing;
  // sends immediately when isTyping flips to false.
  _sendTyping(isTyping) {
    if (!app.session.user || !this.discussion) return;
    if (!isTyping && !this._isTyping) return; // no change

    const now = Date.now();
    if (isTyping && (now - this._lastTypingSent) < 2000) return; // throttle

    this._isTyping       = isTyping;
    this._lastTypingSent = now;

    fetch(`${apiBase()}/sg-typing`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ discussionId: this.discussion.id, isTyping }),
    }).catch(() => {}); // fire-and-forget — errors are non-critical
  }

  onupdate(vnode) {
    if (vnode.attrs.discussionId !== this.attrs.discussionId) {
      this.load();
    }
  }

  onremove(vnode) {
    super.onremove(vnode);
    this._revokeAll(this.uploads);
    this._revokeAll(this.editUploads);
    document.removeEventListener('click', this._closeMenu);
    clearTimeout(this._previewTimer);
    this._teardownRealtime();
  }

  _revokeAll(uploads) {
    uploads.forEach((u) => { if (u.previewUrl) URL.revokeObjectURL(u.previewUrl); });
  }

  load() {
    const discussionId = this.attrs.discussionId;
    this.loading = true;
    this.error   = null;

    fetch(`${apiBase()}/sg-thread-posts/${discussionId}`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((data) => {
        this.discussion = data.discussion;
        this.posts      = data.data || [];
        // Seed the seen-set so WebSocket echoes of already-loaded posts are ignored.
        this._seenPostIds = new Set(this.posts.map((p) => p.id));
        this.loading    = false;
        document.title  = `${this.discussion.title} — ${app.forum.attribute('title')}`;
        m.redraw();
        // Subscribe to the per-group private channel now that we know groupId.
        this._setupRealtime();
      })
      .catch((err) => {
        this.error   = err.message;
        this.loading = false;
        m.redraw();
      });
  }

  // ── File uploads ─────────────────────────────────────────────────────────

  handleFiles(files, uploadsKey, textKey) {
    for (const file of files) {
      const id         = Math.random().toString(36).slice(2);
      const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;

      this[uploadsKey].push({ id, name: file.name, previewUrl, uploading: true, error: null, uuid: null });
      m.redraw();

      const fd = new FormData();
      fd.append('files[]', file);

      fetch(`${apiBase()}/fof/upload`, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
        body:        fd,
      })
        .then((r) => {
          if (!r.ok) return r.json().then((e) => { throw new Error(e.errors?.[0]?.detail || e.error || 'Upload failed'); });
          return r.json();
        })
        .then((data) => {
          const fileData = Array.isArray(data.data) ? data.data[0] : data.data;
          const uuid     = fileData?.attributes?.uuid || fileData?.id;
          const bbcode   = fileData?.attributes?.bbcode || `[upl-file uuid="${uuid}"][/upl-file]`;
          const upload   = this[uploadsKey].find((u) => u.id === id);
          if (upload) {
            upload.uuid      = uuid;
            upload.uploading = false;
            this[textKey]    = this[textKey] ? `${this[textKey]}\n${bbcode}` : bbcode;
          }
          m.redraw();
        })
        .catch((err) => {
          const upload = this[uploadsKey].find((u) => u.id === id);
          if (upload) { upload.uploading = false; upload.error = err.message; }
          m.redraw();
        });
    }
  }

  removeUpload(id, uploadsKey, textKey) {
    const upload = this[uploadsKey].find((u) => u.id === id);
    if (!upload) return;
    if (upload.previewUrl) URL.revokeObjectURL(upload.previewUrl);
    if (upload.uuid) {
      const tag      = `[upl-file uuid="${upload.uuid}"][/upl-file]`;
      this[textKey]  = this[textKey].replace(`\n${tag}`, '').replace(tag, '').trim();
    }
    this[uploadsKey] = this[uploadsKey].filter((u) => u.id !== id);
    m.redraw();
  }

  // ── Reactions ─────────────────────────────────────────────────────────────

  togglePicker(postId) {
    this.pickerPostId = this.pickerPostId === postId ? null : postId;
    m.redraw();
  }

  toggleReaction(post, reactionKey) {
    if (!app.session.user || !post || !post.id) return;

    const prevReaction  = post.actorReaction;
    const prevReactions = { ...(post.reactions || {}) };
    const nextReaction  = prevReaction === reactionKey ? null : reactionKey;

    // Optimistic update
    post.actorReaction = nextReaction;
    const updated = { ...prevReactions };
    if (prevReaction) { updated[prevReaction] = Math.max(0, (updated[prevReaction] || 0) - 1); if (!updated[prevReaction]) delete updated[prevReaction]; }
    if (nextReaction) { updated[nextReaction] = (updated[nextReaction] || 0) + 1; }
    post.reactions    = updated;
    this.pickerPostId = null;
    m.redraw();

    const reactUrl = nextReaction
      ? `${apiBase()}/sg-posts/${post.id}/react`
      : `${apiBase()}/sg-posts/${post.id}/unreact`;
    fetch(reactUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        ...(nextReaction ? { 'Content-Type': 'application/json' } : {}),
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: nextReaction ? JSON.stringify({ reaction: nextReaction }) : undefined,
    })
      .then((r) => {
        if (!r.ok) throw new Error('reaction_failed');
        return r.json();
      })
      .then((data) => {
        post.reactions     = data.reactions || {};
        post.actorReaction = data.actorReaction || null;
        m.redraw();
      })
      .catch(() => {
        post.reactions     = prevReactions;
        post.actorReaction = prevReaction;
        m.redraw();
      });
  }

  // ── Posts ─────────────────────────────────────────────────────────────────

  submitReply() {
    const content = this.replyText.trim();
    if (!content || this.submitting) return;

    this.submitting = true;
    this.replyError = null;

    fetch(`${apiBase()}/sg-posts`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ discussionId: this.discussion.id, content, linkPreview: this.linkPreview || null }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((post) => {
        // Register as seen so the WebSocket echo doesn't double-insert it.
        this._seenPostIds.add(post.id);
        this._sendTyping(false);
        this.posts.push(post);
        if (this.discussion) this.discussion.commentCount = (this.discussion.commentCount || 0) + 1;
        this.replyText  = '';
        this.submitting = false;
        this._revokeAll(this.uploads);
        this.uploads = [];
        clearLinkPreview(this);
        m.redraw();
        requestAnimationFrame(() => {
          const el = document.querySelector('.SGThread-posts');
          if (el) el.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'end' });
        });
      })
      .catch((err) => {
        this.replyError = err.message;
        this.submitting = false;
        m.redraw();
      });
  }

  startEdit(post) {
    this._revokeAll(this.editUploads);
    this.editUploads = [];
    this.editingId   = post.id;
    this.editText    = post.content;
    this.openMenuId  = null;
  }

  cancelEdit() {
    this._revokeAll(this.editUploads);
    this.editUploads = [];
    this.editingId   = null;
    this.editText    = '';
    this.editError   = null;
  }

  saveEdit(post) {
    const content = this.editText.trim();
    if (!content) return;

    fetch(`${apiBase()}/sg-posts/${post.id}`, {
      method:      'PATCH',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ content }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((updated) => {
        const idx = this.posts.findIndex((p) => p.id === post.id);
        if (idx !== -1) this.posts[idx] = { ...this.posts[idx], ...updated };
        this._revokeAll(this.editUploads);
        this.editUploads = [];
        this.cancelEdit();
        m.redraw();
      })
      .catch((err) => {
        this.editError = err.message || 'Failed to save edit.';
        m.redraw();
      });
  }

  /**
   * Toggle the pinned state of a single reply. Optimistic update — we
   * flip the flag locally and re-order, then reconcile against the
   * server response. On failure we revert. Mirrors discussion-level
   * pinning in GroupFeed.pinDiscussion.
   */
  pinPost(post) {
    const wasPinned = !!post.isPinned;
    post.isPinned = !wasPinned;
    this.openMenuId = null;
    this._resortPinned();
    m.redraw();

    fetch(`${apiBase()}/sg-posts/${post.id}/pin`, {
      method:      'PATCH',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.json())
      .then((data) => {
        if (typeof data.isPinned === 'boolean') {
          post.isPinned = data.isPinned;
          this._resortPinned();
          m.redraw();
        }
      })
      .catch(() => {
        post.isPinned = wasPinned;
        this._resortPinned();
        m.redraw();
      });
  }

  /**
   * Re-sort this.posts so pinned posts float to the top in their
   * original chronological order. Stable sort on (is_pinned desc,
   * createdAt asc) — same shape the backend returns.
   */
  _resortPinned() {
    if (!Array.isArray(this.posts)) return;
    this.posts.sort((a, b) => {
      const ap = a.isPinned ? 1 : 0;
      const bp = b.isPinned ? 1 : 0;
      if (ap !== bp) return bp - ap;
      const at = new Date(a.createdAt).getTime();
      const bt = new Date(b.createdAt).getTime();
      return at - bt;
    });
  }

  deletePost(post) {
    if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete_post_confirm'))) return;
    this.deletingId = post.id;
    this.openMenuId = null;

    fetch(`${apiBase()}/sg-posts/${post.id}/delete`, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    }).then(() => {
      // Also remove any child replies (DB cascade handles the data side)
      const removed = new Set([post.id]);
      this.posts.filter((p) => p.parentPostId && removed.has(p.parentPostId)).forEach((p) => removed.add(p.id));
      const removedCount = removed.size;
      this.posts = this.posts.filter((p) => !removed.has(p.id));
      if (this.discussion) this.discussion.commentCount = Math.max(0, (this.discussion.commentCount || removedCount) - removedCount);
      this.deletingId = null;
      m.redraw();
    }).catch(() => {
      this.deletingId = null;
      m.redraw();
    });
  }

  startInlineReply(post) {
    const targetId     = post.parentPostId ?? post.id;
    this.replyingToId  = this.replyingToId === targetId ? null : targetId;
    this.inlineReplyText = '';
    m.redraw();
  }

  submitInlineReply() {
    const content = this.inlineReplyText.trim();
    if (!content || this.inlineReplySubmitting) return;

    this.inlineReplySubmitting = true;

    fetch(`${apiBase()}/sg-posts`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ discussionId: this.discussion.id, content, parentPostId: this.replyingToId }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((post) => {
        this._seenPostIds.add(post.id);
        this._sendTyping(false);
        this.posts.push(post);
        if (this.discussion) this.discussion.commentCount = (this.discussion.commentCount || 0) + 1;
        this.inlineReplyText       = '';
        this.inlineReplySubmitting = false;
        this.replyingToId          = null;
        m.redraw();
      })
      .catch(() => {
        this.inlineReplySubmitting = false;
        m.redraw();
      });
  }

  // ── Typing indicator ──────────────────────────────────────────────────────

  viewTypingBar() {
    const typers = [...this._typingUsers.values()];
    if (!typers.length) return null;

    let label;
    if (typers.length === 1) {
      label = `${typers[0].displayName} is typing…`;
    } else if (typers.length === 2) {
      label = `${typers[0].displayName} and ${typers[1].displayName} are typing…`;
    } else {
      label = `${typers[0].displayName} and ${typers.length - 1} others are typing…`;
    }

    return m('.SGThread-typingBar', [
      m('.SGThread-typingAvatars',
        typers.slice(0, 3).map((t) =>
          t.avatarUrl
            ? m('img.SGThread-typingAvatar', { src: t.avatarUrl, alt: t.displayName, key: t.displayName })
            : m('span.SGThread-typingInitial', { key: t.displayName }, (t.displayName || '?')[0].toUpperCase())
        )
      ),
      m('span.SGThread-typingLabel', [
        m('.SGThread-typingDots', [m('span'), m('span'), m('span')]),
        ' ',
        label,
      ]),
    ]);
  }

  // ── Views ─────────────────────────────────────────────────────────────────

  view() {
    const { slug } = this.attrs;
    const actor    = app.session.user;

    return m('.SGThread', [
      m('.SGThread-back', [
        m('a.SGThread-backLink', {
          href: app.route('ernestdefoe-social-groups.show', { slug }),
          onclick: (e) => { e.preventDefault(); m.route.set(app.route('ernestdefoe-social-groups.show', { slug })); },
        }, [m('i.fa-solid.fa-arrow-left'), ' ',
            app.translator.trans('ernestdefoe-social-groups.forum.discussions.back')]),
      ]),

      this.loading
        ? m('.SGThread-loading', m(LoadingIndicator, { display: 'block' }))
        : this.error
        ? m('.SGThread-error', this.error)
        : m('.SGThread-body', [
            m('.SGThread-headerCard', [
              m('h1.SGThread-title', this.discussion.title),
              m('.SGThread-meta', [
                m('span', [
                  m('i.fa-solid.fa-message'),
                  ' ',
                  app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_count', { count: this.discussion.commentCount }),
                ]),
                this.discussion.isLocked
                  ? m('span.SGThread-locked', [m('i.fa-solid.fa-lock'), ' ',
                      app.translator.trans('ernestdefoe-social-groups.forum.discussions.locked')])
                  : null,
              ]),
            ]),

            m('.SGThread-posts', (() => {
              const topLevel = this.posts.filter((p) => !p.parentPostId);
              const nested   = this.posts.filter((p) => !!p.parentPostId);
              const repliesByParent = {};
              nested.forEach((p) => {
                if (!repliesByParent[p.parentPostId]) repliesByParent[p.parentPostId] = [];
                repliesByParent[p.parentPostId].push(p);
              });
              return topLevel.map((post) => this.viewPost(post, repliesByParent));
            })()),

            this.viewTypingBar(),

            actor && !this.discussion.isLocked
              ? this.viewReplyBox(actor)
              : null,
          ]),
    ]);
  }

  viewReplyBox(actor) {
    return m('.SGThread-replyBox', [
      m('.SGThread-replyBoxAvatar', [
        actor.attribute('avatarUrl')
          ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
          : m('span.SGThread-replyBoxInitial', (actor.attribute('displayName') || '?')[0].toUpperCase()),
      ]),
      m('.SGThread-replyBoxRight', [
        this.replyError ? m('.Alert.Alert--error', this.replyError) : null,
        m('textarea.SGThread-replyTextarea', {
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_placeholder'),
          value:       this.replyText,
          oninput:     (e) => {
            this.replyText = e.target.value;
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
            scheduleLinkPreview(this, e.target.value);
            if (e.target.value.trim()) this._sendTyping(true);
          },
          onblur: () => this._sendTyping(false),
          onpaste: (e) => {
            const imgs = pastedImages(e);
            if (imgs.length) { e.preventDefault(); this.handleFiles(imgs, 'uploads', 'replyText'); }
          },
          rows:     1,
          disabled: this.submitting,
        }),
        this.uploads.length
          ? m('.SGThread-uploads', this.uploads.map((u) => this.viewUpload(u, 'uploads', 'replyText')))
          : null,
        viewComposerLinkPreview(this),
        m('.SGThread-replyFooter', [
          m('.SGThread-replyFooterLeft', [
            this.viewUploadBtn('uploads', 'replyText', this.submitting),
          ]),
          m('button.SGThread-postBtn', {
            disabled: this.submitting || !this.replyText.trim() || this.uploads.some((u) => u.uploading),
            onclick:  () => this.submitReply(),
          }, this.submitting
            ? m('i.fa-solid.fa-spinner.fa-spin')
            : app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_button')),
        ]),
      ]),
    ]);
  }

  viewUploadBtn(uploadsKey, textKey, disabled) {
    return m('label.SGThread-uploadBtn', {
      title: app.translator.trans('ernestdefoe-social-groups.forum.discussions.upload_image'),
      class: disabled ? 'disabled' : '',
    }, [
      m('input[type=file]', {
        accept:   'image/*,video/*,.pdf,.doc,.docx,.zip',
        multiple: true,
        style:    'display:none',
        disabled,
        onchange: (e) => {
          if (e.target.files.length) this.handleFiles(Array.from(e.target.files), uploadsKey, textKey);
          e.target.value = '';
        },
      }),
      m('i.fa-solid.fa-paperclip'),
    ]);
  }

  viewUpload(u, uploadsKey, textKey) {
    const cls = 'SGThread-upload' +
      (u.error ? '.SGThread-upload--error' : u.uploading ? '.SGThread-upload--loading' : '.SGThread-upload--done');

    return m(cls, { key: u.id }, [
      u.uploading
        ? m('i.fa-solid.fa-spinner.fa-spin.SGThread-uploadSpinner')
        : u.error
        ? m('i.fa-solid.fa-circle-exclamation.SGThread-uploadErrIcon')
        : u.previewUrl
        ? m('img.SGThread-uploadThumb', { src: u.previewUrl, alt: u.name })
        : m('i.fa-solid.fa-file.SGThread-uploadFileIcon'),
      m('span.SGThread-uploadName', u.error ? `${u.name}: ${u.error}` : u.name),
      !u.uploading
        ? m('button.SGThread-uploadRemove', {
            type:    'button',
            title:   app.translator.trans('ernestdefoe-social-groups.forum.discussions.upload_remove'),
            onclick: () => this.removeUpload(u.id, uploadsKey, textKey),
          }, '×')
        : null,
    ]);
  }

  static REACTIONS = [
    { key: 'like',  emoji: '👍', label: 'Like' },
    { key: 'heart', emoji: '❤️', label: 'Love' },
    { key: 'haha',  emoji: '😂', label: 'Haha' },
    { key: 'wow',   emoji: '😮', label: 'Wow' },
    { key: 'sad',   emoji: '😢', label: 'Sad' },
    { key: 'angry', emoji: '😡', label: 'Angry' },
  ];

  viewReactionStatBar(post) {
    const reactions = post.reactions || {};
    const total     = Object.values(reactions).reduce((s, c) => s + Number(c), 0);
    if (!total) return null;

    const topEmojis = Object.entries(reactions)
      .filter(([, c]) => Number(c) > 0)
      .sort(([, a], [, b]) => Number(b) - Number(a))
      .slice(0, 3)
      .map(([key]) => GroupDiscussionThread.REACTIONS.find((r) => r.key === key)?.emoji || '👍');

    return m('.SGThread-postStatBar', [
      m('span.SGThread-statLikes', [
        topEmojis.map((emoji) => m('span.SGThread-reactionEmoji', emoji)),
        ' ',
        total,
      ]),
    ]);
  }

  viewReactionActionBar(post) {
    const actor         = app.session.user;
    const pickerOpen    = this.pickerPostId === post.id;
    const actorReaction = post.actorReaction || null;
    const active        = actorReaction
      ? GroupDiscussionThread.REACTIONS.find((r) => r.key === actorReaction)
      : null;

    return m('.SGThread-postActionBar', [
      actor
        ? m('.SGThread-reactWrap', [
            pickerOpen
              ? m('.SGThread-reactionPicker',
                  GroupDiscussionThread.REACTIONS.map((r) =>
                    m('button.SGThread-pickerBtn', {
                      key:     r.key,
                      title:   r.label,
                      class:   actorReaction === r.key ? 'is-active' : '',
                      onclick: (e) => { e.stopPropagation(); this.pickerPostId = null; this.toggleReaction(post, r.key); },
                    }, [m('span.SGThread-pickerEmoji', r.emoji), m('span.SGThread-pickerLabel', r.label)])
                  ))
              : null,
            // Single React button: opens picker when idle, removes reaction when active
            m('button.SGThread-reactBtn', {
              class:   active ? 'SGThread-reactBtn--active' : '',
              onclick: (e) => {
                e.stopPropagation();
                if (active) {
                  this.toggleReaction(post, actorReaction); // same key → removes it
                } else {
                  this.togglePicker(post.id);               // opens emoji picker
                }
              },
            }, active
                ? [active.emoji, ' ', active.label]
                : [m('i.fa-solid.fa-face-grin-beam'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.discussions.react')]),
          ])
        : null,
      actor && !this.discussion?.isLocked
        ? m('button.SGThread-replyBtn', {
            class:   this.replyingToId === (post.parentPostId ?? post.id) ? 'is-active' : '',
            onclick: () => this.startInlineReply(post),
          }, [m('i.fa-solid.fa-reply'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_button')])
        : null,
    ]);
  }

  viewInlineReplyComposer() {
    const actor = app.session.user;
    return m('.SGThread-inlineReply', [
      m('.SGThread-inlineReplyAvatar', [
        actor.attribute('avatarUrl')
          ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
          : m('span', (actor.attribute('displayName') || '?')[0].toUpperCase()),
      ]),
      m('.SGThread-inlineReplyInputWrap', [
        m('textarea.SGThread-inlineReplyInput', {
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_placeholder'),
          value:       this.inlineReplyText,
          rows:        1,
          disabled:    this.inlineReplySubmitting,
          oninput:     (e) => {
            this.inlineReplyText = e.target.value;
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
            if (e.target.value.trim()) this._sendTyping(true);
          },
          onblur:    () => this._sendTyping(false),
          onkeydown: (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.submitInlineReply(); }
          },
        }),
        m('button.SGThread-inlineReplySendBtn', {
          disabled: this.inlineReplySubmitting || !this.inlineReplyText.trim(),
          onclick:  () => this.submitInlineReply(),
          title:    app.translator.trans('ernestdefoe-social-groups.forum.discussions.post_reply'),
        }, this.inlineReplySubmitting
            ? m('i.fa-solid.fa-spinner.fa-spin')
            : m('i.fa-solid.fa-paper-plane')),
      ]),
    ]);
  }

  viewPost(post, repliesByParent = {}, nested = false) {
    const isEditing  = this.editingId === post.id;
    const isDeleting = this.deletingId === post.id;
    const menuOpen   = this.openMenuId === post.id;
    const actor      = app.session.user;
    // Client-side fallback: always allow edit/delete on your own posts even if
    // the server flag was missing (e.g. posts injected via WebSocket broadcast).
    const isOwnPost  = actor && post.user && String(post.user.id) === String(actor.id());
    const canEdit    = post.canEdit   || isOwnPost;
    const canDelete  = post.canDelete || isOwnPost;
    const canPin     = !!post.canPin;
    const isPinned   = !!post.isPinned;
    const cls = '.SGThread-post'
      + (nested    ? '.SGThread-post--nested' : '')
      + (isPinned  ? '.SGThread-post--pinned' : '')
      + (isDeleting ? '.is-deleting'          : '');

    return m(cls, { key: post.id }, [

      // ── Post header: avatar + name/time + menu ──
      m('.SGThread-postHeader', [
        m('.SGThread-postAvatar', [
          post.user && post.user.avatarUrl
            ? m('img', { src: post.user.avatarUrl, alt: post.user.displayName })
            : m('span.SGThread-postInitial',
                (post.user?.displayName || '?')[0].toUpperCase()),
        ]),
        m('.SGThread-postMeta', [
          m('span.SGThread-postAuthor', post.user?.displayName || ''),
          m('span.SGThread-postTime', { title: post.createdAt }, [
            humanTime(new Date(post.createdAt)),
            post.updatedAt !== post.createdAt
              ? m('span.SGThread-postEdited', ' · ' + app.translator.trans('ernestdefoe-social-groups.forum.discussions.edited'))
              : null,
          ]),
          isPinned
            ? m('span.SGThread-pinnedBadge', {
                title: app.translator.trans('ernestdefoe-social-groups.forum.discussions.pinned_reply'),
              }, [
                m('i.fa-solid.fa-thumbtack'),
                ' ',
                app.translator.trans('ernestdefoe-social-groups.forum.discussions.pinned'),
              ])
            : null,
        ]),
        !isEditing && (canEdit || canDelete || canPin)
          ? m('.SGThread-postMenu', [
              m('button.SGThread-postMenuBtn', {
                onclick: (e) => {
                  e.stopPropagation();
                  this.openMenuId = menuOpen ? null : post.id;
                  m.redraw();
                },
                title: app.translator.trans('ernestdefoe-social-groups.forum.discussions.more_options'),
              }, m('i.fa-solid.fa-ellipsis')),
              menuOpen
                ? m('.SGThread-postDropdown', [
                    canPin
                      ? m('button.SGThread-dropdownItem', { onclick: () => this.pinPost(post) }, [
                          m('i.fa-solid.fa-thumbtack'), ' ',
                          app.translator.trans(
                            isPinned
                              ? 'ernestdefoe-social-groups.forum.discussions.unpin_reply'
                              : 'ernestdefoe-social-groups.forum.discussions.pin_reply'
                          ),
                        ])
                      : null,
                    canEdit
                      ? m('button.SGThread-dropdownItem', { onclick: () => this.startEdit(post) }, [
                          m('i.fa-solid.fa-pencil'), ' ',
                          app.translator.trans('ernestdefoe-social-groups.forum.discussions.edit'),
                        ])
                      : null,
                    canDelete
                      ? m('button.SGThread-dropdownItem.SGThread-dropdownItem--danger', { onclick: () => this.deletePost(post) }, [
                          m('i.fa-solid.fa-trash'), ' ',
                          app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete_post'),
                        ])
                      : null,
                  ])
                : null,
            ])
          : null,
      ]),

      // ── Content or edit form ──
      isEditing
        ? m('.SGThread-postEdit', [
            this.editError ? m('.Alert.Alert--error', { style: 'margin-bottom:8px;font-size:.85em' }, this.editError) : null,
            m('textarea.FormControl.SGThread-editTextarea', {
              value:   this.editText,
              oninput: (e) => { this.editText = e.target.value; },
              onpaste: (e) => {
                const imgs = pastedImages(e);
                if (imgs.length) { e.preventDefault(); this.handleFiles(imgs, 'editUploads', 'editText'); }
              },
              rows:    4,
            }),
            this.editUploads.length
              ? m('.SGThread-uploads', this.editUploads.map((u) => this.viewUpload(u, 'editUploads', 'editText')))
              : null,
            m('.SGThread-editActions', [
              this.viewUploadBtn('editUploads', 'editText', false),
              m(Button, {
                class:    'Button Button--primary Button--sm',
                onclick:  () => this.saveEdit(post),
                disabled: !this.editText.trim() || this.editUploads.some((u) => u.uploading),
              }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.save_edit')),
              m(Button, {
                class:   'Button Button--sm',
                onclick: () => this.cancelEdit(),
              }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.cancel_edit')),
            ]),
          ])
        : m('.SGThread-postContent', m.trust(post.contentParsed)),
      !isEditing ? viewPostLinkPreview(post) : null,

      // ── Reaction count bar ──
      this.viewReactionStatBar(post),

      // ── Reaction + reply action bar ──
      !isEditing ? this.viewReactionActionBar(post) : null,

      // ── Nested replies + inline composer (top-level posts only) ──
      !nested ? (() => {
        const replies      = repliesByParent[post.id] || [];
        const showComposer = this.replyingToId === post.id;
        if (!replies.length && !showComposer) return null;
        return m('.SGThread-replies', [
          replies.map((r) => this.viewPost(r, {}, true)),
          showComposer ? this.viewInlineReplyComposer() : null,
        ]);
      })() : null,
    ]);
  }
}
