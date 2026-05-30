import app from 'flarum/forum/app';

/**
 * Base URL for the forum's API endpoint, with the trailing slash stripped
 * so callers can safely concatenate `/foo` paths.
 */
export const apiBase = () => app.forum.attribute('apiUrl').replace(/\/$/, '');

function resolveUrl(path) {
  if (/^https?:\/\//i.test(path)) return path;
  return apiBase() + (path.startsWith('/') ? path : '/' + path);
}

function buildQueryString(params) {
  if (!params || typeof params !== 'object') return '';
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    qs.set(k, String(v));
  }
  const s = qs.toString();
  return s ? '?' + s : '';
}

/**
 * Thin wrappers around `app.request()` for the custom ACTION endpoints
 * (join/leave/react/promote/analytics/uploads/…) that aren't backed by an
 * AbstractDatabaseResource. The four resource types
 * (social-group-discussions/-posts/-members/-join-requests) go through
 * app.store instead (see below) so they're cached, de-duplicated, and
 * reactive — these helpers stay for everything else.
 *
 * On error, the rejected value is Mithril's standard error envelope:
 * `{ response: <parsed body>, status: <code> }`. Call sites destructure
 * via `.catch(err => err.response?.error)`.
 */
export function apiGet(path, params) {
  return app.request({
    method: 'GET',
    url: resolveUrl(path) + buildQueryString(params),
  });
}

export function apiPost(path, body) {
  return app.request({
    method: 'POST',
    url: resolveUrl(path),
    body: body ?? {},
  });
}

export function apiPatch(path, body) {
  return app.request({
    method: 'PATCH',
    url: resolveUrl(path),
    body: body ?? {},
  });
}

export function apiDelete(path) {
  return app.request({
    method: 'DELETE',
    url: resolveUrl(path),
  });
}

/**
 * Multipart upload via FormData. Override the default serializer so
 * Mithril hands the FormData object straight to XHR — letting the
 * browser set the multipart boundary itself.
 */
export function apiUpload(path, formData) {
  return app.request({
    method: 'POST',
    url: resolveUrl(path),
    body: formData,
    serialize: (x) => x,
  });
}

// ── Store-model → legacy-shape projection ─────────────────────────────────
//
// The four resources now flow through app.store, so the helpers below
// project store MODELS (not raw JSON:API payloads) into the denormalised
// legacy shapes the feed UI was written against. Keeping the projection
// means the components don't change while the data still lands in the store
// (cached, de-duplicated, visible to other extensions).

function projectUser(user) {
  if (!user || !user.id) return null;
  return {
    id: Number(user.id()),
    displayName: user.displayName?.() || user.username?.() || '',
    avatarUrl: user.avatarUrl?.() || null,
  };
}

function projectFirstPost(post) {
  if (!post || !post.id) return null;
  return {
    id: Number(post.id()),
    content: post.attribute('content') || '',
    contentParsed: post.attribute('contentParsed') || '',
    reactions: post.attribute('reactions') || {},
    actorReaction: post.attribute('actorReaction') || null,
    linkPreview: post.attribute('linkPreview') || null,
    canEdit: !!post.attribute('canEdit'),
    createdAt: post.attribute('createdAt') || null,
    user: projectUser(post.user()),
  };
}

function mapDiscussion(d) {
  if (!d || !d.id) return null;
  return {
    id: Number(d.id()),
    groupId: Number(d.attribute('groupId')),
    title: d.attribute('title') || '',
    commentCount: Number(d.attribute('commentCount')) || 0,
    isLocked: !!d.attribute('isLocked'),
    isPinned: !!d.attribute('isPinned'),
    canPin: !!d.attribute('canPin'),
    lastPostedAt: d.attribute('lastPostedAt') || null,
    createdAt: d.attribute('createdAt') || null,
    canDelete: !!d.attribute('canDelete'),
    canShare: !!d.attribute('canShare'),
    sharedFrom: d.attribute('sharedFrom') || null,
    poll: d.attribute('poll') || null,
    firstPost: projectFirstPost(d.firstPost()),
    user: projectUser(d.user()),
    lastPostedUser: projectUser(d.lastPostedUser()),
  };
}

function projectPostFull(p) {
  if (!p || !p.id) return null;
  return {
    id: Number(p.id()),
    discussionId: Number(p.attribute('discussionId')),
    content: p.attribute('content') || '',
    contentParsed: p.attribute('contentParsed') || '',
    createdAt: p.attribute('createdAt') || null,
    updatedAt: p.attribute('updatedAt') || p.attribute('createdAt') || null,
    reactions: p.attribute('reactions') || {},
    actorReaction: p.attribute('actorReaction') || null,
    parentPostId: p.attribute('parentPostId') ?? null,
    linkPreview: p.attribute('linkPreview') || null,
    isPinned: !!p.attribute('isPinned'),
    canEdit: !!p.attribute('canEdit'),
    canDelete: !!p.attribute('canDelete'),
    canPin: !!p.attribute('canPin'),
    user: projectUser(p.user()),
  };
}

