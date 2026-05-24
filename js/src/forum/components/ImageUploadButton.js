import { apiUpload } from '../utils/api';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * ImageUploadButton — a clickable upload zone for group images.
 *
 * Props:
 *   - type: 'image' | 'banner'
 *   - groupId: number|null  (null when creating a new group — preview only, upload after create)
 *   - currentUrl: string|null
 *   - label: string
 *   - onUpload: (url: string) => void  — called with final URL after successful upload
 */
export default class ImageUploadButton extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = false;
    this.previewUrl = this.attrs.currentUrl || null;
    // Queue for deferred uploads (when groupId is null)
    this.pendingFile = null;
  }

  view() {
    const { type, label } = this.attrs;
    const isBanner = type === 'banner';

    return m(
      'div',
      {
        class: `ImageUploadButton ImageUploadButton--${isBanner ? 'banner' : 'avatar'}`,
        onclick: () => this.$('input[type=file]')[0]?.click(),
        title: label,
      },
      [
        // Hidden file input
        m('input', {
          type: 'file',
          accept: 'image/jpeg,image/png,image/gif,image/webp',
          onchange: (e) => this.onFileSelected(e),
        }),

        // Preview
        this.previewUrl
          ? m('div.ImageUploadButton-preview', [m('img', { src: this.previewUrl, alt: label })])
          : m('div.ImageUploadButton-placeholder', [
              m('i.fas', { class: isBanner ? 'fa-panorama' : 'fa-image' }),
              m('span', label),
            ]),

        // Loading overlay
        this.loading
          ? m('div.ImageUploadButton-loading', [m(LoadingIndicator, { size: 'small', display: 'inline' })])
          : m('div.ImageUploadButton-overlay', [m('span', app.translator.trans('ernestdefoe-social-groups.forum.upload.change'))]),
      ]
    );
  }

  onFileSelected(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Validate client-side
    const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowed.includes(file.type)) {
      alert(app.translator.trans('ernestdefoe-social-groups.forum.upload.invalid_type'));
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert(app.translator.trans('ernestdefoe-social-groups.forum.upload.too_large'));
      return;
    }

    // Show blob preview immediately
    const blobUrl = URL.createObjectURL(file);
    this.previewUrl = blobUrl;
    m.redraw();

    const groupId = this.attrs.groupId;
    if (!groupId) {
      // No group yet — store file and let parent upload after create
      this.pendingFile = file;
      if (this.attrs.onFileSelected) {
        this.attrs.onFileSelected(file, blobUrl);
      }
      return;
    }

    this.uploadFile(file, groupId);
  }

  uploadFile(file, groupId) {
    this.loading = true;
    m.redraw();

    const formData = new FormData();
    formData.append('file', file);

    const type = this.attrs.type;
    const endpoint = type === 'banner' ? 'banner' : 'image';

    apiUpload(`/social-groups/${groupId}/${endpoint}`, formData)
      .then((data) => {
        this.loading = false;
        if (data.url) {
          this.previewUrl = data.url;
          if (this.attrs.onUpload) {
            this.attrs.onUpload(data.url);
          }
        } else {
          console.error('Upload error:', data.error);
        }
        m.redraw();
      })
      .catch((err) => {
        this.loading = false;
        console.error('Upload failed:', err);
        m.redraw();
      });
  }

  /**
   * Called by parent after group is created to finalize pending upload.
   */
  uploadPendingFile(groupId) {
    if (this.pendingFile && groupId) {
      this.uploadFile(this.pendingFile, groupId);
      this.pendingFile = null;
    }
  }
}
