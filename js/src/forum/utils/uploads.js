import { apiUpload } from './api';

/**
 * Returns any image files found in a paste event's clipboardData.
 * Returns an empty array when the paste contains no images.
 */
export function pastedImages(e) {
  return Array.from(e.clipboardData?.files || []).filter((f) =>
    f.type.startsWith('image/')
  );
}

/**
 * Upload one or more files via fof/upload and append the resulting BBcode
 * to component[textKey].  component[uploadsKey] tracks in-progress chips.
 */
export function handleFiles(component, files, uploadsKey, textKey) {
  for (const file of files) {
    const id         = Math.random().toString(36).slice(2);
    const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;

    component[uploadsKey].push({ id, name: file.name, previewUrl, uploading: true, error: null, uuid: null });
    m.redraw();

    const fd = new FormData();
    fd.append('files[]', file);

    apiUpload('/fof/upload', fd)
      .then((data) => {
        const fileData = Array.isArray(data.data) ? data.data[0] : data.data;
        const uuid     = fileData?.attributes?.uuid || fileData?.id;
        const bbcode   = fileData?.attributes?.bbcode || `[upl-file uuid="${uuid}"][/upl-file]`;
        const upload   = component[uploadsKey].find((u) => u.id === id);
        if (upload) {
          upload.uuid      = uuid;
          upload.uploading = false;
          component[textKey] = component[textKey] ? `${component[textKey]}\n${bbcode}` : bbcode;
        }
        m.redraw();
      })
      .catch((err) => {
        const upload = component[uploadsKey].find((u) => u.id === id);
        if (upload) {
          upload.uploading = false;
          upload.error     = err.response?.errors?.[0]?.detail
            || err.response?.error
            || err.message
            || 'Upload failed';
        }
        m.redraw();
      });
  }
}

/**
 * Remove a single upload chip, revoke its object URL, and strip its BBcode
 * from component[textKey].
 */
export function removeUpload(component, id, uploadsKey, textKey) {
  const upload = component[uploadsKey].find((u) => u.id === id);
  if (!upload) return;
  if (upload.previewUrl) URL.revokeObjectURL(upload.previewUrl);
  if (upload.uuid) {
    const tag = `[upl-file uuid="${upload.uuid}"][/upl-file]`;
    component[textKey] = component[textKey].replace(`\n${tag}`, '').replace(tag, '').trim();
  }
  component[uploadsKey] = component[uploadsKey].filter((u) => u.id !== id);
  m.redraw();
}

/** Revoke all object URLs in an uploads array (call in onremove). */
export function revokeAll(uploads) {
  uploads.forEach((u) => { if (u.previewUrl) URL.revokeObjectURL(u.previewUrl); });
}

/**
 * Render a row of upload preview chips.
 * onRemove(id) is called when the × button is clicked.
 */
export function viewUploadChips(uploads, onRemove) {
  if (!uploads.length) return null;

  return m('.SGUploadChips', uploads.map((u) => {
    const cls = 'SGUploadChip' +
      (u.error    ? '.SGUploadChip--error'   :
       u.uploading ? '.SGUploadChip--loading' : '.SGUploadChip--done');

    return m(cls, { key: u.id }, [
      u.uploading
        ? m('i.fa-solid.fa-spinner.fa-spin')
        : u.error
        ? m('i.fa-solid.fa-circle-exclamation')
        : u.previewUrl
        ? m('img.SGUploadChip-thumb', { src: u.previewUrl, alt: u.name })
        : m('i.fa-solid.fa-file'),
      m('span.SGUploadChip-name', u.error ? `${u.name}: ${u.error}` : u.name),
      !u.uploading
        ? m('button.SGUploadChip-remove', {
            type:    'button',
            onclick: () => onRemove(u.id),
          }, '×')
        : null,
    ]);
  }));
}
