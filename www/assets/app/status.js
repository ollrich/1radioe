// 1radiœ web app: Ollie's listening status (the bars, "Zuletzt von Olli gehört") and,
// in his own browser, reporting what he listens to.

import { $, $$, STATUS_EVERY, T, data, isPlaying, items, state } from './core.js';
import { setText, updateIndicators } from './view.js';
import { play } from './audio.js';

/* ---------- Listening status ---------- */

let statusTimer = 0;
export async function pollStatus() {
  clearTimeout(statusTimer);
  if (document.hidden) return;
  try {
    const res = await fetch('api/status', { cache: 'no-store', headers: { Accept: 'application/json' } });
    if (res.ok) {
      const json = await res.json();
      state.active = new Set(Array.isArray(json.active) ? json.active : []);
      state.last = typeof json.last === 'string' ? json.last : null;
      updateIndicators();
      renderLastHeard();
    }
  } catch { /* offline: keep the last state */ }
  statusTimer = setTimeout(pollStatus, STATUS_EVERY);
}

/** "Zuletzt von Olli gehört: <station>": only while he plays nothing, the bars show that. */
function renderLastHeard() {
  const it = state.last ? items.get(state.last) : null;
  const show = Boolean(it) && state.active.size === 0;
  $$('[data-r1="last"]').forEach((p) => { p.hidden = !show; });
  if (show) $$('[data-r1="last-station"]').forEach((b) => setText(b, it.name));
}

/* ---------- Reporting Ollie's own listening ----------
 * Only in a browser with the listening cookie (the page says so in data.listen).
 * "on" when playback starts and then every data.interval seconds while it plays,
 * "off" on pause, error, another station or when the page goes away. Visitors
 * never report anything. */

export const listen = { id: null, timer: 0, paused: false };
const listenSwitch = $('[data-listen-switch]');

function recording() {
  if (data.listen !== true) return false;
  try {
    return sessionStorage.getItem('r1.listen.paused') !== '1';
  } catch {
    return !listen.paused;
  }
}

function report(id, on, beacon) {
  const body = JSON.stringify({ station: id, state: on ? 'on' : 'off' });
  if (beacon && navigator.sendBeacon) {
    navigator.sendBeacon('api/listen', new Blob([body], { type: 'application/json' }));
  } else {
    fetch('api/listen', {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', 'X-1radioe': '1' },
      body,
    }).then((res) => {
      if (res.status === 401) {
        // this browser's key was revoked in the admin: stop reporting
        data.listen = false;
        clearInterval(listen.timer);
        listen.timer = 0;
      }
      if (!res.ok) pollStatus();   // show what the server knows instead
    }).catch(() => pollStatus());
  }
  // show it here right away instead of waiting for the next status poll
  if (on) state.active.add(id);
  else state.active.delete(id);
  updateIndicators();
}

export function listenStart() {
  if (!recording() || !state.cur) return;
  if (listen.id !== state.cur.id) {
    listenStop(false);
    listen.id = state.cur.id;
  }
  report(listen.id, true);
  clearInterval(listen.timer);
  listen.timer = setInterval(() => { if (listen.id && isPlaying()) report(listen.id, true); }, (data.interval || 60) * 1000);
}

export function listenStop(beacon) {
  clearInterval(listen.timer);
  listen.timer = 0;
  if (listen.id) {
    report(listen.id, false, beacon);
    listen.id = null;
  }
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  document.addEventListener('visibilitychange', () => { if (!document.hidden) pollStatus(); });

  $$('[data-r1="last-station"]').forEach((b) => b.addEventListener('click', () => {
    const it = state.last ? items.get(state.last) : null;
    if (it) play(it, it.level);
  }));

  if (listenSwitch) {
    const sync = () => {
      const on = recording();
      listenSwitch.setAttribute('aria-pressed', String(on));
      $('[data-listen-label]', listenSwitch).textContent = on ? T.listenOn : T.listenOff;
    };
    listenSwitch.addEventListener('click', () => {
      const on = !recording();
      listen.paused = !on;
      try { sessionStorage.setItem('r1.listen.paused', on ? '0' : '1'); } catch { /* kept in memory */ }
      if (!on) listenStop(false);
      else if (state.phase === 'live') listenStart();
      sync();
    });
    sync();
  }
  window.addEventListener('pagehide', () => listenStop(true));
}
