import { apiGet, apiPost, apiPatch, apiDelete } from '../utils/api';
import { pastedImages, handleFiles, removeUpload, revokeAll, viewUploadChips } from '../utils/uploads';
import { scheduleLinkPreview, clearLinkPreview, viewComposerLinkPreview } from '../utils/linkPreview';
import { MentionDropdown } from './feed/MentionDropdown';
import { PollComposer } from './feed/PollComposer';
import { InlineCommentList } from './feed/InlineCommentList';
import PostCard from './feed/PostCard';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';

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

    apiGet(`/sg-discussions/${groupId}`, { page, ...(q ? { q } : {}) })
      .then((data) => {
        this.discussions = data.data || [];
        this.total       = data.total || 0;
        this.pages       = data.pages || 1;
        this.loadError   = false;
        this.loading     = false;

        // Comments are loaded lazily when the user clicks the Comments
        // button (see toggleComments). The previous behaviour fanned out
        // one /sg-thread-posts/{id} request per discussion on every page
        // load, hammering the DB with up to 20 simultaneous full-table
        // scans on social_group_posts ordered by (is_pinned, created_at).

        m.redraw();
      })
      .catch(() => {
        this.discussions = [];
        this.loadError   = true;
        this.loading     = false;
        m.redraw();
      });
  }

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

    const reactionRequest = nextReaction
      ? apiPost(`/sg-posts/${fp.id}/react`, { reaction: nextReaction })
      : apiPost(`/sg-posts/${fp.id}/unreact`);
    reactionRequest
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

    const reactionRequest = nextReaction
      ? apiPost(`/sg-posts/${post.id}/react`, { reaction: nextReaction })
      : apiPost(`/sg-posts/${post.id}/unreact`);
    reactionRequest
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

    apiPost('/sg-discussions', {
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
        this.postError      = err.response?.error || err.message || 'Error';
        this.postSubmitting = false;
        m.redraw();
      });
  }

  // ── Add a reply comment to an existing discussion ────────────────────────

  submitReply(d) {
    const content = (this.replyTexts[d.id] || '').trim();
    if (!content || this.replySubmitting[d.id]) return;

    this.replySubmitting[d.id] = true;

    apiPost('/sg-posts', { discussionId: d.id, content })
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

    apiDelete(`/sg-discussions/${d.id}`)
      .then(() => {
        this.discussions = this.discussions.filter((x) => x.id !== d.id);
        this.total       = Math.max(0, this.total - 1);
        this.deleting    = null;
        m.redraw();
      })
      .catch(() => {
        this.deleting = null;
        m.redraw();
      });
  }

  pinDiscussion(d) {
    const wasPinned = d.isPinned;
    d.isPinned = !wasPinned;
    this.openMenuId = null;
    m.redraw();

    apiPatch(`/sg-discussions/${d.id}/pin`)
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

    apiGet(`/sg-thread-posts/${d.id}`)
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
    apiGet(`/social-groups/${this.attrs.groupId}/members`)
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
    if (this.mentionDiscId !== discId) return null;

    return MentionDropdown({
      members:  this.members,
      loading:  this.membersLoading,
      query:    this.mentionQuery,
      onSelect: (member) => this.insertMention(member),
    });
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

    return InlineCommentList({
      comments:        this.loadedComments[d.id],
      loading:         !!this.commentsLoading[d.id],
      pickerCommentId: this.pickerCommentId,
      onPickReaction:  (post, key) => this.toggleCommentReaction(post, key),
      onTogglePicker:  (id) => { this.pickerCommentId = id; m.redraw(); },
      onOpenThread:    () => this.openThread(d),
    });
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
        m('i.fa-solid.fa-magnifying-glass.SGFeed-searchIcon'),
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
            }, m('i.fa-solid.fa-xmark'))
          : null,
      ]),

      // Feed
      this.loading
        ? m('.SGFeed-loading', m(LoadingIndicator, { display: 'block' }))
        : this.loadError
        ? m('.SGFeed-empty', [
            m('i.fa-solid.fa-circle-exclamation'),
            m('p', app.translator.trans('ernestdefoe-social-groups.forum.discussions.load_error')),
          ])
        : !this.discussions || this.discussions.length === 0
        ? m('.SGFeed-empty', [
            m('i.fa-solid.fa-magnifying-glass'),
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
                  }, m('i.fa-solid.fa-chevron-left')),
                  m('span.SGFeed-pageInfo', `${this.page} / ${this.pages}`),
                  m(Button, {
                    class:    'Button',
                    disabled: this.page >= this.pages,
                    onclick:  () => this.load(this.page + 1),
                    'aria-label': app.translator.trans('ernestdefoe-social-groups.forum.discussions.next_page'),
                  }, m('i.fa-solid.fa-chevron-right')),
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
        this.poll ? PollComposer({ poll: this.poll, onChange: () => m.redraw() }) : null,
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
                m('i.fa-solid.fa-paperclip'),
              ]),
              m('button.SGFeed-pollToggle', {
                class:   this.poll ? 'is-active' : '',
                title:   this.poll ? app.translator.trans('ernestdefoe-social-groups.forum.discussions.poll_remove') : app.translator.trans('ernestdefoe-social-groups.forum.discussions.poll_add'),
                onclick: () => {
                  this.poll = this.poll
                    ? null
                    : { question: '', options: ['', ''], isMultiSelect: false };
                  m.redraw();
                },
              }, m('i.fa-solid.fa-square-poll-vertical')),
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
                  ? m('i.fa-solid.fa-spinner.fa-spin')
                  : app.translator.trans('ernestdefoe-social-groups.forum.discussions.reply_button')),
            ])
          : null,
      ]),
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

    apiPost(`/sg-polls/${poll.id}/vote`, { optionIds: newVoteIds })
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

  viewPostCard(d) {
    return m(PostCard, {
      key:         d.id,
      discussion:  d,
      groupId:     this.attrs.groupId,
      groupSlug:   this.attrs.groupSlug,
      isMember:    this.attrs.isMember,

      menuOpen:         this.openMenuId === d.id,
      pickerOpen:       this.pickerDiscId === d.id,
      commentsExpanded: this.expandedDiscIds.has(d.id),
      deleting:        this.deleting === d.id,
      replyText:       this.replyTexts[d.id] || '',
      replyBusy:       !!this.replySubmitting[d.id],

      onMenuToggle:     () => { this.openMenuId = this.openMenuId === d.id ? null : d.id; m.redraw(); },
      onTogglePicker:   () => this.togglePicker(d.id),
      onReact:          (key) => { this.pickerDiscId = null; this.toggleReaction(d, key); },
      onClearReaction:  () => this.toggleReaction(d, d.firstPost?.actorReaction),
      onToggleComments: () => this.toggleComments(d),
      onPin:            () => this.pinDiscussion(d),
      onDelete:         () => this.deleteDiscussion(d),
      onVotePoll:       (optionId) => this.votePoll(d, optionId),
      onReplyChange:    (text) => { this.replyTexts[d.id] = text; },
      onReplyInput:     (e) => this.handleMentionInput(d.id, e),
      onReplyKeydown:   (e) => {
        if (e.key === 'Escape' && this.mentionQuery !== null) {
          e.stopPropagation();
          this.mentionQuery = null;
          this.mentionDiscId = null;
          m.redraw();
          return;
        }
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          this.submitReply(d);
        }
      },
      onReplySubmit:    () => this.submitReply(d),
      mentionDropdown:  this.viewMentionDropdown(d.id),
      inlineComments:   this.viewInlineComments(d),
    });
  }
}
