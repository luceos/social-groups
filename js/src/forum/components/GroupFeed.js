import { apiBase } from '../utils/api';
import { pastedImages, handleFiles, removeUpload, revokeAll, viewUploadChips } from '../utils/uploads';
import { scheduleLinkPreview, clearLinkPreview, viewComposerLinkPreview, viewPostLinkPreview } from '../utils/linkPreview';
import ShareDiscussionModal from './ShareDiscussionModal';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/utils/humanTime';

export default class GroupFeed extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.discussions  = null;
    this.loading      = true;
    this.loadError    = false;
    this.page         = 1;
    this.pages        = 1;
    this.total        = 0;
    this.deleting     = null;
    this.openMenuId   = null;

    // Composer state
    this.postText       = '';
    this.postSubmitting = false;
    this.postError      = null;
    this.postFocused    = false;
    this.postUploads    = [];

    this.linkPreview    = null;
    this.previewLoading = false;
    this.previewUrl     = null;
    this._previewTimer  = null;
    this._dismissedUrls = new Set();

    // Per-post comment reply state: { [discussionId]: text }
    this.replyTexts     = {};
    this.replySubmitting = {};

    this.pickerDiscId = null;

    this.searchQuery  = '';
    this._searchTimer = null;

    // Poll composer state
    this.poll = null; // null = no poll; object = { question, options: ['', ''], isMultiSelect: false }

    // Inline comment state
    this.expandedDiscIds  = new Set();   // IDs of discussions showing inline comments
    this.loadedComments   = {};          // { [discId]: post[] }
    this.commentsLoading  = {};          // { [discId]: bool }
    this._rtFeedHandler   = null;        // sg:post-created DOM listener
    this.pickerCommentId  = null;        // post.id whose reaction picker is open

    // @mention state
    this.members          = null;        // cached member list; null = not yet loaded
    this.membersLoading   = false;
    this.mentionQuery     = null;        // string typed after '@', null = dropdown closed
    this.mentionDiscId    = null;        // 'feed' | discussion.id (identifies active textarea)
    this._mentionTa       = null;        // reference to the active textarea DOM element
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();

    this._closeMenu = (e) => {
      if (this.openMenuId !== null && !e.target.closest('.SGFeed-postMenu')) {
        this.openMenuId = null;
        m.redraw();
      }
      if (this.pickerDiscId !== null && !e.target.closest('.SGFeed-reactWrap')) {
        this.pickerDiscId = null;
        m.redraw();
      }
      if (this.pickerCommentId !== null && !e.target.closest('.SGFeed-commentReactWrap')) {
        this.pickerCommentId = null;
        m.redraw();
      }
      if (this.mentionQuery !== null && !e.target.closest('.SGFeed-mentionDropdown')) {
        this.mentionQuery  = null;
        this.mentionDiscId = null;
        m.redraw();
      }
    };
    document.addEventListener('click', this._closeMenu);

    // Live-update inline comment lists when a new post arrives via WebSocket.
    this._rtFeedHandler = (e) => {
      const post   = e.detail;
      if (!post || !post.discussionId) return;
      const discId = post.discussionId;
      // Only inject if the comment section for this discussion is open.
      if (!this.expandedDiscIds.has(discId)) return;
      const comments = this.loadedComments[discId];
      if (!Array.isArray(comments)) return;
      // Deduplicate — the actor's own reply is added synchronously in submitReply.
      if (comments.some((c) => c.id === post.id)) return;
      this.loadedComments[discId] = [...comments, post];
      // Bump visible comment count.
      const d = (this.discussions || []).find((x) => x.id === discId);
      if (d) d.commentCount = (d.commentCount || 0) + 1;
      m.redraw();
    };
    document.addEventListener('sg:post-created', this._rtFeedHandler);
  }

  onupdate(vnode) {
    if (vnode.attrs.groupId !== this.attrs.groupId) {
      this.discussions = null;
      this.loading     = true;
      this.page        = 1;
      this.load();
    }
  }

  onremove() {
    document.removeEventListener('click', this._closeMenu);
    if (this._rtFeedHandler) document.removeEventListener('sg:post-created', this._rtFeedHandler);
    revokeAll(this.postUploads);
    clearTimeout(this._previewTimer);
    clearTimeout(this._searchTimer);
  }

  load(page = 1, q = this.searchQuery) {
    const groupId = this.attrs.groupId;
    this.loading  = true;
    this.page     = page;

    // Reset per-page comment state so stale data from a previous page/search
    // doesn't linger when the discussion list is replaced.
    this.expandedDiscIds = new Set();
    this.loadedComments  = {};
    this.commentsLoading = {};

    const qs = new URLSearchParams({ page });
    if (q) qs.set('q', q);

    fetch(`${apiBase()}/sg-discussions/${groupId}?${qs}`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((data) => {
        this.discussions = data.data || [];
        this.total       = data.total || 0;
        this.pages       = data.pages || 1;
        this.loadError   = false;
        this.loading     = false;

        // Auto-expand replies for every discussion that has them so users
        // see comments immediately without having to click.
        this.discussions.forEach((d) => {
          if (d.commentCount > 1) {
            this.expandedDiscIds.add(d.id);
            this.loadComments(d);
          }
        });

        m.redraw();
      })
      .catch(() => {
        this.discussions = [];
        this.loadError   = true;
        this.loading     = false;
        m.redraw();
      });
  }

  static REACTIONS = [
    { key: 'like',  emoji: '👍', label: 'Like' },
    { key: 'heart', emoji: '❤️', label: 'Love' },
    { key: 'haha',  emoji: '😂', label: 'Haha' },
    { key: 'wow',   emoji: '😮', label: 'Wow' },
    { key: 'sad',   emoji: '😢', label: 'Sad' },
    { key: 'angry', emoji: '😡', label: 'Angry' },
  ];

  // ── Reaction picker ───────────────────────────────────────────────────────

  togglePicker(discId) {
    this.pickerDiscId = this.pickerDiscId === discId ? null : discId;
    m.redraw();
  }

  toggleReaction(d, reactionKey) {
    if (!app.session.user || !d.firstPost || !d.firstPost.id) return;
    const fp = d.firstPost;

    const prevReaction  = fp.actorReaction;
    const prevReactions = { ...(fp.reactions || {}) };
    const nextReaction  = prevReaction === reactionKey ? null : reactionKey;

    fp.actorReaction = nextReaction;
    const updated = { ...prevReactions };
    if (prevReaction) { updated[prevReaction] = Math.max(0, (updated[prevReaction] || 0) - 1); if (!updated[prevReaction]) delete updated[prevReaction]; }
    if (nextReaction) { updated[nextReaction] = (updated[nextReaction] || 0) + 1; }
    fp.reactions      = updated;
    this.pickerDiscId = null;
    m.redraw();

    const reactUrl = nextReaction
      ? `${apiBase()}/sg-posts/${fp.id}/react`
      : `${apiBase()}/sg-posts/${fp.id}/unreact`;
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
        fp.reactions     = data.reactions || {};
        fp.actorReaction = data.actorReaction || null;
        m.redraw();
      })
      .catch(() => {
        fp.reactions     = prevReactions;
        fp.actorReaction = prevReaction;
        m.redraw();
      });
  }

  // ── Comment reactions (inline comment cards in the feed) ─────────────────

  toggleCommentReaction(post, reactionKey) {
    if (!app.session.user || !post || !post.id) return;

    const prevReaction  = post.actorReaction;
    const prevReactions = { ...(post.reactions || {}) };
    const nextReaction  = prevReaction === reactionKey ? null : reactionKey;

    // Optimistic update
    post.actorReaction = nextReaction;
    const updated = { ...prevReactions };
    if (prevReaction) { updated[prevReaction] = Math.max(0, (updated[prevReaction] || 0) - 1); if (!updated[prevReaction]) delete updated[prevReaction]; }
    if (nextReaction) { updated[nextReaction] = (updated[nextReaction] || 0) + 1; }
    post.reactions      = updated;
    this.pickerCommentId = null;
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
      .then((r) => r.ok ? r.json() : r.json().then((e) => { throw new Error(e.error || 'Error'); }))
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

  // ── Create post (discussion + first post) ────────────────────────────────

  submitPost() {
    const content = this.postText.trim();
    if ((!content && !this.poll) || this.postSubmitting) return;

    this.postSubmitting = true;
    this.postError      = null;

    fetch(`${apiBase()}/sg-discussions`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({
        groupId:     this.attrs.groupId,
        content,
        linkPreview: this.linkPreview || null,
        poll:        this.poll && this.poll.question.trim() && this.poll.options.filter((o) => o.trim()).length >= 2
          ? {
              question:      this.poll.question.trim(),
              options:       this.poll.options.filter((o) => o.trim()),
              isMultiSelect: this.poll.isMultiSelect,
            }
          : null,
      }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((d) => {
        this.discussions    = [d, ...(this.discussions || [])];
        this.total++;
        this.postText       = '';
        this.postFocused    = false;
        this.postSubmitting = false;
        this.poll           = null;
        revokeAll(this.postUploads);
        this.postUploads    = [];
        clearLinkPreview(this);
        m.redraw();
      })
      .catch((err) => {
        this.postError      = err.message;
        this.postSubmitting = false;
        m.redraw();
      });
  }

  // ── Add a reply comment to an existing discussion ────────────────────────

  submitReply(d) {
    const content = (this.replyTexts[d.id] || '').trim();
    if (!content || this.replySubmitting[d.id]) return;

    this.replySubmitting[d.id] = true;

    fetch(`${apiBase()}/sg-posts`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ discussionId: d.id, content }),
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((post) => {
        d.commentCount = (d.commentCount || 0) + 1;
        this.replyTexts[d.id]      = '';
        this.replySubmitting[d.id] = false;
        // Auto-expand the inline comment section and append the new post.
        this.expandedDiscIds.add(d.id);
        if (!this.loadedComments[d.id]) this.loadedComments[d.id] = [];
        // Deduplicate in case a WebSocket echo arrives first.
        if (!this.loadedComments[d.id].some((c) => c.id === post.id)) {
          this.loadedComments[d.id] = [...this.loadedComments[d.id], post];
        }
        m.redraw();
      })
      .catch(() => {
        this.replySubmitting[d.id] = false;
        m.redraw();
      });
  }

  // ── Delete discussion ────────────────────────────────────────────────────

  deleteDiscussion(d) {
    if (!confirm(app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete_confirm'))) return;
    this.deleting   = d.id;
    this.openMenuId = null;

    fetch(`${apiBase()}/sg-discussions/${d.id}`, {
      method:      'DELETE',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    }).then(() => {
      this.discussions = this.discussions.filter((x) => x.id !== d.id);
      this.total       = Math.max(0, this.total - 1);
      this.deleting    = null;
      m.redraw();
    }).catch(() => {
      this.deleting = null;
      m.redraw();
    });
  }

  pinDiscussion(d) {
    const wasPinned = d.isPinned;
    d.isPinned = !wasPinned;
    this.openMenuId = null;
    m.redraw();

    fetch(`${apiBase()}/sg-discussions/${d.id}/pin`, {
      method:      'PATCH',
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.json())
      .then((data) => {
        d.isPinned = data.isPinned;
        // Re-sort: pinned items first
        this.discussions = [
          ...this.discussions.filter((x) => x.isPinned),
          ...this.discussions.filter((x) => !x.isPinned),
        ];
        m.redraw();
      })
      .catch(() => {
        d.isPinned = wasPinned;
        m.redraw();
      });
  }

  // ── Inline comments ──────────────────────────────────────────────────────

  loadComments(d) {
    if (this.commentsLoading[d.id]) return;
    this.commentsLoading[d.id] = true;
    m.redraw();

    fetch(`${apiBase()}/sg-thread-posts/${d.id}`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.ok ? r.json() : r.json().then((e) => { throw new Error(e.error || 'Error'); }))
      .then((data) => {
        // posts[0] is the first post (already shown as card body) — skip it.
        this.loadedComments[d.id]  = (data.data || []).slice(1);
        this.commentsLoading[d.id] = false;
        m.redraw();
      })
      .catch(() => {
        this.commentsLoading[d.id] = false;
        m.redraw();
      });
  }

  // ── @mention helpers ────────────────────────────────────────────────────

  loadMembers() {
    if (this.members !== null || this.membersLoading) return;
    this.membersLoading = true;
    fetch(`${apiBase()}/social-groups/${this.attrs.groupId}/members`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => r.ok ? r.json() : Promise.reject())
      .then((data) => {
        this.members        = data.data || [];
        this.membersLoading = false;
        m.redraw();
      })
      .catch(() => {
        this.members        = [];
        this.membersLoading = false;
      });
  }

  // Called from oninput — detects whether the cursor is right after an @mention.
  handleMentionInput(discId, e) {
    const ta  = e.target;
    const pos = ta.selectionStart;
    const before = ta.value.slice(0, pos);
    // Match '@' followed by word chars at end of text before cursor.
    const match = before.match(/@([\w-]*)$/);
    if (match) {
      this.mentionQuery  = match[1];
      this.mentionDiscId = discId;
      this._mentionTa    = ta;
      this.loadMembers();
    } else if (this.mentionDiscId === discId) {
      this.mentionQuery  = null;
      this.mentionDiscId = null;
    }
  }

  insertMention(member) {
    const ta = this._mentionTa;
    if (!ta) return;

    const pos    = ta.selectionStart;
    const text   = ta.value;
    const before = text.slice(0, pos);
    const match  = before.match(/@([\w-]*)$/);
    if (!match) { this.mentionQuery = null; return; }

    const start    = pos - match[0].length;
    const inserted = '@' + member.displayName + ' ';
    const newText  = text.slice(0, start) + inserted + text.slice(pos);

    if (this.mentionDiscId === 'feed') {
      this.postText = newText;
    } else {
      this.replyTexts[this.mentionDiscId] = newText;
    }

    this.mentionQuery  = null;
    this.mentionDiscId = null;
    m.redraw();

    // Restore cursor to end of inserted mention.
    requestAnimationFrame(() => {
      if (!ta.isConnected) return;
      const newPos = start + inserted.length;
      ta.value = newText;
      ta.focus();
      ta.setSelectionRange(newPos, newPos);
    });
  }

  viewMentionDropdown(discId) {
    if (this.mentionDiscId !== discId || this.mentionQuery === null) return null;

    const query   = this.mentionQuery.toLowerCase();
    const members = (this.members || [])
      .filter((mbr) =>
        mbr.displayName.toLowerCase().includes(query) ||
        (mbr.slug || '').toLowerCase().includes(query)
      )
      .slice(0, 7);

    if (!members.length && !this.membersLoading) return null;

    return m('.SGFeed-mentionDropdown', [
      this.membersLoading && !this.members
        ? m('.SGFeed-mentionLoading', m('i.fas.fa-spinner.fa-spin'))
        : members.map((mbr) =>
            m('button.SGFeed-mentionItem', {
              key:         mbr.userId,
              type:        'button',
              // onmousedown prevents blurring the textarea before the click registers.
              onmousedown: (e) => { e.preventDefault(); this.insertMention(mbr); },
            }, [
              mbr.avatarUrl
                ? m('img.SGFeed-mentionAvatar', { src: mbr.avatarUrl, alt: '' })
                : m('span.SGFeed-mentionInitial', (mbr.displayName || '?')[0].toUpperCase()),
              m('span.SGFeed-mentionName', mbr.displayName),
              mbr.role && mbr.role !== 'member'
                ? m('span.SGFeed-mentionRole', mbr.role)
                : null,
            ])
          ),
    ]);
  }

  toggleComments(d) {
    if (this.expandedDiscIds.has(d.id)) {
      this.expandedDiscIds.delete(d.id);
    } else {
      this.expandedDiscIds.add(d.id);
      if (!this.loadedComments[d.id]) {
        this.loadComments(d);
      }
    }
    m.redraw();
  }

  viewInlineComments(d) {
    if (!this.expandedDiscIds.has(d.id)) return null;

    const comments = this.loadedComments[d.id];
    const loading  = this.commentsLoading[d.id];

    if (loading && !comments) {
      return m('.SGFeed-comments',
        m('.SGFeed-commentsLoading', m('i.fas.fa-spinner.fa-spin'))
      );
    }

    if (!comments || comments.length === 0) {
      return m('.SGFeed-comments',
        m('.SGFeed-commentsEmpty', 'No comments yet. Be the first!')
      );
    }

    // Show the most recent 3 inline; offer "View all N comments" above if more.
    const PREVIEW = 3;
    const shown   = comments.slice(-PREVIEW);
    const hidden  = Math.max(0, comments.length - PREVIEW);
    const actor   = app.session.user;

    return m('.SGFeed-comments', [
      hidden > 0
        ? m('button.SGFeed-viewAllBtn', {
            onclick: () => this.openThread(d),
          }, `View all ${comments.length} comments in thread`)
        : null,
      shown.map((post) => {
        const user        = post.user;
        const reactions   = post.reactions || {};
        const totalReact  = Object.values(reactions).reduce((s, c) => s + Number(c), 0);
        const actorReact  = post.actorReaction || null;
        const pickerOpen  = this.pickerCommentId === post.id;
        const activeEmoji = actorReact ? GroupFeed.REACTIONS.find((r) => r.key === actorReact) : null;

        const topEmojis = Object.entries(reactions)
          .filter(([, c]) => Number(c) > 0)
          .sort(([, a], [, b]) => Number(b) - Number(a))
          .slice(0, 3)
          .map(([key]) => GroupFeed.REACTIONS.find((r) => r.key === key)?.emoji || '👍');

        return m('.SGFeed-comment', { key: post.id }, [
          // Avatar
          m('.SGFeed-commentAvatar', [
            user?.avatarUrl
              ? m('img', { src: user.avatarUrl, alt: user.displayName })
              : m('span.SGFeed-commentInitial', (user?.displayName || '?')[0].toUpperCase()),
          ]),

          // Right column: bubble + footer
          m('.SGFeed-commentRight', [
            // Content bubble
            m('.SGFeed-commentBody', [
              m('span.SGFeed-commentAuthor', user?.displayName || ''),
              m('.SGFeed-commentContent', m.trust(post.contentParsed || post.content || '')),
            ]),

            // Footer: time · React button · reaction count bubble
            m('.SGFeed-commentFooter', [
              m('span.SGFeed-commentTime', humanTime(new Date(post.createdAt))),

              // React button + picker
              actor
                ? m('.SGFeed-commentReactWrap', [
                    pickerOpen
                      ? m('.SGFeed-commentPicker',
                          GroupFeed.REACTIONS.map((r) =>
                            m('button.SGFeed-pickerBtn', {
                              key:     r.key,
                              title:   r.label,
                              class:   actorReact === r.key ? 'is-active' : '',
                              onclick: (e) => {
                                e.stopPropagation();
                                this.toggleCommentReaction(post, r.key);
                              },
                            }, [m('span.SGFeed-pickerEmoji', r.emoji), m('span.SGFeed-pickerLabel', r.label)])
                          ))
                      : null,
                    m('button.SGFeed-commentReactBtn', {
                      class:   activeEmoji ? 'is-active' : '',
                      title:   activeEmoji ? `Remove ${activeEmoji.label}` : 'React',
                      onclick: (e) => {
                        e.stopPropagation();
                        if (activeEmoji) {
                          this.toggleCommentReaction(post, actorReact);
                        } else {
                          this.pickerCommentId = pickerOpen ? null : post.id;
                          m.redraw();
                        }
                      },
                    }, activeEmoji
                        ? [activeEmoji.emoji, ' ', activeEmoji.label]
                        : [m('i.fas.fa-smile-beam'), ' React']),
                  ])
                : null,

              // Reaction stat bubble (emoji stack + total count)
              totalReact > 0
                ? m('span.SGFeed-commentReactStat', [
                    topEmojis.map((emoji) => m('span.SGFeed-commentReactEmoji', emoji)),
                    ' ',
                    totalReact,
                  ])
                : null,
            ]),
          ]),
        ]);
      }),
    ]);
  }

  openThread(d) {
    m.route.set(app.route('ernestdefoe-social-groups.discussion', {
      slug:         this.attrs.groupSlug,
      discussionId: d.id,
    }));
  }

  // ── Views ─────────────────────────────────────────────────────────────────

  view() {
    const { isMember } = this.attrs;
    const actor        = app.session.user;

    return m('.SGFeed', [
      // Composer
      actor && isMember ? this.viewComposer(actor) : null,

      // Search bar
      m('.SGFeed-search', [
        m('i.fas.fa-search.SGFeed-searchIcon'),
        m('input.SGFeed-searchInput', {
          type:        'text',
          placeholder: 'Search posts…',
          value:       this.searchQuery,
          oninput:     (e) => {
            this.searchQuery = e.target.value;
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => this.load(1, this.searchQuery.trim()), 400);
          },
        }),
        this.searchQuery.trim()
          ? m('button.SGFeed-searchClear', {
              onclick: () => {
                this.searchQuery = '';
                this.load(1);
              },
            }, m('i.fas.fa-times'))
          : null,
      ]),

      // Feed
      this.loading
        ? m('.SGFeed-loading', m(LoadingIndicator, { display: 'block' }))
        : this.loadError
        ? m('.SGFeed-empty', [
            m('i.fas.fa-exclamation-circle'),
            m('p', 'Failed to load posts. Please try refreshing the page.'),
          ])
        : !this.discussions || this.discussions.length === 0
        ? m('.SGFeed-empty', [
            m('i.fas.fa-search'),
            m('p', this.searchQuery
                ? `No posts found for "${this.searchQuery}".`
                : app.translator.trans('ernestdefoe-social-groups.forum.discussions.empty')),
          ])
        : [
            m('.SGFeed-list',
              this.discussions.map((d) => this.viewPostCard(d))
            ),
            this.pages > 1
              ? m('.SGFeed-pagination', [
                  m(Button, {
                    class:    'Button',
                    disabled: this.page <= 1,
                    onclick:  () => this.load(this.page - 1),
                    'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.discussions.prev_page'),
                  }, m('i.fas.fa-chevron-left')),
                  m('span.SGFeed-pageInfo', `${this.page} / ${this.pages}`),
                  m(Button, {
                    class:    'Button',
                    disabled: this.page >= this.pages,
                    onclick:  () => this.load(this.page + 1),
                    'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.discussions.next_page'),
                  }, m('i.fas.fa-chevron-right')),
                ])
              : null,
          ],
    ]);
  }

  viewComposer(actor) {
    const expanded = this.postFocused || this.postText.trim().length > 0;

    return m('.SGFeed-composer', [
      m('.SGFeed-composerAvatar', [
        actor.attribute('avatarUrl')
          ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
          : m('span.SGFeed-composerInitial', (actor.attribute('displayName') || '?')[0].toUpperCase()),
      ]),
      m('.SGFeed-composerRight', [
        this.postError ? m('.Alert.Alert--error', { style: 'margin-bottom:8px' }, this.postError) : null,
        m('textarea.SGFeed-composerTextarea', {
          placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.feed_placeholder'),
          value:       this.postText,
          rows:        expanded ? 3 : 1,
          onfocus:     () => { this.postFocused = true; m.redraw(); },
          oninput:     (e) => {
            this.postText = e.target.value;
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
            scheduleLinkPreview(this, e.target.value);
            this.handleMentionInput('feed', e);
          },
          onkeydown: (e) => {
            if (e.key === 'Escape' && this.mentionQuery !== null) {
              e.stopPropagation();
              this.mentionQuery = null; this.mentionDiscId = null; m.redraw();
            }
          },
          onpaste: (e) => {
            const imgs = pastedImages(e);
            if (imgs.length) { e.preventDefault(); handleFiles(this, imgs, 'postUploads', 'postText'); }
          },
          disabled: this.postSubmitting,
        }),
        viewUploadChips(this.postUploads, (id) => removeUpload(this, id, 'postUploads', 'postText')),
        viewComposerLinkPreview(this),
        this.poll ? this.viewPollComposer() : null,
        this.viewMentionDropdown('feed'),
        expanded
          ? m('.SGFeed-composerActions', [
              m('label.SGFeed-composerAttach', {
                title: app.translator.trans('ernestdefoe-social-groups.forum.discussions.upload_image'),
              }, [
                m('input[type=file]', {
                  accept:   'image/*',
                  multiple: true,
                  style:    'display:none',
                  disabled: this.postSubmitting,
                  onchange: (e) => {
                    if (e.target.files.length) handleFiles(this, Array.from(e.target.files), 'postUploads', 'postText');
                    e.target.value = '';
                  },
                }),
                m('i.fas.fa-paperclip'),
              ]),
              m('button.SGFeed-pollToggle', {
                class:   this.poll ? 'is-active' : '',
                title:   this.poll ? 'Remove poll' : 'Add poll',
                onclick: () => {
                  this.poll = this.poll
                    ? null
                    : { question: '', options: ['', ''], isMultiSelect: false };
                  m.redraw();
                },
              }, m('i.fas.fa-poll')),
              m('button.SGFeed-cancelBtn', {
                onclick: () => {
                  revokeAll(this.postUploads);
                  this.postUploads = [];
                  this.postText    = '';
                  this.postFocused = false;
                  this.poll        = null;
                  clearLinkPreview(this);
                  m.redraw();
                },
              }, app.translator.trans('ernestdefoe-social-groups.forum.discussions.cancel_edit')),
              m('button.SGFeed-postBtn', {
                disabled: this.postSubmitting || (!this.postText.trim() && !this.postUploads.length && !this.poll) || this.postUploads.some((u) => u.uploading),
                onclick:  () => this.submitPost(),
              }, this.postSubmitting
                  ? m('i.fas.fa-spinner.fa-spin')
                  : app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_button')),
            ])
          : null,
      ]),
    ]);
  }

  viewPollComposer() {
    const p = this.poll;
    return m('.SGFeed-pollComposer', [
      m('.SGFeed-pollComposer-header', [
        m('span', [m('i.fas.fa-poll'), ' Poll']),
        m('label.SGFeed-pollComposer-multiToggle', [
          m('input[type=checkbox]', {
            checked:  p.isMultiSelect,
            onchange: (e) => { p.isMultiSelect = e.target.checked; m.redraw(); },
          }),
          ' Allow multiple choices',
        ]),
      ]),
      m('input.FormControl.SGFeed-pollComposer-question', {
        type:        'text',
        placeholder: 'Ask a question…',
        value:       p.question,
        maxlength:   500,
        oninput:     (e) => { p.question = e.target.value; },
      }),
      p.options.map((opt, i) =>
        m('.SGFeed-pollComposer-optRow', { key: i }, [
          m('input.FormControl.SGFeed-pollComposer-opt', {
            type:        'text',
            placeholder: `Option ${i + 1}`,
            value:       opt,
            maxlength:   255,
            oninput:     (e) => { p.options[i] = e.target.value; },
          }),
          p.options.length > 2
            ? m('button.SGFeed-pollComposer-removeOpt', {
                onclick: () => { p.options.splice(i, 1); m.redraw(); },
              }, m('i.fas.fa-times'))
            : null,
        ])
      ),
      p.options.length < 6
        ? m('button.SGFeed-pollComposer-addOpt', {
            onclick: () => { p.options.push(''); m.redraw(); },
          }, [m('i.fas.fa-plus'), ' Add option'])
        : null,
    ]);
  }

  votePoll(d, optionId) {
    if (!app.session.user || !d.poll) return;
    const poll = d.poll;
    const actor = app.session.user;

    const alreadyVoted = poll.actorVotedOptionIds.includes(optionId);
    let newVoteIds;
    if (poll.isMultiSelect) {
      newVoteIds = alreadyVoted
        ? poll.actorVotedOptionIds.filter((id) => id !== optionId)
        : [...poll.actorVotedOptionIds, optionId];
    } else {
      newVoteIds = alreadyVoted ? [] : [optionId];
    }

    // Optimistic update
    const prevVoteIds = [...poll.actorVotedOptionIds];
    const prevCounts  = poll.options.map((o) => o.voteCount);
    poll.actorVotedOptionIds = newVoteIds;
    poll.options.forEach((o) => {
      const wasVoted = prevVoteIds.includes(o.id);
      const isVoted  = newVoteIds.includes(o.id);
      if (wasVoted && !isVoted) o.voteCount = Math.max(0, o.voteCount - 1);
      if (!wasVoted && isVoted) o.voteCount = o.voteCount + 1;
    });
    poll.totalVotes = poll.options.reduce((s, o) => s + o.voteCount, 0);
    m.redraw();

    fetch(`${apiBase()}/sg-polls/${poll.id}/vote`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': app.session.csrfToken || '',
      },
      body: JSON.stringify({ optionIds: newVoteIds }),
    })
      .then((r) => r.ok ? r.json() : r.json().then((e) => { throw new Error(e.error || 'Error'); }))
      .then((updated) => {
        d.poll = updated;
        m.redraw();
      })
      .catch(() => {
        poll.actorVotedOptionIds = prevVoteIds;
        poll.options.forEach((o, i) => { o.voteCount = prevCounts[i]; });
        poll.totalVotes = prevCounts.reduce((s, c) => s + c, 0);
        m.redraw();
      });
  }

  viewPoll(d) {
    const poll  = d.poll;
    const actor = app.session.user;
    if (!poll) return null;

    const ended  = poll.endsAt && new Date(poll.endsAt) < new Date();
    const canVote = actor && !ended;
    const max    = Math.max(1, ...poll.options.map((o) => o.voteCount));

    return m('.SGFeed-poll', [
      m('.SGFeed-poll-question', [m('i.fas.fa-poll'), ' ', poll.question]),
      m('.SGFeed-poll-options',
        poll.options.map((opt) => {
          const voted  = poll.actorVotedOptionIds.includes(opt.id);
          const pct    = poll.totalVotes > 0 ? Math.round((opt.voteCount / poll.totalVotes) * 100) : 0;
          return m('button.SGFeed-poll-option', {
            key:      opt.id,
            class:    voted ? 'is-voted' : '',
            disabled: !canVote,
            onclick:  () => canVote && this.votePoll(d, opt.id),
          }, [
            m('.SGFeed-poll-optBar', { style: `width:${pct}%` }),
            m('span.SGFeed-poll-optText', opt.text),
            m('span.SGFeed-poll-optPct', `${pct}%`),
            voted ? m('i.fas.fa-check.SGFeed-poll-check') : null,
          ]);
        })
      ),
      m('.SGFeed-poll-footer', [
        m('span', `${poll.totalVotes} vote${poll.totalVotes !== 1 ? 's' : ''}`),
        ended ? m('span.SGFeed-poll-ended', ' · Ended') : null,
      ]),
    ]);
  }

  viewPostCard(d) {
    const fp         = d.firstPost;
    const isDeleting = this.deleting === d.id;
    const menuOpen   = this.openMenuId === d.id;
    const actor      = app.session.user;
    const replyText  = this.replyTexts[d.id] || '';
    const replyBusy  = !!this.replySubmitting[d.id];

    // Use firstPost user if available, fall back to discussion user
    const postUser = fp?.user || d.user;
    const postTime = fp?.createdAt || d.createdAt;

    return m('.SGFeed-post', { key: d.id, class: isDeleting ? 'is-deleting' : '' }, [

      // Header: avatar + name/time + menu
      m('.SGFeed-postHeader', [
        m('.SGFeed-postAvatar', [
          postUser?.avatarUrl
            ? m('img', { src: postUser.avatarUrl, alt: postUser.displayName })
            : m('span.SGFeed-postInitial', (postUser?.displayName || '?')[0].toUpperCase()),
        ]),
        m('.SGFeed-postMeta', [
          m('span.SGFeed-postAuthor', postUser?.displayName || ''),
          m('span.SGFeed-postTime', { title: postTime }, humanTime(new Date(postTime))),
          d.isPinned ? m('span.SGFeed-pinnedBadge', [m('i.fas.fa-thumbtack'), ' Pinned']) : null,
        ]),
        d.canDelete || d.canPin || (actor && d.canShare)
          ? m('.SGFeed-postMenu', [
              m('button.SGFeed-postMenuBtn', {
                onclick: (e) => {
                  e.stopPropagation();
                  this.openMenuId = menuOpen ? null : d.id;
                  m.redraw();
                },
              }, m('i.fas.fa-ellipsis-h')),
              menuOpen
                ? m('.SGFeed-postDropdown', [
                    actor && d.canShare
                      ? m('button.SGFeed-dropdownItem', {
                          onclick: () => {
                            this.openMenuId = null;
                            app.modal.show(ShareDiscussionModal, {
                              discussionId:   d.id,
                              currentGroupId: this.attrs.groupId,
                            });
                          },
                        }, [m('i.fas.fa-share'), ' Share post'])
                      : null,
                    d.canPin
                      ? m('button.SGFeed-dropdownItem', {
                          onclick: () => this.pinDiscussion(d),
                        }, d.isPinned
                            ? [m('i.fas.fa-thumbtack'), ' Unpin post']
                            : [m('i.fas.fa-thumbtack'), ' Pin post'])
                      : null,
                    d.canDelete
                      ? m('button.SGFeed-dropdownItem.SGFeed-dropdownItem--danger', {
                          onclick: () => this.deleteDiscussion(d),
                        }, [m('i.fas.fa-trash'), ' ',
                            app.translator.trans('ernestdefoe-social-groups.forum.discussions.delete')])
                      : null,
                  ])
                : null,
            ])
          : null,
      ]),

      // Post body content
      fp
        ? m('.SGFeed-postContent', m.trust(fp.contentParsed))
        : m('.SGFeed-postContent', m('.SGFeed-noContent', d.title)),
      fp ? viewPostLinkPreview(fp) : null,

      // Shared-from quoted card
      d.sharedFrom
        ? m('a.SGFeed-sharedCard', {
            href: `/groups/${d.sharedFrom.groupSlug}/d/${d.sharedFrom.discussionId}`,
            onclick: (e) => { e.preventDefault(); m.route.set(`/groups/${d.sharedFrom.groupSlug}/d/${d.sharedFrom.discussionId}`); },
          }, [
            m('.SGFeed-sharedCard-header', [
              d.sharedFrom.user?.avatarUrl
                ? m('img.SGFeed-sharedCard-avatar', { src: d.sharedFrom.user.avatarUrl, alt: '' })
                : m('span.SGFeed-sharedCard-initial',
                    (d.sharedFrom.user?.displayName || '?')[0].toUpperCase()),
              m('span.SGFeed-sharedCard-author', d.sharedFrom.user?.displayName || ''),
              m('span.SGFeed-sharedCard-group', [m('i.fas.fa-users'), ' ', d.sharedFrom.groupName]),
            ]),
            m('.SGFeed-sharedCard-title', d.sharedFrom.title),
            d.sharedFrom.snippet
              ? m('.SGFeed-sharedCard-snippet', d.sharedFrom.snippet)
              : null,
          ])
        : null,

      // Poll
      d.poll ? this.viewPoll(d) : null,

      // Reaction count + comment count stat bar
      (() => {
        const reactions  = fp?.reactions || {};
        const totalReact = Object.values(reactions).reduce((s, c) => s + Number(c), 0);
        const hasComments = d.commentCount > 1;
        if (!totalReact && !hasComments) return null;

        const topEmojis = Object.entries(reactions)
          .filter(([, c]) => Number(c) > 0)
          .sort(([, a], [, b]) => Number(b) - Number(a))
          .slice(0, 3)
          .map(([key]) => GroupFeed.REACTIONS.find((r) => r.key === key)?.emoji || '👍');

        return m('.SGFeed-postStatBar', [
          totalReact > 0
            ? m('span.SGFeed-statLikes', [
                topEmojis.map((emoji) => m('span.SGFeed-reactionEmoji', emoji)),
                ' ', totalReact,
              ])
            : null,
          totalReact > 0 && hasComments ? m('span.SGFeed-statDot', '·') : null,
          hasComments
            ? m('button.SGFeed-statComments', { onclick: () => this.toggleComments(d) },
                app.translator.trans('ernestdefoe-social-groups.forum.discussions.comments_count', { count: d.commentCount - 1 }))
            : null,
        ]);
      })(),

      // Reaction | Comment action bar
      m('.SGFeed-postActionBar', [
        actor && fp
          ? m('.SGFeed-reactWrap', [
              this.pickerDiscId === d.id
                ? m('.SGFeed-reactionPicker',
                    GroupFeed.REACTIONS.map((r) =>
                      m('button.SGFeed-pickerBtn', {
                        key:     r.key,
                        title:   r.label,
                        class:   fp.actorReaction === r.key ? 'is-active' : '',
                        onclick: (e) => { e.stopPropagation(); this.pickerDiscId = null; this.toggleReaction(d, r.key); },
                      }, [m('span.SGFeed-pickerEmoji', r.emoji), m('span.SGFeed-pickerLabel', r.label)])
                    ))
                : null,
              (() => {
                const active = fp.actorReaction
                  ? GroupFeed.REACTIONS.find((r) => r.key === fp.actorReaction)
                  : null;
                return m('button.SGFeed-reactBtn', {
                  class:   active ? 'SGFeed-reactBtn--active' : '',
                  onclick: (e) => {
                    e.stopPropagation();
                    if (active) {
                      this.toggleReaction(d, fp.actorReaction);
                    } else {
                      this.togglePicker(d.id);
                    }
                  },
                }, active
                    ? [active.emoji, ' ', active.label]
                    : [m('i.fas.fa-smile-beam'), ' React']);
              })(),
            ])
          : null,
        m('button.SGFeed-commentBtn', {
          class:   this.expandedDiscIds.has(d.id) ? 'is-active' : '',
          onclick: () => this.toggleComments(d),
        }, [m('i.fas.fa-comment'), ' ',
            this.expandedDiscIds.has(d.id)
              ? 'Hide Comments'
              : app.translator.trans('ernestdefoe-social-groups.forum.discussions.view_comments')]),
      ]),

      // Inline comments list (toggled by the Comments button)
      this.viewInlineComments(d),

      // Inline reply composer (for quick replies; posts to the discussion)
      actor && this.attrs.isMember && !d.isLocked
        ? m('.SGFeed-replyRow', [
            m('.SGFeed-replyAvatar', [
              actor.attribute('avatarUrl')
                ? m('img', { src: actor.attribute('avatarUrl'), alt: actor.attribute('displayName') })
                : m('span', (actor.attribute('displayName') || '?')[0].toUpperCase()),
            ]),
            m('.SGFeed-replyInputWrap', [
              this.viewMentionDropdown(d.id),
              m('textarea.SGFeed-replyInput', {
                placeholder: app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_placeholder'),
                value:       replyText,
                rows:        1,
                disabled:    replyBusy,
                oninput:     (e) => {
                  this.replyTexts[d.id] = e.target.value;
                  e.target.style.height = 'auto';
                  e.target.style.height = e.target.scrollHeight + 'px';
                  this.handleMentionInput(d.id, e);
                },
                onkeydown: (e) => {
                  if (e.key === 'Escape' && this.mentionQuery !== null) {
                    e.stopPropagation();
                    this.mentionQuery = null; this.mentionDiscId = null; m.redraw();
                    return;
                  }
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.submitReply(d);
                  }
                },
              }),
              m('button.SGFeed-replySendBtn', {
                disabled: replyBusy || !replyText.trim(),
                onclick:  () => this.submitReply(d),
                title:    'Post comment',
              }, replyBusy
                  ? m('i.fas.fa-spinner.fa-spin')
                  : m('i.fas.fa-paper-plane')),
            ]),
          ])
        : null,
    ]);
  }
}
