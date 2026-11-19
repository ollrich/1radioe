// 1radiœ web app: media keys, Chrome's media controls and the lock screen.

import { data, isPlaying, isYt, items, state } from './core.js';
import { step, stop, toggle } from './audio.js';
import { currentTrack } from './song.js';

/* Media keys, Chrome's media controls, lock screen */
export const media = 'mediaSession' in navigator ? navigator.mediaSession : null;
let metaKey = '';
export function setMediaMetadata() {
  if (!media || !state.cur || typeof MediaMetadata === 'undefined') return;
  const it = state.cur;
  const cat = it.level !== 'root' ? items.get(it.level) : null;
  const type = it.logo && /\.png$/i.test(it.logo) ? 'image/png' : 'image/jpeg';
  const t = currentTrack();
  const data = {
    title: t ? t.title : it.name,
    // the fixed description: the running time of a video stays on the page
    artist: t ? (t.artist || it.name) : (it.desc || '1radiœ'),
    album: t ? it.name : (cat ? cat.name : ''),
    artwork: it.logo ? [{ src: it.logo, sizes: isYt(it) ? '640x360' : '600x600', type }] : [],
  };
  const key = JSON.stringify(data);
  if (key === metaKey) return;   // renderTrack runs every second while a video plays
  metaKey = key;
  media.metadata = new MediaMetadata(data);
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  if (media) {
    const handle = (action, fn) => { try { media.setActionHandler(action, fn); } catch { /* not supported */ } };
    handle('play', () => { if (!isPlaying()) toggle(); });
    handle('pause', () => { if (isPlaying()) stop(); });
    handle('stop', () => { if (isPlaying()) stop(); });
    handle('previoustrack', () => step(-1));
    handle('nexttrack', () => step(1));
  }
}
