import { apiBase } from './api';
import app from 'flarum/forum/app';

const URL_RE = /https?:\/\/[^\s<>"{}|\\^`[\]]+/gi;

export function detectFirstUrl(text) {
  URL_RE.lastIndex = 0;
  const match = URL_RE.exec(text);
  if (!match) return null;
  // Strip trailing punctuation that is unlikely part of the URL
  return match[0].replace(/[.,;:!?)>]+$/, '');
}

/**
 * Schedule a debounced link preview fetch for `component`.
 * The component must expose: previewUrl, previewLoading, linkPreview,
 * _previewTimer, _dismissedUrls (Set).
 */
export function scheduleLinkPreview(component, text) {
  clearTimeout(component._previewTimer);
  const url = detectFirstUrl(text);

  if (!url) return;
  if (url === component.previewUrl) return;
  if (component._dismissedUrls?.has(url)) return;

  component._previewTimer = setTimeout(() => {
    component.previewUrl     = url;
    component.previewLoading = true;
    m.redraw();

    fetch(`${apiBase()}/sg-link-preview?${new URLSearchParams({ url })}`, {
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((data) => {
        if (component.previewUrl !== url) return;
        component.linkPreview    = data;
        component.previewLoading = false;
        m.redraw();
      })
      .catch(() => {
        if (component.previewUrl !== url) return;
        component.previewLoading = false;
        m.redraw();
      });
  }, 800);
}

/** Reset all preview state on a component (call after submit). */
export function clearLinkPreview(component) {
  clearTimeout(component._previewTimer);
  component.linkPreview    = null;
  component.previewUrl     = null;
  component.previewLoading = false;
  component._dismissedUrls = new Set();
}

/**
 * Render the in-composer preview card (with a dismiss button).
 * `component` must have linkPreview / previewLoading / previewUrl.
 */
export function viewComposerLinkPreview(component) {
  if (component.previewLoading) {
    return m('.SGLinkPreview.SGLinkPreview--loading', [
      m('i.fa-solid.fa-spinner.fa-spin'),
      m('span', ' Loading preview…'),
    ]);
  }

  const p = component.linkPreview;
  if (!p) return null;

  return m('.SGLinkPreview', [
    p.image
      ? m('img.SGLinkPreview-image', { src: p.image, alt: '', onerror: (e) => { e.target.style.display = 'none'; } })
      : null,
    m('.SGLinkPreview-body', [
      p.siteName ? m('span.SGLinkPreview-site', p.siteName) : null,
      m('a.SGLinkPreview-title', { href: p.url, target: '_blank', rel: 'noopener noreferrer' }, p.title || p.url),
      p.description ? m('p.SGLinkPreview-desc', p.description) : null,
    ]),
    m('button.SGLinkPreview-remove', {
      type:    'button',
      onclick: () => {
        if (!component._dismissedUrls) component._dismissedUrls = new Set();
        component._dismissedUrls.add(component.previewUrl);
        component.linkPreview    = null;
        component.previewLoading = false;
        m.redraw();
      },
    }, '×'),
  ]);
}

/** Render the read-only link preview card inside a rendered post. */
export function viewPostLinkPreview(post) {
  const p = post?.linkPreview;
  if (!p?.url) return null;

  return m('a.SGLinkPreview.SGLinkPreview--post', { href: p.url, target: '_blank', rel: 'noopener noreferrer' }, [
    p.image
      ? m('img.SGLinkPreview-image', { src: p.image, alt: '', onerror: (e) => { e.target.style.display = 'none'; } })
      : null,
    m('.SGLinkPreview-body', [
      p.siteName ? m('span.SGLinkPreview-site', p.siteName) : null,
      m('.SGLinkPreview-title', p.title || p.url),
      p.description ? m('p.SGLinkPreview-desc', p.description) : null,
    ]),
  ]);
}
