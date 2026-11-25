// 1radiœ web app: playing radio in <audio>, hls.js where the browser cannot play HLS,
// reconnects, volume. SoundCloud and YouTube play in their own players.

import { $, $$, RETRY, SCRIPT_SRC, data, isOwn, isPlaying, isSc, isYt, items, state, stationsOf } from './core.js';
import { reveal, setView } from './scale.js';
import { renderPlayer, setPhase } from './view.js';
import { sc, scClose, scFocus, scPause, scPlay, scSend, scShow } from './soundcloud.js';
import { yt, ytClose, ytPause, ytPlay, ytSend, ytShow } from './youtube.js';
import { listen, listenStop } from './status.js';
import { openDeezer } from './dialogs.js';

/* ---------- Audio ---------- */

const audio = new Audio();

let retryTimer = 0;
let lastTime = 0;
let lastProgress = 0;

/* HLS in browsers that cannot play it in <audio>, Firefox above all: hls.js,
   stored on this server (assets/vendor) and loaded only then. It fetches the playlist
   and its pieces from the station and hands them to <audio>. Every other stream and
   every other browser plays straight from <audio> as before. */
// next to the entry app.js (its versioned folder), the same address on / and /de/
const HLS_LIB = new URL('vendor/hls.light.min.js?v=1.7.4', SCRIPT_SRC).href;
let hls = null;
let hlsLoading = null;
let hlsRun = 0;   // a newer connect or a stop cancels a start that is still loading

/** HLS by the format from the admin (the stream test reads the playlist), else by .m3u8. */
function needsHlsJs(st) {
  let path = '';
  try { path = new URL(st.url, location.href).pathname; } catch { return false; }
  return (st.hls === true || /\.m3u8$/i.test(path)) && !audio.canPlayType('application/vnd.apple.mpegurl') && 'MediaSource' in window;
}

function loadHlsJs() {
  hlsLoading ??= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = HLS_LIB;
    s.onload = () => resolve(window.Hls);
    s.onerror = () => { hlsLoading = null; reject(new Error('hls.js')); };
    document.head.append(s);
  });
  return hlsLoading;
}

function dropHls() {
  hlsRun++;
  if (hls) {
    hls.destroy();
    hls = null;
  }
}

async function connectHls(url) {
  const run = hlsRun;
  let Hls;
  try {
    Hls = await loadHlsJs();
  } catch {
    if (run === hlsRun) failed(0);   // file not loaded, maybe offline: retry
    return;
  }
  if (run !== hlsRun) return;
  if (!Hls || !Hls.isSupported()) {
    failed(4);
    return;
  }
  // no worker: it would need blob: scripts, which the page's CSP does not allow
  hls = new Hls({ enableWorker: false });
  hls.on(Hls.Events.MANIFEST_PARSED, () => { if (run === hlsRun) startPlayback(); });
  hls.on(Hls.Events.ERROR, (_e, data) => {
    if (data.fatal && run === hlsRun) failed(data.type === Hls.ErrorTypes.NETWORK_ERROR ? 0 : 4);
  });
  hls.loadSource(url);
  hls.attachMedia(audio);
}

function startPlayback() {
  const started = audio.play();
  if (started && started.catch) {
    started.catch((err) => {
      if (err && err.name === 'NotAllowedError') setPhase('paused');   // autoplay blocked, needs a tap
      else if (!err || err.name !== 'AbortError') failed(0);            // AbortError: replaced by a newer connect
    });
  }
}

export function play(it, level) {
  state.touched = true;
  state.mqPick = it.id;
  if (listen.id && listen.id !== it.id) listenStop(false);   // switching stations ends the old one
  const fromSc = isSc(state.cur);
  const fromYt = isYt(state.cur);
  // the buttons disappear for SoundCloud; a keyboard focus on them must not get lost
  const focusOnButtons = document.activeElement instanceof Element && Boolean(document.activeElement.closest('.controls'));
  state.cur = it;
  state.curLevel = level;
  renderPlayer();
  if (isSc(it)) {
    hangUp();          // the radio stops, SoundCloud plays in its own player
    if (fromYt) ytClose();
    scShow(it, true);
    if (focusOnButtons) scFocus();
  } else if (isYt(it)) {
    hangUp();          // the radio stops, YouTube plays in its own player
    if (fromSc) scClose();
    ytShow(it, true);
  } else {
    if (fromSc) scClose();
    if (fromYt) ytClose();
    connect(true);
  }
  reveal(false);
}

/** (Re)connects to the current station. Always sets the address again, so no stale buffer is left. */
function connect(fresh) {
  if (isSc(state.cur)) {
    scPlay();
    return;
  }
  if (isYt(state.cur)) {
    ytPlay();
    return;
  }
  clearTimeout(retryTimer);
  retryTimer = 0;
  if (fresh) state.retry = 0;
  setPhase('connecting');
  lastProgress = Date.now();
  dropHls();
  if (needsHlsJs(state.cur)) {
    connectHls(state.cur.url);
    return;
  }
  audio.src = state.cur.url;
  startPlayback();
}

/** Pausing live radio means hanging up; playing again starts fresh at the live point. */
export function stop() {
  if (isSc(state.cur)) {
    scPause();
    return;
  }
  if (isYt(state.cur)) {
    ytPause();
    return;
  }
  setPhase(state.cur ? 'paused' : 'idle');
  hangUp();
}

