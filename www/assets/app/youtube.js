// 1radiœ web app: videos of YouTube playlists in YouTube's player.

import { $, $$, T, el, image, isPlaying, isYt, state, stationsOf } from './core.js';
import { renderPlayer, setPhase } from './view.js';
import { play, volume } from './audio.js';
import { phone } from './soundcloud.js';
import { openDialog } from './dialogs.js';
import { renderTrack } from './song.js';

/* ---------- YouTube ----------
 * A video of a YouTube playlist plays in YouTube's own player (an iframe from
 * youtube-nocookie.com), never in <audio>, and visible where a station shows its logo:
 * YouTube allows no sound without the picture and nothing on top of its player. It
 * loads only after a click on "YouTube laden", for this visit. The page steers the
 * player with postMessage, the protocol of YouTube's IFrame API, without loading that
 * script; our buttons stay. On phones it plays in the full player, closing that pauses
 * it (a hidden player must not play). A video that is gone shows so and the next one
 * follows. No listening status and no song lookups for YouTube. */

const YT_ORIGIN = 'https://www.youtube-nocookie.com';
export const yt = { ok: false, frame: null, video: '', ready: false, want: false, timer: 0, hello: 0, gone: 0, pos: null, dur: 0, line: '' };
const ytBox = () => $(`[data-yt-box="${phone.matches ? 'sheet' : 'stage'}"]`);
const ytSlot = () => $(`[data-yt-slot="${phone.matches ? 'sheet' : 'stage'}"]`);

export function ytSend(func, args = []) {
  if (!yt.frame || !yt.frame.contentWindow) return;
  yt.frame.contentWindow.postMessage(JSON.stringify({ event: 'command', func, args, id: 1, channel: 'widget' }), YT_ORIGIN);
}

/** The picture of the video in every place that does not hold the player. */
export function ytPicture(it) {
  $$('[data-yt-box]').forEach((b) => {
    if (yt.frame && yt.frame.parentElement === b) return;
    b.replaceChildren();
    if (it.logo) b.append(image(it.logo, () => b.replaceChildren()));
  });
}

/** The place of a video: picture and note, or the player once allowed. */
export function ytShow(it, start) {
  const box = ytBox();
  $$('[data-yt-box]').forEach((b) => { b.hidden = b !== box; });
  if (start) $('[data-r1="mini"]').hidden = false;
  if (!yt.ok) {
    const slot = ytSlot();
    $$('[data-yt-slot]').forEach((s) => { s.hidden = s !== slot; });
    clearTimeout(yt.timer);
    setPhase('idle');
    if (start && phone.matches) openDialog('sheet');   // the note is in the full player
    return;
  }
  $$('[data-yt-slot]').forEach((s) => { s.hidden = true; });
  if (yt.frame && yt.video === it.video && yt.frame.parentElement === box) {
    if (start) ytPlay();
    else setPhase('paused');
    return;
  }
  ytLoad(it, start);
}

function ytLoad(it, autoplay) {
  const box = ytBox();
  clearTimeout(yt.gone);
  yt.video = it.video;
  yt.want = autoplay;
  Object.assign(yt, { pos: null, dur: 0, line: '' });
  if (yt.frame && yt.ready && yt.frame.parentElement === box) {
    ytSend(autoplay ? 'loadVideoById' : 'cueVideoById', [it.video]);   // the same player, no new consent to start
  } else {
    if (!yt.frame) {
      yt.frame = el('iframe', 'yt-frame');
      yt.frame.title = T.ytFrame;
      yt.frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      // the page sends no referrer, YouTube's player needs one: only the origin goes along
      yt.frame.referrerPolicy = 'strict-origin-when-cross-origin';
    }
    yt.ready = false;
    const q = new URLSearchParams({ enablejsapi: '1', origin: location.origin, autoplay: autoplay ? '1' : '0', playsinline: '1', rel: '0', iv_load_policy: '3' });
    yt.frame.onload = ytHello;   // once the player is there, not before
    yt.frame.src = `${YT_ORIGIN}/embed/${encodeURIComponent(it.video)}?${q}`;
    box.replaceChildren(yt.frame);
  }
  if (phone.matches && autoplay) openDialog('sheet');   // visible while it plays
  setPhase(autoplay ? 'connecting' : 'paused');
  if (autoplay) ytWatch();
}

/** Asks the player for its events until it answers (what YouTube's own script does). */
function ytHello() {
  clearInterval(yt.hello);
  let tries = 0;
  const hello = () => {
    if (yt.ready || !yt.frame || ++tries > 40) {
      clearInterval(yt.hello);
      return;
    }
    const w = yt.frame.contentWindow;
    let blank = false;   // still the empty page before YouTube's (readable, ours): wait
    try { blank = Boolean(w && w.location.href); } catch { /* YouTube's page, as wanted */ }
    if (w && !blank) w.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), YT_ORIGIN);
  };
  hello();
  yt.hello = setInterval(hello, 250);
}

export function ytPlay() {
  if (!yt.ok) {
    ytAsk();
    return;
  }
  if (!yt.frame || yt.video !== state.cur.video || yt.frame.parentElement !== ytBox()) {
    ytLoad(state.cur, true);
    return;
  }
  if (phone.matches) openDialog('sheet');
  yt.want = true;
  setPhase('connecting');
  ytSend('playVideo');
  ytWatch();
}

export function ytPause() {
  clearTimeout(yt.timer);
  clearTimeout(yt.gone);
  yt.want = false;
  ytSend('pauseVideo');
  setPhase(yt.frame ? 'paused' : 'idle');
}

