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
