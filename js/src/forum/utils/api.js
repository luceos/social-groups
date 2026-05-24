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
 * Thin wrappers around `app.request()` so every social-groups call gets
 * the same CSRF injection, Authorization header, session-expiry
 * handling, and JSON-parse path. Replaces direct `fetch()` calls that
 * were duplicating that wiring (and missing pieces — session expiry was
 * never observed, CSRF was reread from `app.session.csrfToken` per call,
 * 401 responses surfaced as parse errors).
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

// ── JSON:API → legacy-shape projection ────────────────────────────────────
//
// SocialGroupDiscussionResource (Phase 1 of audit #4) lives at
// /api/social-group-discussions. Its response is standard JSON:API
// — { data: [...], included: [...], meta: {...} } — while the rest of
// the feed UI was written against the legacy /sg-discussions/{groupId}
// shape (plain objects with denormalised user/firstPost). The helpers
// below project the JSON:API response into the legacy shape so the
// JS UI code doesn't change. Once every consumer is on this helper,
// the legacy controller can go.

function findIncluded(included, type, id) {
  if (!included || id == null) return null;
  const idStr = String(id);
  for (const r of included) {
    if (r.type === type && String(r.id) === idStr) return r;
  }
  return null;
}

function projectUser(included, ref) {
  if (!ref) return null;
  const r = findIncluded(included, 'users', ref.id);
  if (!r) return null;
  return {
    id:          Number(r.id),
    displayName: r.attributes.displayName || r.attributes.username || '',
    avatarUrl:   r.attributes.avatarUrl || null,
  };
}

function projectFirstPost(included, ref) {
  if (!ref) return null;
  const r = findIncluded(included, 'social-group-posts', ref.id);
  if (!r) return null;
  const a = r.attributes || {};
  return {
    id:            Number(r.id),
    content:       a.content || '',
    contentParsed: a.contentParsed || '',
    reactions:     a.reactions || {},
    actorReaction: a.actorReaction || null,
    linkPreview:   a.linkPreview || null,
    canEdit:       !!a.canEdit,
    createdAt:     a.createdAt || null,
    user:          projectUser(included, r.relationships?.user?.data),
  };
}

function mapDiscussion(d, included) {
  const a   = d.attributes || {};
  const rel = d.relationships || {};
  return {
    id:             Number(d.id),
    groupId:        Number(a.groupId),
    title:          a.title || '',
    commentCount:   Number(a.commentCount) || 0,
    isLocked:       !!a.isLocked,
    isPinned:       !!a.isPinned,
    canPin:         !!a.canPin,
    lastPostedAt:   a.lastPostedAt || null,
    createdAt:      a.createdAt || null,
    canDelete:      !!a.canDelete,
    canShare:       !!a.canShare,
    sharedFrom:     a.sharedFrom || null,
    poll:           a.poll || null,
    firstPost:      projectFirstPost(included, rel.firstPost?.data),
    user:           projectUser(included, rel.user?.data),
    lastPostedUser: projectUser(included, rel.lastPostedUser?.data),
  };
}

function projectPostFull(r, included) {
  const a = r.attributes || {};
  return {
    id:            Number(r.id),
    discussionId:  Number(a.discussionId),
    content:       a.content || '',
    contentParsed: a.contentParsed || '',
    createdAt:     a.createdAt || null,
    updatedAt:     a.updatedAt || a.createdAt || null,
    reactions:     a.reactions || {},
    actorReaction: a.actorReaction || null,
    parentPostId:  a.parentPostId ?? null,
    linkPreview:   a.linkPreview || null,
    isPinned:      !!a.isPinned,
    canEdit:       !!a.canEdit,
    canDelete:     !!a.canDelete,
    canPin:        !!a.canPin,
    user:          projectUser(included, r.relationships?.user?.data),
  };
}