function projectDiscussionFromResource(d) {
  if (!d || !d.id) return null;
  return {
    id: Number(d.id()),
    groupId: Number(d.attribute('groupId')),
    title: d.attribute('title') || '',
    commentCount: Number(d.attribute('commentCount')) || 0,
    isLocked: !!d.attribute('isLocked'),
    createdAt: d.attribute('createdAt') || d.attribute('lastPostedAt') || null,
    canDelete: !!d.attribute('canDelete'),
  };
}

// ── Discussions (social-group-discussions) ────────────────────────────────

/**
 * Lists discussions in a group via app.store and projects them into the
 * legacy `{ data, total, pages }` shape. The store hydrates the included
 * firstPost/user relations so projection reads them without extra calls,
 * and a repeat navigation to the same page is served from cache.
 *
 *   listDiscussions(groupId, { page: 1, q: 'foo' })
 *     -> { data: [...legacy discussion objects], total, pages }
 */
export function listDiscussions(groupId, { page = 1, q = '' } = {}) {
  const trimmed = (q || '').trim();
  const params = {
    groupId,
    page: { number: page, size: 20 },
    include: 'firstPost,firstPost.user,user,lastPostedUser',
  };
  if (trimmed) params.q = trimmed;

  return app.store.find('social-group-discussions', params).then((results) => {
    const meta = results.payload?.meta?.page || {};
    return {
      data: results.map(mapDiscussion).filter(Boolean),
      total: meta.total ?? results.length ?? 0,
      pages: meta.lastPage ?? 1,
      q: trimmed || null,
    };
  });
}

/**
 * Creates a discussion through the store (POST + push), returning the
 * projected legacy-shape discussion so GroupFeed can splice it into the
 * list without special-casing.
 *
 *   createDiscussion({groupId, content, title, linkPreview, poll})
 *     -> legacy discussion object
 */
export function createDiscussion(attrs) {
  return app.store
    .createRecord('social-group-discussions')
    .save(attrs)
    .then((d) => mapDiscussion(d));
}

export function deleteDiscussion(id) {
  const record = app.store.getById('social-group-discussions', id);
  if (record) return record.delete();
  return apiDelete(`/social-group-discussions/${id}`);
}

// ── Posts (social-group-posts) ────────────────────────────────────────────

/**
 * Creates a post through the store. Returns the projected legacy-shape post.
 */
export function createPost(attrs) {
  return app.store
    .createRecord('social-group-posts')
    .save(attrs)
    .then((p) => projectPostFull(p));
}

export function updatePost(id, attrs) {
  let record = app.store.getById('social-group-posts', id);
  if (!record) {
    record = app.store.createRecord('social-group-posts', { id: String(id) });
  }
  return record.save(attrs).then((p) => projectPostFull(p));
}

export function deletePost(id) {
  const record = app.store.getById('social-group-posts', id);
  if (record) return record.delete();
  return apiDelete(`/social-group-posts/${id}`);
}

/**
 * Lists the posts in a single discussion thread, paginated through the
 * store. The Flarum 2 JSON:API Endpoint behind this is
 * `Endpoint::Index::make()->paginate()` on SocialGroupPostResource — it
 * accepts `page[offset]` + `page[limit]`.
 *
 * Caller contract:
 *   • `opts.offset` (default 0)   — number of posts to skip.
 *   • `opts.limit`  (default 30)  — page size; clamped 1–100 server-side.
 *
 * Returns: `{ discussion, data, meta: { hasMore, total, offset, limit } }`.
 * `data` contains the projected posts for THIS page only. The caller is
 * responsible for appending across pages.
 */
