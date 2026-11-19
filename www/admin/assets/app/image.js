// 1radiœ admin: the editor for logos and covers (Cropper.js), in the station and the
// track dialog.

import { $, $$, MAX_IMAGE, api, toast } from './core.js';

/* ---------- Image editor (logos and covers) ---------- */

export class ImageEditor {
  constructor(root) {
    this.root = root;
    this.cropper = null;
    this.bg = '';
    const q = (name) => $(`[data-ie="${name}"]`, root);
    this.q = q;
    q('file').addEventListener('change', (e) => { this.readFile(e.target.files[0]); e.target.value = ''; });
    q('fromurl').addEventListener('click', () => { q('urlrow').hidden = false; q('url').focus(); });
    q('urlload').addEventListener('click', () => this.loadUrl());
    q('url').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.loadUrl(); } });
    q('remove').addEventListener('click', () => { this.show(null); this.pending = false; this.removeIt = Boolean(this.existing); });
    $$('[data-crop]', root).forEach((b) => b.addEventListener('click', () => this.crop(b.dataset.crop)));
    q('bg').addEventListener('change', (e) => {
      const custom = e.target.value === 'custom';
      q('bgcolor').hidden = !custom;
      this.bg = custom ? q('bgcolor').value : e.target.value;
      this.applyBg();
    });
    q('bgcolor').addEventListener('input', (e) => { this.bg = e.target.value; this.applyBg(); });
  }

  reset(url) {
    this.existing = url || null;
    this.pending = false;
    this.removeIt = false;
    this.bg = '';
    this.q('bg').value = '';
    this.q('bgcolor').hidden = true;
    this.q('urlrow').hidden = true;
    this.q('url').value = '';
    this.show(url);
  }

  show(url) {
    this.destroy();
    this.q('preview').hidden = !url;
    if (url) this.q('preview').src = url;
    this.q('placeholder').hidden = Boolean(url);
    this.q('remove').hidden = !url;
  }

  destroy() {
    if (this.cropper) this.cropper.destroy();
    this.cropper = null;
    this.q('cropwrap').hidden = true;
    this.q('tools').hidden = true;
  }

  start(src) {
    this.destroy();
    this.q('preview').hidden = true;
    this.q('placeholder').hidden = true;
    this.q('cropwrap').hidden = false;
    this.q('tools').hidden = false;
    this.q('remove').hidden = false;
    const img = this.q('cropimg');
    img.src = src;
    this.cropper = new window.Cropper(img, {
      aspectRatio: 1, viewMode: 0, dragMode: 'move', autoCropArea: 1, background: true, restore: false, toggleDragModeOnDblclick: false,
    });
    this.pending = true;
    this.removeIt = false;
    this.applyBg();
  }

  applyBg() {
    const wrap = this.q('cropwrap');
    wrap.classList.toggle('has-bg', Boolean(this.bg));
    wrap.style.backgroundColor = this.bg || '';
  }

  crop(kind) {
    const c = this.cropper;
    if (!c) return;
    if (kind === 'zoom-in') c.zoom(0.1);
    if (kind === 'zoom-out') c.zoom(-0.1);
    if (kind === 'rotate') c.rotate(90);
    if (kind === 'fill') c.reset();
    if (kind === 'fit') {
      // whole image inside the square stage; empty space gets the background color
      c.reset();
      const box = c.getContainerData();
      c.setCropBoxData({ left: 0, top: 0, width: box.width, height: box.height });
    }
  }

  readFile(file) {
    if (!file) return;
    if (file.size > MAX_IMAGE) {
      toast('Bild ist größer als 8 MB.', 'error');
      return;
    }
    const reader = new FileReader();
    reader.onload = () => this.start(reader.result);
    reader.readAsDataURL(file);
  }

  async loadUrl() {
    const url = this.q('url').value.trim();
    if (!/^https?:\/\//i.test(url)) {
      toast('Bitte eine Bildadresse mit http(s) eingeben.', 'error');
      return;
    }
    const btn = this.q('urlload');
    btn.disabled = true;
    try {
      const { dataUrl } = await api('logo.fetch', { url });
      this.start(dataUrl);
      this.q('urlrow').hidden = true;
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      btn.disabled = false;
    }
  }

  blob() {
    const canvas = this.cropper.getCroppedCanvas({
      width: 600, height: 600, fillColor: this.bg || 'transparent', imageSmoothingEnabled: true, imageSmoothingQuality: 'high',
    });
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
  }

  /** Uploads or removes the image after the main record was saved. Returns the API response or null. */
  async commit(uploadAction, removeAction, id) {
    if (this.pending && this.cropper) {
      const fd = new FormData();
      fd.append('id', id);
      fd.append('logo', await this.blob(), 'image.png');
      return api(uploadAction, fd);
    }
    if (this.removeIt) return api(removeAction, { id });
    return null;
  }
}
