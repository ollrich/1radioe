// 1radiœ web app: the song from the stream, asked from the server while a station plays.

import { $, $$, NOW_EVERY, isOwn, isPlaying, isYt, phaseText, state } from './core.js';
import { canHover, marquee, setText, stopMarquee, updateIndicators } from './view.js';
import { ytLine } from './youtube.js';
import { setMediaMetadata } from './media.js';

/* ---------- Song from the stream ----------
 * Shoutcast and Icecast send the current title inside the stream, which a page cannot
 * read from <audio>. The server reads it (api/nowplaying, cached there), the player
 * asks while it plays. */

export const now = { id: null, track: null, timer: 0 };

/** The song of the station in the player, only while it plays. */
export function currentTrack() {
  return isPlaying() && state.cur && now.id === state.cur.id ? now.track : null;
}

export function trackLine(t) {
  return t ? (t.artist ? `${t.artist} · ${t.title}` : t.title) : '';
}

/** The station's description from the admin: always under the name, until a song replaces it. */
export function currentDesc() {
  if (!state.cur) return '';
  return isYt(state.cur) ? ytLine(state.cur) : (state.cur.desc || '');
}

export function renderTrack() {
  const t = currentTrack();
  const desc = t ? '' : currentDesc();
  $$('[data-r1="track"]').forEach((n) => {
    n.hidden = !t && !desc;
    n.title = trackLine(t) || desc;
    n.classList.toggle('is-desc', !t && Boolean(desc));   // a description may take two lines
  });
  $$('[data-r1="track-title"]').forEach((n) => { setText(n, t ? t.title : ''); n.hidden = !t; });
  // the description takes the quieter line of the artist
  $$('[data-r1="track-artist"]').forEach((n) => { setText(n, t ? t.artist : desc); n.hidden = !(t ? t.artist : desc); });
  // the docked player has one line: the song or the description instead of "Live"
  $$('.mini [data-r1="state"]').forEach((n) => {
    setText(n, state.phase === 'live' && (t || desc) ? (trackLine(t) || desc) : phaseText(state.phase));
  });
  setMediaMetadata();
  updateIndicators();
}

export async function pollNow() {
  clearTimeout(now.timer);
  now.timer = 0;
  const it = state.cur;
  if (!it || state.phase !== 'live' || isOwn(it)) return;
  let track = now.id === it.id ? now.track : null;
  try {
    const res = await fetch('api/nowplaying?id=' + encodeURIComponent(it.id), { credentials: 'omit' });
    if (res.ok) track = (await res.json()).track || null;
  } catch {
    // offline for a moment: keep what is shown
  }
  if (state.cur !== it || state.phase !== 'live') return;   // switched or stopped meanwhile
  const changed = now.id !== it.id || trackLine(track) !== trackLine(now.track);
  now.id = it.id;
  now.track = track;
  if (changed) renderTrack();
  now.timer = setTimeout(pollNow, NOW_EVERY);
}

export function stopNow() {
  clearTimeout(now.timer);
  now.timer = 0;
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  // a cut-off song line on the stage runs while the pointer rests on it
  $$('.stage [data-r1="track"]').forEach((track) => {
    track.addEventListener('mouseenter', () => {
      if (track.classList.contains('is-desc')) return;   // the description wraps instead
      $$(':scope > span', track).forEach((n) => marquee(n, () => canHover.matches && track.matches(':hover')));
    });
    track.addEventListener('mouseleave', () => $$(':scope > span', track).forEach(stopMarquee));
  });
}