/** Browsers may refuse to start a player from outside: then a tap on YouTube's own button starts it. */
function ytWatch() {
  clearTimeout(yt.timer);
  yt.timer = setTimeout(() => {
    if (isYt(state.cur) && state.phase === 'connecting') setPhase('paused');
  }, 8000);
}

/** Play without permission yet: point at the button that loads the player. */
function ytAsk() {
  if (phone.matches) openDialog('sheet');
  const b = $('[data-yt-load]', ytSlot());
  if (!b) return;
  b.classList.remove('nudge');
  void b.offsetWidth;   // restart the animation
  b.classList.add('nudge');
  b.focus();
}

export function ytClose() {
  clearTimeout(yt.timer);
  clearTimeout(yt.gone);
  clearInterval(yt.hello);
  if (yt.frame) yt.frame.remove();
  Object.assign(yt, { frame: null, video: '', ready: false, want: false, pos: null, dur: 0, line: '' });
  $$('[data-yt-box]').forEach((b) => { b.hidden = true; b.replaceChildren(); });
  $$('[data-yt-slot]').forEach((s) => { s.hidden = true; });
}

/** Seconds as on YouTube: 4:05, or 1:04:05 if the video is an hour or longer. */
function clock(sec, long) {
  const s = Math.floor(sec);
  const m = Math.floor(s / 60) % 60;
  const h = Math.floor(s / 3600);
  const ss = String(s % 60).padStart(2, '0');
  return long || h ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}

/** Channel and length of a video; for the one in the player the time runs: "Kanal · 12:34 / 1:02:03". */
export function ytLine(it) {
  if (!it || yt.video !== it.video || yt.pos === null) return it ? it.desc || '' : '';
  const dur = it.length || yt.dur;   // the length of the list first, the player rounds it down
  if (!dur) return it.desc || '';
  const long = dur >= 3600;
  return [it.channel, `${clock(Math.min(yt.pos, dur), long)} / ${clock(dur, long)}`].filter(Boolean).join(' · ');
}

/** The player reports where it is (also after a jump, also paused): the line follows. */
function ytTime(info) {
  const v = info.videoData && info.videoData.video_id;
  if (v && v !== yt.video) return;   // a last report about the video before
  if (typeof info.duration === 'number' && info.duration > 0) yt.dur = info.duration;
  if (typeof info.currentTime === 'number') {
    // right after loading another video the old position may come once more: only from the start
    if (yt.pos === null && !v && info.currentTime > 2) return;
    yt.pos = Math.max(0, info.currentTime);
  }
  const line = ytLine(state.cur);
  if (line !== yt.line) {
    yt.line = line;
    renderTrack();
  }
}

/** A video came to its end: the next entry of the list if it is a video too, else stop. */
function ytNext() {
  const list = stationsOf(state.curLevel);
  const i = list.findIndex((x) => x.id === state.cur.id);
  const next = i >= 0 ? list[i + 1] : null;
  if (next && isYt(next)) play(next, state.curLevel);
  else ytPause();
}

/** The player cannot play this video (private, deleted, not embeddable here): say so, then the next. */
function ytGone() {
  clearTimeout(yt.timer);
  clearTimeout(yt.gone);
  setPhase('error');
  const it = state.cur;
  yt.gone = setTimeout(() => { if (state.cur === it) ytNext(); }, 3000);
}

// closing the full player on a phone hides the video: then it must not play on
const sheetEl = $('#sheet');

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  window.addEventListener('message', (e) => {
    if (e.origin !== YT_ORIGIN || !yt.frame || e.source !== yt.frame.contentWindow) return;
    let msg = null;
    try { msg = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch { return; }
    if (!msg || typeof msg.event !== 'string') return;
    if (!yt.ready) {   // the first answer: the player listens now
      yt.ready = true;
      clearInterval(yt.hello);
      ytSend('addEventListener', ['onStateChange']);
      ytSend('addEventListener', ['onError']);
      ytSend('setVolume', [volume]);
    }
    if (!isYt(state.cur)) return;
    let st = null;
    if (msg.event === 'onStateChange' && typeof msg.info === 'number') st = msg.info;
    else if (msg.event === 'infoDelivery' && msg.info && typeof msg.info.playerState === 'number') st = msg.info.playerState;
    else if (msg.event === 'onError') {
      ytGone();
      return;
    }
    if (msg.event === 'infoDelivery' && msg.info && typeof msg.info === 'object') ytTime(msg.info);
    if (st === 1) {          // playing
      clearTimeout(yt.timer);
      yt.want = true;
      if (state.phase !== 'live') setPhase('live');
    } else if (st === 2) {   // paused, also by YouTube's own button
      if (isPlaying()) {
        yt.want = false;
        setPhase('paused');
      }
    } else if (st === 0 && state.phase !== 'error') {   // ended
      ytNext();
    }
  });

  $$('[data-yt-load]').forEach((b) => b.addEventListener('click', () => {
    yt.ok = true;
    if (isYt(state.cur)) ytShow(state.cur, true);
  }));

  if (sheetEl) sheetEl.addEventListener('close', () => { if (isYt(state.cur) && phone.matches && isPlaying()) ytPause(); });
  // the player moves with the layout: it starts again from its picture, paused
  phone.addEventListener('change', () => {
    if (!isYt(state.cur)) return;
    const it = state.cur;
    ytClose();
    renderPlayer();
    ytShow(it, false);
  });
}
