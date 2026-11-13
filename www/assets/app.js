// 1radiœ web app: player, scale with needle (wide screens), list with docked player
// (phones), listening status. Station data comes from the page (#r1-data).
// No framework and no requests to anyone but this server and the streams.
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const dataEl = $('#r1-data');
  if (!dataEl) return;
  const data = JSON.parse(dataEl.textContent);
  const { levels } = data;

  const SLOT = 88;                                         // width of a place on the scale, as in CSS
  const RETRY = [1, 2, 5, 10, 10, 10, 10, 10, 10, 10, 10, 10];   // seconds between reconnects
  const STATUS_EVERY = 30000;
  const TEXT = { idle: 'Bereit', connecting: 'Verbindet', live: 'Live', paused: 'Pausiert', error: 'Keine Verbindung' };
  // the frame speaks the page's language; status and buttons stay as they are for now
  const T = Object.assign({ listening: 'Olli hört gerade', listeningTo: 'Olli hört gerade', and: 'und' }, data.text || {});
  const LISTENING = T.listening;
  const NOW_EVERY = 25000;                                 // how often the song title is asked for while playing

  /** Every station and category by id; stations know their level ('root' or a category id). */
  const items = new Map();
  for (const [level, list] of Object.entries(levels)) {
    for (const it of list) items.set(it.id, Object.assign(it, { level }));
  }
  const stationsOf = (level) => (levels[level] || []).filter((x) => x.type === 'station');

  const state = {
    view: 'root',        // level shown on the scale
    cur: null,           // the station in the player
    curLevel: 'root',    // level it was started from: Vor/Zurück stays there
    phase: 'idle',       // idle | connecting | live | paused | error
    active: new Set(),   // stations Ollie is listening to
    openCat: null,       // open category in the phone list
    retry: 0,
  };
  const isPlaying = () => state.phase === 'live' || state.phase === 'connecting';

  /* ---------- Helpers ---------- */

  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function initials(name) {
    const words = String(name).replace(/[^\p{L}\p{N} ]+/gu, ' ').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return '?';
    if (words.length === 1) return words[0].slice(0, 3).toUpperCase();
    return (words[0][0] + words[1][0]).toUpperCase();
  }

  function image(src, onFail) {
    const img = new Image();
    img.alt = '';
    img.decoding = 'async';
    img.loading = 'lazy';
    img.addEventListener('error', onFail, { once: true });
    img.src = src;
    return img;
  }

  /* small stack in the top right corner: this tile is a category, not a station */
  const STACK = '<svg viewBox="0 0 16 16" aria-hidden="true">'
    + '<rect x="5.2" y="1.8" width="9" height="9" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.6"/>'
    + '<rect x="1.8" y="5.2" width="9" height="9" rx="2.2" fill="currentColor"/></svg>';

  /**
   * Fills a tile with the logo, or the initials if there is none (or it fails to load).
   * A category shows its own picture from the admin, without one the collage the server
   * makes from the first logos inside (as in Sonos), and the stack on top.
   */
  function fillTile(node, it) {
    const isCat = it.type === 'category';
    node.replaceChildren();
    node.classList.toggle('cat', isCat);
    const stack = () => {
      if (!isCat) return;
      const s = el('span', 'stack');
      s.innerHTML = STACK;
      node.append(s);
    };
    if (it.logo) {
      node.append(image(it.logo, () => {
        node.textContent = initials(it.name);
        stack();
      }));
    } else node.textContent = initials(it.name);
    stack();
  }

  function pillEl(it) {
    const p = el('span', 'pill');
    p.dataset.item = it.id;   // the info on hover or tap names the station
    p.append(el('i'), el('i'), el('i'));
    p.hidden = true;
    return p;
  }

  function isActive(it) {
    if (it.type === 'category') return (levels[it.id] || []).some((x) => state.active.has(x.id));
    return state.active.has(it.id);
  }

  function holdsCur(cat) {
    return Boolean(state.cur && state.cur.level === cat.id);
  }

  function label(it) {
    let text = it.type === 'category'
      ? `${it.num} Kategorie ${it.name}, ${it.count} Sender`
      : `${it.num} ${it.name}`;
    if (it.type === 'station' && state.cur && state.cur.id === it.id) text += ', ' + TEXT[state.phase];
    if (it.type === 'category' && holdsCur(it)) text += ', enthält den aktuellen Sender';
    if (isActive(it)) text += ', ' + LISTENING;
    return text;
  }

  /* ---------- Scale (wide screens) ---------- */

  const scroller = $('[data-r1="scroller"]');
  const strip = $('[data-r1="strip"]');
  const crumbs = $('[data-r1="crumbs"]');
  const scale = { buttons: new Map(), needle: null };

  function renderScale() {
    const list = levels[state.view] || [];
    const ul = el('ul', 'slots');
    scale.buttons.clear();
    for (const it of list) {
      const li = el('li');
      const b = el('button', 'st');
      b.type = 'button';
      b.title = it.name;
      const tile = el('span', 'tile');
      fillTile(tile, it);
      const slot = el('span', 'pill-slot');
      slot.append(pillEl(it));
      const parts = [el('span', 'num', it.num), el('span', 'tick'), slot, tile, el('span', 'snm', it.name)];
      parts.forEach((p) => p.setAttribute('aria-hidden', 'true'));
      b.append(...parts);
      b.addEventListener('click', () => pick(it, state.view));
      li.append(b);
      ul.append(li);
      scale.buttons.set(it.id, b);
    }
    scale.needle = el('div', 'needle');
    scale.needle.setAttribute('aria-hidden', 'true');
    strip.style.width = (list.length * SLOT + 16) + 'px';
    strip.replaceChildren(scale.needle, ul);
    renderCrumbs();
    updateIndicators();
    requestAnimationFrame(() => { measure(); reveal(true); });
  }

  function renderCrumbs() {
    if (state.view === 'root') {
      crumbs.replaceChildren(el('span', '', 'Alle Sender'));
      return;
    }
    const back = el('button', 'pillbtn');
    back.type = 'button';
    back.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.7 4.3 8 12l7.7 7.7-1.4 1.4L5.2 12l9.1-9.1z"/></svg>';
    back.append('Alle Sender');
    back.addEventListener('click', () => setView('root'));
    const sep = el('span', 'sep', '/');
    sep.setAttribute('aria-hidden', 'true');
    crumbs.replaceChildren(back, sep, el('span', '', items.get(state.view).name));
  }

  function setView(view) {
    const from = state.view;
    state.view = view;
    renderScale();
    // keep the keyboard where it was: on "Alle Sender" or back on the category
    const target = view === 'root' ? scale.buttons.get(from) : $('button', crumbs);
    if (target) target.focus();
  }

  function needleIndex() {
    const list = levels[state.view] || [];
    if (!state.cur) return [-1, false];
    if (state.view === state.cur.level) return [list.findIndex((x) => x.id === state.cur.id), false];
    if (state.view === 'root') return [list.findIndex((x) => x.id === state.cur.level), true];
    return [-1, false];
  }

  /* Scroll arrows when the scale is wider than the screen */
  const arrows = $$('[data-scroll]');
  function measure() {
    const overflow = scroller.scrollWidth > scroller.clientWidth + 1;
    arrows.forEach((a) => {
      a.hidden = !overflow;
      const dir = Number(a.dataset.scroll);
      const can = dir < 0 ? scroller.scrollLeft > 2 : scroller.scrollLeft + scroller.clientWidth < scroller.scrollWidth - 2;
      a.classList.toggle('is-off', !can);
      a.tabIndex = can ? 0 : -1;
    });
  }

  /** Brings the current station (or its category) into view. */
  function reveal(jump) {
    if (scroller.scrollWidth <= scroller.clientWidth + 1) return;
    const [idx] = needleIndex();
    if (idx < 0) {
      if (jump) scroller.scrollTo({ left: 0, behavior: 'auto' });
      return;
    }
    const left = 8 + idx * SLOT;
    const target = Math.max(0, left + SLOT / 2 - scroller.clientWidth / 2);
    if (jump) {
      // a jump must not animate: the CSS asks for smooth scrolling, which can stall on load
      scroller.style.scrollBehavior = 'auto';
      scroller.scrollLeft = target;
      scroller.style.scrollBehavior = '';
      measure();
    } else if (left < scroller.scrollLeft || left + SLOT > scroller.scrollLeft + scroller.clientWidth) {
      scroller.scrollTo({ left: target, behavior: 'smooth' });
    }
  }

  arrows.forEach((a) => a.addEventListener('click', () => {
    const dir = Number(a.dataset.scroll);
    scroller.scrollBy({ left: dir * Math.max(SLOT, scroller.clientWidth - 2 * SLOT) });
  }));
  let measureFrame = 0;
  scroller.addEventListener('scroll', () => {
    cancelAnimationFrame(measureFrame);
    measureFrame = requestAnimationFrame(measure);
  }, { passive: true });
  if ('ResizeObserver' in window) new ResizeObserver(measure).observe(scroller);

  /* ---------- List (phones) ---------- */

  const listEl = $('[data-r1="list"]');
  const rows = new Map();

  function rowFor(it, level) {
    const row = el('button', 'row' + (level !== 'root' ? ' is-sub' : ''));
    row.type = 'button';
    const tile = el('span', 'tile tile-s');
    fillTile(tile, it);
    const name = el('span', 'rname', it.name);
    const sub = el('span', 'rsub');
    const rn = el('span', 'rn');
    rn.append(name, sub);
    const pill = pillEl(it);
    const parts = [el('span', 'num', it.num), tile, rn, pill];
    if (it.type === 'category') {
      const chev = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      chev.setAttribute('class', 'chev');
      chev.setAttribute('width', '20');
      chev.setAttribute('height', '20');
      chev.setAttribute('viewBox', '0 0 24 24');
      chev.innerHTML = '<path d="M5.3 8.3 12 15l6.7-6.7 1.4 1.4L12 17.8 3.9 9.7z"/>';
      parts.push(chev);
    }
    parts.forEach((p) => p.setAttribute('aria-hidden', 'true'));
    row.append(...parts);
    rows.set(it.id, { row, sub, pill, it });
    return row;
  }

  function renderList() {
    listEl.replaceChildren();
    rows.clear();
    for (const it of levels.root) {
      const li = el('li');
      const row = rowFor(it, 'root');
      li.append(row);
      if (it.type === 'category') {
        const inner = el('ul');
        inner.id = 'cat-' + it.id;
        inner.hidden = state.openCat !== it.id;
        row.setAttribute('aria-controls', inner.id);
        row.setAttribute('aria-expanded', String(state.openCat === it.id));
        for (const ch of levels[it.id] || []) {
          const li2 = el('li');
          const r2 = rowFor(ch, it.id);
          r2.addEventListener('click', () => pick(ch, it.id));
          li2.append(r2);
          inner.append(li2);
        }
        row.addEventListener('click', () => {
          state.openCat = state.openCat === it.id ? null : it.id;
          $$('ul[id^="cat-"]', listEl).forEach((u) => { u.hidden = u.id !== 'cat-' + state.openCat; });
          $$('.row[aria-expanded]', listEl).forEach((r) => r.setAttribute('aria-expanded', String(r.getAttribute('aria-controls') === 'cat-' + state.openCat)));
          updateIndicators();
        });
        li.append(inner);
      } else {
        row.addEventListener('click', () => pick(it, 'root'));
      }
      listEl.append(li);
    }
  }

  /* ---------- Indicators: current station, needle, Ollie's status ---------- */

  function updateIndicators() {
    for (const [id, b] of scale.buttons) {
      const it = items.get(id);
      b.setAttribute('aria-current', String(Boolean(state.cur && state.cur.id === id)));
      b.setAttribute('aria-label', label(it));
      $('.pill', b).hidden = !isActive(it);
    }
    if (scale.needle) {
      const [idx, dim] = needleIndex();
      scale.needle.hidden = idx < 0;
      scale.needle.classList.toggle('dim', dim);
      if (idx >= 0) scale.needle.style.left = (8 + idx * SLOT + SLOT / 2) + 'px';
    }
    for (const { row, sub, pill, it } of rows.values()) {
      // the list has no player in view before the first start, so nothing is marked yet
      const cur = Boolean(state.cur && state.cur.id === it.id && state.phase !== 'idle');
      row.setAttribute('aria-current', String(cur));
      row.setAttribute('aria-label', label(it));
      sub.textContent = it.type === 'category' ? `${it.count} Sender` : cur ? (trackLine(currentTrack()) || TEXT[state.phase]) : (it.desc || '');
      // an open category shows the status at the station inside, not twice
      pill.hidden = !isActive(it) || (it.type === 'category' && state.openCat === it.id);
    }
    $$('[data-r1="pill"]').forEach((p) => { p.hidden = !(state.cur && state.active.has(state.cur.id)); });
  }

  /* ---------- Player views (stage, docked player, full player) ---------- */

  function renderPlayer() {
    const it = state.cur;
    if (!it) return;
    const cat = it.level !== 'root' ? items.get(it.level) : null;
    $$('[data-r1="art"]').forEach((n) => fillTile(n, it));
    $$('[data-r1="num"]').forEach((n) => { n.textContent = it.num; });
    $$('[data-r1="name"]').forEach((n) => {
      n.textContent = it.name;
      n.classList.toggle('is-long', it.name.length > 16);
    });
    $$('[data-r1="kicker"]').forEach((n) => { n.textContent = cat ? cat.name : ''; n.hidden = !cat; });
    $$('[data-r1="level"]').forEach((n) => { n.textContent = cat ? cat.name : 'Alle Sender'; });
    setMediaMetadata();
  }

  function setPhase(phase) {
    state.phase = phase;
    $$('[data-r1="state"]').forEach((n) => { n.textContent = TEXT[phase]; });
    $$('[data-r1="dot"]').forEach((n) => { n.className = 'dot ' + phase; });
    $$('[data-act="toggle"]').forEach((b) => {
      b.classList.toggle('is-playing', isPlaying());
      b.setAttribute('aria-label', isPlaying() ? 'Pause' : 'Abspielen');
    });
    if (phase !== 'idle') $('[data-r1="mini"]').hidden = false;
    if (phase === 'live') listenStart();
    else if (phase !== 'connecting') listenStop(false);
    if (phase === 'live' && (now.id !== state.cur.id || !now.timer)) pollNow();
    else if (phase !== 'live' && phase !== 'connecting') stopNow();
    document.title = isPlaying() && state.cur ? `${state.cur.name} · 1radiœ` : '1radiœ';
    if (media) media.playbackState = isPlaying() ? 'playing' : phase === 'paused' ? 'paused' : 'none';
    renderTrack();
  }

  /* ---------- Audio ---------- */

  const audio = new Audio();
  audio.preload = 'none';
  let retryTimer = 0;
  let lastTime = 0;
  let lastProgress = 0;

  /* HLS (.m3u8) in browsers that cannot play it in <audio>, Firefox above all: hls.js,
     stored on this server (assets/vendor) and loaded only then. It fetches the playlist
     and its pieces from the station and hands them to <audio>. Every other stream and
     every other browser plays straight from <audio> as before. */
  const HLS_LIB = 'assets/vendor/hls.light.min.js?v=1.7.4';
  let hls = null;
  let hlsLoading = null;
  let hlsRun = 0;   // a newer connect or a stop cancels a start that is still loading

  function needsHlsJs(url) {
    let path = '';
    try { path = new URL(url, location.href).pathname; } catch { return false; }
    return /\.m3u8$/i.test(path) && !audio.canPlayType('application/vnd.apple.mpegurl') && 'MediaSource' in window;
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

  function play(it, level) {
    if (listen.id && listen.id !== it.id) listenStop(false);   // switching stations ends the old one
    state.cur = it;
    state.curLevel = level;
    renderPlayer();
    connect(true);
    reveal(false);
  }

  /** (Re)connects to the current station. Always sets the address again, so no stale buffer is left. */
  function connect(fresh) {
    clearTimeout(retryTimer);
    retryTimer = 0;
    if (fresh) state.retry = 0;
    setPhase('connecting');
    lastProgress = Date.now();
    dropHls();
    if (needsHlsJs(state.cur.url)) {
      connectHls(state.cur.url);
      return;
    }
    audio.src = state.cur.url;
    startPlayback();
  }

  /** Pausing live radio means hanging up; playing again starts fresh at the live point. */
  function stop() {
    clearTimeout(retryTimer);
    retryTimer = 0;
    setPhase(state.cur ? 'paused' : 'idle');
    dropHls();
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
  }

  function failed(code) {
    if (!isPlaying()) return;
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

  function toggle() {
    if (!state.cur) {
      const first = stationsOf('root')[0] || [...items.values()].find((x) => x.type === 'station');
      if (first) play(first, first.level);
      return;
    }
    if (isPlaying()) stop();
    else connect(true);
  }

  function step(dir) {
    const list = stationsOf(state.curLevel);
    if (!list.length) return;
    let i = state.cur ? list.findIndex((x) => x.id === state.cur.id) : -1;
    if (i < 0) i = dir > 0 ? -1 : 0;
    play(list[(i + dir + list.length) % list.length], state.curLevel);
  }

  function pick(it, level) {
    if (it.type === 'category') {
      setView(it.id);
      return;
    }
    if (state.cur && state.cur.id === it.id && isPlaying()) stop();
    else play(it, level);
  }

  audio.addEventListener('playing', () => {
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
  audio.addEventListener('waiting', () => { if (state.phase === 'live') setPhase('connecting'); });
  audio.addEventListener('error', () => failed(audio.error ? audio.error.code : 0));
  audio.addEventListener('ended', () => failed(0));   // live radio does not end: reconnect
  // paused from outside, e.g. headphones unplugged or the system took the audio
  audio.addEventListener('pause', () => { if (state.phase === 'live') stop(); });

  // watchdog: no progress for a while means the connection is gone
  setInterval(() => {
    if (!isPlaying() || retryTimer) return;
    const quiet = Date.now() - lastProgress;
    if ((state.phase === 'live' && quiet > 12000) || (state.phase === 'connecting' && quiet > 20000)) failed(0);
  }, 3000);
  window.addEventListener('online', () => { if (state.cur && (isPlaying() || state.phase === 'error')) connect(true); });

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
  let volume = 80;
  try {
    const saved = localStorage.getItem('r1.volume');
    if (saved !== null && saved !== '' && !Number.isNaN(Number(saved))) volume = Math.min(100, Math.max(0, Number(saved)));
  } catch { /* storage blocked: default volume */ }
  audio.volume = volume / 100;
  $$('[data-volume]').forEach((n) => { n.hidden = !canVolume; });
  $$('[data-r1="volume"]').forEach((input) => {
    input.value = String(volume);
    input.addEventListener('input', () => {
      volume = Number(input.value);
      audio.volume = volume / 100;
      try { localStorage.setItem('r1.volume', String(volume)); } catch { /* not remembered */ }
    });
  });

  /* Media keys, Chrome's media controls, lock screen */
  const media = 'mediaSession' in navigator ? navigator.mediaSession : null;
  function setMediaMetadata() {
    if (!media || !state.cur || typeof MediaMetadata === 'undefined') return;
    const it = state.cur;
    const cat = it.level !== 'root' ? items.get(it.level) : null;
    const type = it.logo && /\.png$/i.test(it.logo) ? 'image/png' : 'image/jpeg';
    const t = currentTrack();
    media.metadata = new MediaMetadata({
      title: t ? t.title : it.name,
      artist: t ? (t.artist || it.name) : '1radiœ',
      album: t ? it.name : (cat ? cat.name : ''),
      artwork: it.logo ? [{ src: it.logo, sizes: '600x600', type }] : [],
    });
  }
  if (media) {
    const handle = (action, fn) => { try { media.setActionHandler(action, fn); } catch { /* not supported */ } };
    handle('play', () => { if (!isPlaying()) toggle(); });
    handle('pause', () => { if (isPlaying()) stop(); });
    handle('stop', () => { if (isPlaying()) stop(); });
    handle('previoustrack', () => step(-1));
    handle('nexttrack', () => step(1));
  }

  /* ---------- Listening status ---------- */

  let statusTimer = 0;
  async function pollStatus() {
    clearTimeout(statusTimer);
    if (document.hidden) return;
    try {
      const res = await fetch('api/status', { cache: 'no-store', headers: { Accept: 'application/json' } });
      if (res.ok) {
        const json = await res.json();
        state.active = new Set(Array.isArray(json.active) ? json.active : []);
        updateIndicators();
      }
    } catch { /* offline: keep the last state */ }
    statusTimer = setTimeout(pollStatus, STATUS_EVERY);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) pollStatus(); });

  /* ---------- Reporting Ollie's own listening ----------
   * Only in a browser with the listening cookie (the page says so in data.listen).
   * "on" when playback starts and then every data.interval seconds while it plays,
   * "off" on pause, error, another station or when the page goes away. Visitors
   * never report anything. */

  const listen = { id: null, timer: 0, paused: false };
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
      }).catch(() => { /* the status expires by itself */ });
    }
    // show it here right away instead of waiting for the next status poll
    if (on) state.active.add(id);
    else state.active.delete(id);
    updateIndicators();
  }

  function listenStart() {
    if (!recording() || !state.cur) return;
    if (listen.id !== state.cur.id) {
      listenStop(false);
      listen.id = state.cur.id;
    }
    report(listen.id, true);
    clearInterval(listen.timer);
    listen.timer = setInterval(() => { if (listen.id && isPlaying()) report(listen.id, true); }, (data.interval || 60) * 1000);
  }

  function listenStop(beacon) {
    clearInterval(listen.timer);
    listen.timer = 0;
    if (listen.id) {
      report(listen.id, false, beacon);
      listen.id = null;
    }
  }

  if (listenSwitch) {
    const sync = () => {
      const on = recording();
      listenSwitch.setAttribute('aria-pressed', String(on));
      $('[data-listen-label]', listenSwitch).textContent = on ? 'Hören wird erfasst' : 'Erfassung pausiert';
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

  /* ---------- Dialogs ---------- */

  function fillInfo() {
    const it = state.cur;
    if (!it) return false;
    fillTile($('[data-info="art"]'), it);
    $('[data-info="name"]').textContent = it.name;
    const desc = $('[data-info="desc"]');
    desc.textContent = it.desc || 'Keine Beschreibung.';
    const site = $('[data-info="website"]');
    site.parentElement.hidden = !it.website;   // the line holds only the website
    if (it.website) {
      site.href = it.website;
      let host = it.website;
      try { host = new URL(it.website).host.replace(/^www\./, ''); } catch { /* keep the address */ }
      site.textContent = 'Website: ' + host;
    }
    return true;
  }

  function openDialog(id) {
    const d = document.getElementById(id);
    if (!d || d.open || typeof d.showModal !== 'function') return;
    if (id === 'info' && !fillInfo()) return;
    d.showModal();
  }

  /* Menu on phones: a dialog that slides in from the right (class is-open moves it in).
     Its links show the text of a page in the menu itself, the arrow leads back to the links.
     The texts stay in the dialogs, which wide screens show as before. */
  const menu = $('#menu');
  const menuBtn = $('[data-menu]');
  const menuNav = $('.drawer-nav');
  const menuPage = $('[data-page]');
  const menuBack = $('[data-back]');
  const PAGES = ['about', 'impressum', 'datenverarbeitung'];
  // the page in links like 1radioe.net/#impressum; #datenschutz is the old name of #datenverarbeitung
  const pageFromHash = () => {
    const id = location.hash.slice(1) === 'datenschutz' ? 'datenverarbeitung' : location.hash.slice(1);
    return PAGES.includes(id) ? id : null;
  };
  const clearHash = () => history.replaceState(null, '', location.pathname + location.search);
  const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // copy the text of a dialog into the menu, without its close button and ids
  function showPage(id) {
    const src = document.getElementById(id);
    if (!menu || !src) return;
    const nodes = [...src.children].filter((n) => !n.matches('[data-close]')).map((n) => {
      const c = n.cloneNode(true);
      c.removeAttribute('id');
      $$('[id]', c).forEach((x) => x.removeAttribute('id'));
      return c;
    });
    menuPage.replaceChildren(...nodes);
    const h = $('h2', menuPage);
    if (h) {
      h.id = 'menu-page-title';
      h.tabIndex = -1;
    }
    menu.dataset.page = id;
    menuNav.hidden = true;
    menuNav.classList.remove('from-page');
    menuPage.hidden = false;
    menuBack.hidden = false;
    menu.scrollTop = 0;
    if (h) h.focus({ preventScroll: true });
  }

  function showLinks() {
    const from = menu.dataset.page;
    if (!from) return;
    delete menu.dataset.page;
    menuPage.hidden = true;
    menuPage.replaceChildren();
    menuBack.hidden = true;
    menuNav.hidden = false;
    menuNav.classList.add('from-page');
    menu.scrollTop = 0;
    const link = $(`[data-dialog="${from}"]`, menuNav);
    if (link) link.focus();
  }

  // a page from a link like 1radioe.net/#impressum: in the menu, on phones and on the desktop
  function openPage(id) {
    if (menu && menuBtn && typeof menu.showModal === 'function') {
      openMenu();
      showPage(id);
    } else openDialog(id);
  }

  function openMenu() {
    if (!menu || menu.open || typeof menu.showModal !== 'function') return;
    menu.showModal();
    menuBtn.setAttribute('aria-expanded', 'true');
    // read its size, so the browser places it outside first and then slides it in
    void menu.offsetWidth;
    menu.classList.add('is-open');
  }

  // the menu slides out before it closes, every other dialog closes at once
  function closeDialog(d, after) {
    if (!d.open) return;
    if (!d.classList.contains('drawer') || reducedMotion()) {
      d.close();
      if (after) after();
      return;
    }
    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      d.close();
      if (after) after();
    };
    d.addEventListener('transitionend', finish, { once: true });
    setTimeout(finish, 400);
    d.classList.remove('is-open');
  }

  if (menu && menuBtn) {
    menuBtn.addEventListener('click', openMenu);
    // Escape and the back gesture on Android: from a page back to the links, then the menu slides out
    menu.addEventListener('cancel', (e) => {
      e.preventDefault();
      if (menu.dataset.page) showLinks();
      else closeDialog(menu);
    });
    menu.addEventListener('close', () => {
      menu.classList.remove('is-open');
      menuBtn.setAttribute('aria-expanded', 'false');
      if (pageFromHash()) clearHash();
      // the next time it opens with the links
      delete menu.dataset.page;
      menuPage.hidden = true;
      menuPage.replaceChildren();
      menuBack.hidden = true;
      menuNav.hidden = false;
      menuNav.classList.remove('from-page');
    });
    menuBack.addEventListener('click', showLinks);
    // the other language: keeps the station that is in the player
    $$('[data-lang-link]', menu).forEach((a) => a.addEventListener('click', () => {
      if (!state.cur || a.getAttribute('aria-current') === 'true') return;
      const url = new URL(a.href);
      url.searchParams.set('s', state.cur.id);
      a.href = url.href;
    }));
  }

  $$('[data-dialog]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    if (a.closest('dialog.drawer')) showPage(a.dataset.dialog);
    else openDialog(a.dataset.dialog);
  }));
  $$('dialog').forEach((d) => {
    $$('[data-close]', d).forEach((b) => b.addEventListener('click', () => closeDialog(d)));
    // a click on the dark area around a dialog closes it
    d.addEventListener('click', (e) => {
      if (e.target !== d || d.classList.contains('sheet')) return;
      const r = d.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) closeDialog(d);
    });
    d.addEventListener('close', () => {
      if (pageFromHash() === d.id) clearHash();
    });
  });

  /* ---------- Buttons and keys ---------- */

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    if (act === 'toggle') toggle();
    else if (act === 'prev') step(-1);
    else if (act === 'next') step(1);
    else if (act === 'info') openDialog('info');
    else if (act === 'sheet') openDialog('sheet');
  });

  let spaceTaken = false;
  let tabbed = false;   // focus moved with Tab since the last click or tap
  document.addEventListener('pointerdown', () => { tabbed = false; }, true);
  document.addEventListener('keydown', (e) => { if (e.key === 'Tab') tabbed = true; }, true);

  document.addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    if ($('dialog[open]')) return;
    const t = e.target instanceof Element ? e.target : null;
    const tag = t ? t.tagName.toLowerCase() : '';
    const k = e.key;
    if (k === ' ' || k === 'Spacebar') {
      if ((tag === 'input' && t.type !== 'range') || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
      // Space is play and pause, as in any player, also right after a click on a button
      // (the click leaves the focus there, e.g. on "next"). Only a control reached with
      // Tab keeps its own Space: keyboard users expect it to press the control.
      // (:focus-visible cannot tell: browsers switch it on with this very key press.)
      if ((tag === 'button' || tag === 'a') && tabbed) return;
      e.preventDefault();
      spaceTaken = true;   // the button would otherwise still be pressed on keyup
      if (!e.repeat) toggle();
      return;
    }
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
    if (k === 'ArrowRight' || k === 'ArrowLeft') {
      e.preventDefault();
      step(k === 'ArrowRight' ? 1 : -1);
    } else if (/^[0-9]$/.test(k)) {
      const it = (levels[state.view] || [])[k === '0' ? 9 : Number(k) - 1];
      if (it) {
        e.preventDefault();
        pick(it, state.view);
      }
    } else if (k === 'Escape' && state.view !== 'root') {
      e.preventDefault();
      setView('root');
    }
  });
  document.addEventListener('keyup', (e) => {
    if (spaceTaken && (e.key === ' ' || e.key === 'Spacebar')) {
      e.preventDefault();
      spaceTaken = false;
    }
  });

  /* ---------- Song from the stream ----------
   * Shoutcast and Icecast send the current title inside the stream, which a page cannot
   * read from <audio>. The server reads it (api/nowplaying, cached there), the player
   * asks while it plays. */

  const now = { id: null, track: null, timer: 0 };

  /** The song of the station in the player, only while it plays. */
  function currentTrack() {
    return isPlaying() && state.cur && now.id === state.cur.id ? now.track : null;
  }

  function trackLine(t) {
    return t ? (t.artist ? `${t.artist} · ${t.title}` : t.title) : '';
  }

  function renderTrack() {
    const t = currentTrack();
    $$('[data-r1="track"]').forEach((n) => { n.hidden = !t; n.title = trackLine(t); });
    $$('[data-r1="track-title"]').forEach((n) => { n.textContent = t ? t.title : ''; });
    $$('[data-r1="track-artist"]').forEach((n) => { n.textContent = t ? t.artist : ''; n.hidden = !(t && t.artist); });
    // the docked player has one line: the song instead of "Live"
    $$('.mini [data-r1="state"]').forEach((n) => { n.textContent = t && state.phase === 'live' ? trackLine(t) : TEXT[state.phase]; });
    setMediaMetadata();
    updateIndicators();
  }

  async function pollNow() {
    clearTimeout(now.timer);
    now.timer = 0;
    const it = state.cur;
    if (!it || state.phase !== 'live') return;
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

  function stopNow() {
    clearTimeout(now.timer);
    now.timer = 0;
  }

  /* ---------- Info at "Olli hört gerade" ----------
   * Hovering the bars shows who listens to what. On touch screens a tap on the bars
   * shows it instead of starting the station; the rest of the button still starts it.
   * Screen readers get the same text from the buttons' labels. */

  const ptip = el('div', 'ptip');
  ptip.setAttribute('aria-hidden', 'true');
  ptip.hidden = true;
  document.body.append(ptip);
  let ptipFor = null;
  let ptipTimer = 0;
  let pointer = 'mouse';

  /** Bars of a station, a category or the player; not the switch in the header. */
  function pillAt(target) {
    const p = target instanceof Element ? target.closest('.pill') : null;
    return p && !p.hidden && (p.dataset.item || p.dataset.r1 === 'pill') ? p : null;
  }

  function pillText(pill) {
    const it = pill.dataset.item ? items.get(pill.dataset.item) : state.cur;
    if (!it) return T.listening;
    if (it.type === 'category') {
      const names = stationsOf(it.id).filter((x) => state.active.has(x.id)).map((x) => x.name);
      return names.length ? `${T.listeningTo} ${names.join(` ${T.and} `)}` : T.listening;
    }
    return `${T.listeningTo} ${it.name}`;
  }

  function showTip(pill, autoHide) {
    clearTimeout(ptipTimer);
    ptip.textContent = pillText(pill);
    ptip.hidden = false;
    ptip.classList.remove('below', 'right');
    const r = pill.getBoundingClientRect();
    let left = r.right + 10;
    let top = Math.round(r.top + r.height / 2 - ptip.offsetHeight / 2);
    // the player's bars sit in a line of text: the info goes beside them on the right,
    // so it covers neither the station name above nor the buttons below
    if (pill.dataset.r1 === 'pill' && left + ptip.offsetWidth <= window.innerWidth - 8) {
      ptip.classList.add('right');
    } else {
      const cx = r.left + r.width / 2;
      left = Math.min(Math.max(8, cx - ptip.offsetWidth / 2), window.innerWidth - 8 - ptip.offsetWidth);
      top = r.top - ptip.offsetHeight - 10;
      if (top < 8) {
        top = r.bottom + 10;
        ptip.classList.add('below');
      }
      ptip.style.setProperty('--ax', (cx - left) + 'px');
    }
    ptip.style.left = left + 'px';
    ptip.style.top = top + 'px';
    ptipFor = pill;
    if (autoHide) ptipTimer = setTimeout(hideTip, 2500);
  }

  function hideTip() {
    clearTimeout(ptipTimer);
    ptip.hidden = true;
    ptipFor = null;
  }

  document.addEventListener('pointerover', (e) => {
    if (e.pointerType !== 'mouse') return;
    const p = pillAt(e.target);
    if (p && p !== ptipFor) showTip(p, false);
  });
  document.addEventListener('pointerout', (e) => {
    if (e.pointerType === 'mouse' && ptipFor && !(e.relatedTarget instanceof Node && ptipFor.contains(e.relatedTarget))) hideTip();
  });
  document.addEventListener('pointerdown', (e) => {
    pointer = e.pointerType;
    if (ptipFor && pillAt(e.target) !== ptipFor) hideTip();
  }, true);
  // captured before the buttons see it: a tap on the bars only shows the info
  document.addEventListener('click', (e) => {
    const p = pillAt(e.target);
    if (!p || pointer === 'mouse') return;
    e.preventDefault();
    e.stopPropagation();
    if (ptipFor === p) hideTip();
    else showTip(p, true);
  }, true);
  window.addEventListener('scroll', hideTip, { passive: true });
  scroller.addEventListener('scroll', hideTip, { passive: true });
  listEl.addEventListener('scroll', hideTip, { passive: true });

  /* ---------- Start ---------- */

  // a station page (?s=<id>, the link in posts) puts that station in the player
  const start = data.start ? items.get(data.start) : null;
  const first = (start && start.type === 'station' ? start : null)
    || stationsOf('root')[0] || [...items.values()].find((x) => x.type === 'station');
  if (first) {
    state.cur = first;
    state.curLevel = first.level;
    if (first === start && first.level !== 'root') {
      state.view = first.level;      // the scale shows its category
      state.openCat = first.level;   // the phone list opens it
    }
    renderPlayer();
    if (first === start) $('[data-r1="mini"]').hidden = false;   // on phones: one tap to play
  }
  renderList();
  renderScale();
  setPhase('idle');
  pollStatus();
  // links like 1radioe.net/#impressum open the page, also when only the anchor changes
  const openFromHash = () => {
    const id = pageFromHash();
    if (id) openPage(id);
  };
  window.addEventListener('hashchange', openFromHash);
  openFromHash();
})();
