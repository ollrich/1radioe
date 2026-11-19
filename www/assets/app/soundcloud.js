// 1radiœ web app: SoundCloud entries in SoundCloud's player.

import { $, $$, T, el, isPlaying, isSc, state, stationsOf } from './core.js';
import { setPhase } from './view.js';
import { play, volume } from './audio.js';
import { openDialog, openPage } from './dialogs.js';

/* ---------- SoundCloud ----------
 * A SoundCloud entry plays in SoundCloud's own player (an iframe), never in <audio>.
 * It loads only after a click on "SoundCloud laden"; that holds for this visit and is
 * not stored. The page steers the player with postMessage, the protocol of SoundCloud's
 * widget API (w.soundcloud.com/player/api.js), without loading that script. Wide
 * screens show the player in the stage, phones in the full player (sheet). No
 * listening status and no song lookups for SoundCloud. */

const SC_ORIGIN = 'https://w.soundcloud.com';
export const sc = { ok: false, frame: null, url: '', ready: false, want: false, timer: 0 };
export const phone = window.matchMedia('(max-width: 760px)');

const scSlot = () => $(`[data-sc-slot="${phone.matches ? 'sheet' : 'stage'}"]`);

export function scSend(method, value) {
  if (!sc.frame || !sc.frame.contentWindow) return;
  sc.frame.contentWindow.postMessage(JSON.stringify(value === undefined ? { method } : { method, value }), SC_ORIGIN);
}

/** The place for the player of a SoundCloud entry: the note, or the player once allowed. */
export function scShow(it, start) {
  const slot = scSlot();
  $$('[data-sc-slot]').forEach((s) => { s.hidden = s !== slot; });
  if (start) $('[data-r1="mini"]').hidden = false;
  if (!sc.ok) {
    clearTimeout(sc.timer);
    setPhase('idle');
    if (start && phone.matches) openDialog('sheet');   // the note is in the full player
    return;
  }
  if (sc.frame && sc.url === it.url) {
    if (start) scPlay();
    else setPhase('paused');
    return;
  }
  scLoad(it, start);
}

function scLoad(it, autoplay) {
  const slot = scSlot();
  if (!sc.frame) {
    sc.frame = el('iframe', 'sc-frame');
    sc.frame.title = T.scFrame;
    sc.frame.allow = 'autoplay; encrypted-media';
  }
  sc.url = it.url;
  sc.ready = false;
  sc.want = autoplay;
  const q = new URLSearchParams({
    url: it.url, auto_play: String(autoplay), visual: 'false', show_artwork: 'false', show_comments: 'false',
    show_reposts: 'false', show_teaser: 'false', show_playcount: 'false', hide_related: 'true',
    buying: 'false', sharing: 'false', download: 'false', color: '#1a80b6',
  });
  sc.frame.src = SC_ORIGIN + '/player/?' + q;
  $$('[data-sc-card]').forEach((c) => { c.hidden = true; });
  if (sc.frame.parentElement !== slot) slot.append(sc.frame);
  setPhase(autoplay ? 'connecting' : 'paused');
  if (autoplay) scWatch();
}

export function scPlay() {
  if (!sc.ok) {
    scAsk();
    return;
  }
  if (!sc.frame || sc.url !== state.cur.url) {
    scLoad(state.cur, true);
    return;
  }
  sc.want = true;
  setPhase('connecting');
  if (sc.ready) scSend('play');   // otherwise once the player is ready
  scWatch();
}

export function scPause() {
  clearTimeout(sc.timer);
  sc.want = false;
  scSend('pause');
  setPhase(sc.frame ? 'paused' : 'idle');
}

/** Phones may refuse to start a player from outside: then show it, a tap there starts it. */
function scWatch() {
  clearTimeout(sc.timer);
  sc.timer = setTimeout(() => {
    if (!isSc(state.cur) || state.phase !== 'connecting') return;
    setPhase('paused');
    if (phone.matches) openDialog('sheet');
  }, 6000);
}

/** Focus into the place of the player: its load button, or the place itself (arrows keep working). */
export function scFocus() {
  const slot = scSlot();
  if (slot.hidden || !slot.offsetParent) return;   // phones: the full player is closed
  const b = $('[data-sc-load]', slot);
  (b && !b.closest('[hidden]') ? b : slot).focus();
}

/** Play without permission yet: point at the button that loads the player. */
function scAsk() {
  if (phone.matches) openDialog('sheet');
  const b = $('[data-sc-load]', scSlot());
  if (!b) return;
  b.classList.remove('nudge');
  void b.offsetWidth;   // restart the animation
  b.classList.add('nudge');
  b.focus();
}

export function scClose() {
  clearTimeout(sc.timer);
  if (sc.frame) sc.frame.remove();
  Object.assign(sc, { frame: null, url: '', ready: false, want: false });
  $$('[data-sc-slot]').forEach((s) => { s.hidden = true; });
  $$('[data-sc-card]').forEach((c) => { c.hidden = false; });
}

/** A track came to its end: the next entry of the list if it is SoundCloud too, else stop. */
function scNext() {
  const list = stationsOf(state.curLevel);
  const i = list.findIndex((x) => x.id === state.cur.id);
  const next = i >= 0 ? list[i + 1] : null;
  if (next && isSc(next)) play(next, state.curLevel);
  else scPause();
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  window.addEventListener('message', (e) => {
    if (e.origin !== SC_ORIGIN || !sc.frame || e.source !== sc.frame.contentWindow || !isSc(state.cur)) return;
    let msg = null;
    try { msg = JSON.parse(e.data); } catch { return; }
    if (!msg || typeof msg.method !== 'string') return;
    if (msg.method === 'ready') {
      sc.ready = true;
      ['play', 'pause', 'finish', 'error', 'playProgress'].forEach((ev) => scSend('addEventListener', ev));
      scSend('setVolume', volume);
      if (sc.want) scSend('play');
    } else if (msg.method === 'play' || (msg.method === 'playProgress' && sc.want)) {
      // the player may start without a "play" event, then progress tells; after a pause
      // one more progress report can follow, which must not count as playing again
      if (state.phase !== 'live') {
        clearTimeout(sc.timer);
        sc.want = true;
        setPhase('live');
      }
    } else if (msg.method === 'pause') {
      if (isPlaying()) {
        sc.want = false;
        setPhase('paused');
      }
    } else if (msg.method === 'finish') {
      scNext();
    } else if (msg.method === 'error') {
      clearTimeout(sc.timer);
      setPhase('error');
    }
  });

  $$('[data-sc-load]').forEach((b) => b.addEventListener('click', () => {
    const hadFocus = document.activeElement === b;   // the button goes away with the note
    sc.ok = true;
    if (isSc(state.cur)) scLoad(state.cur, true);
    if (hadFocus) scFocus();
  }));
  $$('[data-sc-more]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    openPage('datenverarbeitung');
  }));
  // the player moves with the layout (this reloads it; it continues if it was playing)
  phone.addEventListener('change', () => {
    if (!isSc(state.cur)) return;
    const slot = scSlot();
    $$('[data-sc-slot]').forEach((s) => { s.hidden = s !== slot; });
    if (sc.frame && sc.frame.parentElement !== slot) {
      sc.ready = false;
      sc.want = isPlaying();
      slot.append(sc.frame);
    }
  });
}