function projectDiscussionFromResource(r) {
  if (!r) return null;
  const a = r.attributes || {};
  return {
    id:           Number(r.id),
    groupId:      Number(a.groupId),
    title:        a.title || '',
    commentCount: Number(a.commentCount) || 0,
    isLocked:     !!a.isLocked,
    createdAt:    a.createdAt || a.lastPostedAt || null,
    canDelete:    !!a.canDelete,
  };
}

/**
 * Lists discussions in a group via the new JSON:API endpoint and
 * returns the legacy `{ data, total, pages }` shape so call sites
 * don't need to touch every property access. Page size is fixed at
 * 20 to match the legacy controller's hardcoded limit.
 *
 *   listDiscussions(groupId, { page: 1, q: 'foo' })
 *     -> { data: [...legacy discussion objects], total, pages }
 */
export function listDiscussions(groupId, { page = 1, q = '' } = {}) {
  const params = {
    'filter[group]': groupId,
    'page[number]':  page,
    'page[size]':    20,
    include:         'firstPost,firstPost.user,user,lastPostedUser',
  };
  const trimmed = (q || '').trim();
  if (trimmed) params['filter[q]'] = trimmed;

  return apiGet('/social-group-discussions', params).then((body) => ({
    data:  (body.data || []).map((d) => mapDiscussion(d, body.included || [])),
    total: body.meta?.page?.total ?? body.data?.length ?? 0,
    pages: body.meta?.page?.lastPage ?? 1,
    q:    trimmed || null,
  }));
}

/**
 * Lists all posts in a discussion. Returns the legacy thread-posts
 * shape `{ discussion: {...}, data: [...posts] }`. The discussion
 * meta comes through `?include=discussion` and is projected via
 * projectDiscussion(); each post via projectPostFull().
 *
 * Page size is high so the thread loads in one shot (matching the
 * legacy controller which paginated via Eloquent default 'get-all').
 * When threads grow past 200 we'll teach the parent to paginate
 * properly; for now the JS expects the full list.
 */
/**
 * Wraps a flat attributes object into a JSON:API request body and POSTs
 * it to the discussion Resource. Returns the projected legacy-shape
 * discussion (same as listDiscussions items) so GroupFeed can splice
 * it into the list without special-casing.
 *
 *   createDiscussion({groupId, content, title, linkPreview, poll})
 *     -> legacy discussion object
 */
export function createDiscussion(attrs) {
  return apiPost('/social-group-discussions', {
    data: { type: 'social-group-discussions', attributes: attrs },
  }).then((body) => mapDiscussion(body.data, body.included || []));
}

export function deleteDiscussion(id) {
  return apiDelete(`/social-group-discussions/${id}`);
}

/**
 * Same idea as createDiscussion but for posts. Returns the projected
 * legacy-shape post.
 */
export function createPost(attrs) {
  return apiPost('/social-group-posts', {
    data: { type: 'social-group-posts', attributes: attrs },
  }).then((body) => projectPostFull(body.data, body.included || []));
}

export function updatePost(id, attrs) {
  return app.request({
    method: 'PATCH',
    url:    resolveUrl(`/social-group-posts/${id}`),
    body:   { data: { type: 'social-group-posts', id: String(id), attributes: attrs } },
  }).then((body) => projectPostFull(body.data, body.included || []));
}

export function deletePost(id) {
  return apiDelete(`/social-group-posts/${id}`);
}

// ── Action endpoints on resources (pin/share/react/unreact) ───────────────
//
// These return JSON:API resources. Callers want the post-action attrs
// (e.g. {isPinned: bool} or {reactions: {...}, actorReaction: ...}),
// so the helpers project the response down to that shape.

export function pinDiscussion(id) {
  return app.request({
    method: 'PATCH',
    url:    resolveUrl(`/social-group-discussions/${id}/pin`),
  }).then((body) => ({ isPinned: !!body.data?.attributes?.isPinned }));
}