export function listThreadPosts(discussionId, opts = {}) {
  const offset = Number.isFinite(opts.offset) ? Math.max(0, opts.offset | 0) : 0;
  const limit = Number.isFinite(opts.limit) ? Math.max(1, Math.min(100, opts.limit | 0)) : 30;

  const params = {
    discussionId,
    page: { offset, limit },
    include: 'user,discussion',
  };

  return app.store.find('social-group-posts', params).then((results) => {
    const posts = results.map(projectPostFull).filter(Boolean);

    /*
     * JSON:API server hands us `meta.page.total` (when paginate() is
     * declared on the Endpoint). `links.next` only exists if more pages
     * remain — we use BOTH signals because some Flarum versions populate
     * one but not the other depending on whether `total` is known.
     */
    const payload = results.payload || {};
    const total = Number(payload.meta?.page?.total ?? NaN);
    const hasNext = !!payload.links?.next;
    const hasMore = Number.isFinite(total) ? offset + posts.length < total : hasNext;

    const meta = {
      offset,
      limit,
      total: Number.isFinite(total) ? total : null,
      hasMore,
    };

    /*
     * The `include=discussion` hydrated the discussion into the store, so
     * read it back without a second round-trip. Only co-fetch when it's
     * absent (empty thread / page > 0) and we're on the first page.
     */
    const cached = app.store.getById('social-group-discussions', discussionId);
    if (cached) {
      return { discussion: projectDiscussionFromResource(cached), data: posts, meta };
    }
    if (offset > 0) {
      return { discussion: null, data: posts, meta };
    }
    return app.store.find('social-group-discussions', discussionId).then((d) => ({
      discussion: projectDiscussionFromResource(d),
      data: posts,
      meta,
    }));
  });
}

// ── Action endpoints on resources (pin/share/react/unreact) ───────────────
//
// These are custom (non-CRUD) endpoints, so they stay on app.request and
// project the JSON:API response down to the post-action attrs the caller
// wants. shareDiscussion pushes its created discussion into the store so
// the freshly-shared row is cached alongside the rest of the feed.

export function pinDiscussion(id) {
  return app
    .request({
      method: 'PATCH',
      url: resolveUrl(`/social-group-discussions/${id}/pin`),
    })
    .then((body) => ({ isPinned: !!body.data?.attributes?.isPinned }));
}

export function shareDiscussion(id, payload) {
  return apiPost(`/social-group-discussions/${id}/share`, payload).then((body) => {
    const d = app.store.pushPayload(body);
    return mapDiscussion(d);
  });
}

export function pinPost(id) {
  return app
    .request({
      method: 'PATCH',
      url: resolveUrl(`/social-group-posts/${id}/pin`),
    })
    .then((body) => ({ isPinned: !!body.data?.attributes?.isPinned }));
}

export function reactToPost(id, reaction) {
  return apiPost(`/social-group-posts/${id}/react`, { reaction }).then((body) => ({
    reactions: body.data?.attributes?.reactions || {},
    actorReaction: body.data?.attributes?.actorReaction || null,
  }));
}

export function unreactToPost(id) {
  return apiPost(`/social-group-posts/${id}/unreact`).then((body) => ({
    reactions: body.data?.attributes?.reactions || {},
    actorReaction: body.data?.attributes?.actorReaction || null,
  }));
}

// ── Members (social-group-members) ────────────────────────────────────────

function projectMember(r) {
  return {
    id: Number(r.id()),
    userId: Number(r.attribute('userId')),
    role: r.attribute('role') || 'member',
    displayName: r.attribute('displayName') || '',
    avatarUrl: r.attribute('avatarUrl') || null,
    slug: r.attribute('slug') || '',
    joinedAt: r.attribute('joinedAt') || null,
    canModerate: !!r.attribute('canModerate'),
    canRemove: !!r.attribute('canRemove'),
  };
}

export function listMembers(groupId) {
  return app.store
    .find('social-group-members', { groupId, include: 'user' })
    .then((results) => ({ data: results.map(projectMember) }));
}

export function promoteMember(memberId) {
  return apiPost(`/social-group-members/${memberId}/promote`).then((body) => ({
    role: body.data?.attributes?.role || 'moderator',
  }));
}

export function demoteMember(memberId) {
  return apiPost(`/social-group-members/${memberId}/demote`).then((body) => ({
    role: body.data?.attributes?.role || 'member',
  }));
}

export function kickMember(memberId) {
  const record = app.store.getById('social-group-members', memberId);
  if (record) return record.delete();
  return apiDelete(`/social-group-members/${memberId}`);
}

// ── Join requests (social-group-join-requests) ────────────────────────────

function projectJoinRequest(r) {
  const userId = Number(r.attribute('userId'));
  const displayName = r.attribute('displayName');
  return {
    id: Number(r.id()),
    userId,
    user: displayName
      ? { id: userId, displayName, avatarUrl: r.attribute('avatarUrl') || null }
      : null,
    createdAt: r.attribute('createdAt') || null,
  };
}

export function listJoinRequests(groupId) {
  return app.store
    .find('social-group-join-requests', { groupId, include: 'user' })
    .then((results) => ({ data: results.map(projectJoinRequest) }));
}

export function approveJoinRequest(requestId) {
  return apiPost(`/social-group-join-requests/${requestId}/approve`);
}

export function rejectJoinRequest(requestId) {
  const record = app.store.getById('social-group-join-requests', requestId);
  if (record) return record.delete();
  return apiDelete(`/social-group-join-requests/${requestId}`);
}