function hangUp() {
  clearTimeout(retryTimer);
  retryTimer = 0;
  dropHls();
  audio.pause();
  audio.removeAttribute('src');
  audio.load();
}

function failed(code) {
  if (!isPlaying() || isOwn(state.cur)) return;
  // one failure often comes twice (the error event and the refused play()): the retry that
  // already waits covers it, counted once; so pausing or another station always stops it
  if (retryTimer) return;
  // code 4: format or address not playable here, a long retry would not help
  const limit = code === 4 ? 2 : RETRY.length;
  if (state.retry >= limit) {
    stop();
    setPhase('error');
    return;
  }
  setPhase('connecting');
  retryTimer = setTimeout(() => connect(false), RETRY[state.retry++] * 1000);
}

export function toggle() {
  state.touched = true;
  if (!state.cur) {
    const first = stationsOf('root').find((x) => x.type === 'station') || [...items.values()].find((x) => x.type === 'station');
    if (first) play(first, first.level);
    return;
  }
  if (isPlaying()) stop();
  else connect(true);
}

export function step(dir) {
  const list = stationsOf(state.curLevel);
  if (!list.length) return;
  let i = state.cur ? list.findIndex((x) => x.id === state.cur.id) : -1;
  if (i < 0) i = dir > 0 ? -1 : 0;
  play(list[(i + dir + list.length) % list.length], state.curLevel);
}

export function pick(it, level) {
  if (it.type === 'category') {
    setView(it.id);
    return;
  }
  if (it.type === 'deezer') {
    openDeezer(it);   // the radio keeps playing
    return;
  }
  if (state.cur && state.cur.id === it.id && isPlaying()) stop();
  else play(it, level);
}

/* Volume, remembered in this browser only. iOS does not let pages change it. */
const canVolume = (() => {
  try {
    const a = document.createElement('audio');
    a.volume = 0.5;
    return Math.abs(a.volume - 0.5) < 0.01;
  } catch {
    return false;
  }
})();
export let volume = 80;

// the waves at the speaker follow the volume (app.css, .vol-ic)
const showVolume = () => {
  $$('[data-volume]').forEach((n) => n.style.setProperty('--v', String(volume / 100)));
  $$('[data-r1="volume"]').forEach((n) => n.setAttribute('aria-valuetext', `${volume} %`));
};

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  audio.preload = 'none';

  audio.addEventListener('playing', () => {
    if (isOwn(state.cur)) return;
    // it plays (again) by itself: a reconnect that still waits is not needed any more
    clearTimeout(retryTimer);
    retryTimer = 0;
    state.retry = 0;
    lastProgress = Date.now();
    setPhase('live');
  });
  audio.addEventListener('timeupdate', () => {
    if (audio.currentTime !== lastTime) {
      lastTime = audio.currentTime;
      lastProgress = Date.now();
    }
  });
  audio.addEventListener('waiting', () => { if (state.phase === 'live' && !isOwn(state.cur)) setPhase('connecting'); });
  audio.addEventListener('error', () => failed(audio.error ? audio.error.code : 0));
  audio.addEventListener('ended', () => failed(0));   // live radio does not end: reconnect
  // paused from outside, e.g. headphones unplugged or the system took the audio
  audio.addEventListener('pause', () => { if (state.phase === 'live' && !isOwn(state.cur)) stop(); });

  // watchdog: no progress for a while means the connection is gone
  setInterval(() => {
    if (!isPlaying() || retryTimer || isOwn(state.cur)) return;
    const quiet = Date.now() - lastProgress;
    if ((state.phase === 'live' && quiet > 12000) || (state.phase === 'connecting' && quiet > 20000)) failed(0);
  }, 3000);
  window.addEventListener('online', () => { if (state.cur && !isOwn(state.cur) && (isPlaying() || state.phase === 'error')) connect(true); });

  try {
    const saved = localStorage.getItem('r1.volume');
    if (saved !== null && saved !== '' && !Number.isNaN(Number(saved))) volume = Math.min(100, Math.max(0, Number(saved)));
  } catch { /* storage blocked: default volume */ }
  audio.volume = volume / 100;

  $$('[data-volume]').forEach((n) => { n.hidden = !canVolume; });
  showVolume();
  $$('[data-r1="volume"]').forEach((input) => {
    input.value = String(volume);
    input.addEventListener('input', () => {
      volume = Number(input.value);
      audio.volume = volume / 100;
      showVolume();
      if (sc.frame) scSend('setVolume', volume);
      if (yt.frame) ytSend('setVolume', [volume]);
      try { localStorage.setItem('r1.volume', String(volume)); } catch { /* not remembered */ }
    });
    // the mouse wheel over the speaker and the slider sets the volume: one notch is 5 %,
    // a trackpad moves it in small steps; up is louder
    let wheelRest = 0;
    input.closest('[data-volume]').addEventListener('wheel', (e) => {
      if (e.ctrlKey || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
      e.preventDefault();
      const px = e.deltaMode === 1 ? e.deltaY * 33 : e.deltaMode === 2 ? e.deltaY * 100 : e.deltaY;
      wheelRest += -px / 20;
      const step = Math.trunc(wheelRest);
      if (!step) return;
      wheelRest -= step;
      const next = Math.min(100, Math.max(0, Number(input.value) + step));
      if (next === Number(input.value)) return;
      input.value = String(next);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }, { passive: false });
  });
}