export function shareDiscussion(id, payload) {
  return apiPost(`/social-group-discussions/${id}/share`, payload)
    .then((body) => mapDiscussion(body.data, body.included || []));
}

export function pinPost(id) {
  return app.request({
    method: 'PATCH',
    url:    resolveUrl(`/social-group-posts/${id}/pin`),
  }).then((body) => ({ isPinned: !!body.data?.attributes?.isPinned }));
}

export function reactToPost(id, reaction) {
  return apiPost(`/social-group-posts/${id}/react`, { reaction })
    .then((body) => ({
      reactions:     body.data?.attributes?.reactions || {},
      actorReaction: body.data?.attributes?.actorReaction || null,
    }));
}

export function unreactToPost(id) {
  return apiPost(`/social-group-posts/${id}/unreact`)
    .then((body) => ({
      reactions:     body.data?.attributes?.reactions || {},
      actorReaction: body.data?.attributes?.actorReaction || null,
    }));
}

// ── Members ──────────────────────────────────────────────────────────────

function projectMember(r) {
  const a = r.attributes || {};
  return {
    id:          Number(r.id),
    userId:      Number(a.userId),
    role:        a.role || 'member',
    displayName: a.displayName || '',
    avatarUrl:   a.avatarUrl || null,
    slug:        a.slug || '',
    joinedAt:    a.joinedAt || null,
    canModerate: !!a.canModerate,
    canRemove:   !!a.canRemove,
  };
}

export function listMembers(groupId) {
  return apiGet('/social-group-members', {
    'filter[group]': groupId,
    include:         'user',
  }).then((body) => ({
    data: (body.data || []).map(projectMember),
  }));
}

export function promoteMember(memberId) {
  return apiPost(`/social-group-members/${memberId}/promote`)
    .then((body) => ({ role: body.data?.attributes?.role || 'moderator' }));
}

export function demoteMember(memberId) {
  return apiPost(`/social-group-members/${memberId}/demote`)
    .then((body) => ({ role: body.data?.attributes?.role || 'member' }));
}

export function kickMember(memberId) {
  return apiDelete(`/social-group-members/${memberId}`);
}

// ── Join requests ────────────────────────────────────────────────────────

function projectJoinRequest(r) {
  const a = r.attributes || {};
  return {
    id:          Number(r.id),
    userId:      Number(a.userId),
    user:        a.displayName
      ? { id: Number(a.userId), displayName: a.displayName, avatarUrl: a.avatarUrl || null }
      : null,
    createdAt:   a.createdAt || null,
  };
}

export function listJoinRequests(groupId) {
  return apiGet('/social-group-join-requests', {
    'filter[group]': groupId,
    include:         'user',
  }).then((body) => ({
    data: (body.data || []).map(projectJoinRequest),
  }));
}

export function approveJoinRequest(requestId) {
  return apiPost(`/social-group-join-requests/${requestId}/approve`);
}

export function rejectJoinRequest(requestId) {
  return apiDelete(`/social-group-join-requests/${requestId}`);
}

export function listThreadPosts(discussionId) {
  const params = {
    'filter[discussion]': discussionId,
    'page[size]':         200,
    include:              'user,discussion',
  };

  return apiGet('/social-group-posts', params).then((body) => {
    const included = body.included || [];
    const posts    = (body.data || []).map((r) => projectPostFull(r, included));
    const discRes  = included.find((r) => r.type === 'social-group-discussions');

    if (discRes) {
      return { discussion: projectDiscussionFromResource(discRes), data: posts };
    }
    // Discussão sem nenhum post — JSON:API include não tem nada para
    // hidratar; busca a discussão sozinha para que o caller ainda
    // receba o meta esperado (title, etc.).
    return apiGet(`/social-group-discussions/${discussionId}`).then((discBody) => ({
      discussion: projectDiscussionFromResource(discBody.data),
      data:       posts,
    }));
  });
}
