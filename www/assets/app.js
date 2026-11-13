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

  /** Fills a tile with the logo, or the initials if there is none (or it fails to load). */
  function fillTile(node, it) {
    node.replaceChildren();
    node.classList.remove('folder');
    if (it.type === 'category') {
      node.classList.add('folder');
      (levels[it.id] || []).slice(0, 4).forEach((ch) => {
        const m = el('span');
        if (ch.logo) m.append(image(ch.logo, () => m.replaceChildren()));
        node.append(m);
      });
      return;
    }
    if (it.logo) node.append(image(it.logo, () => { node.textContent = initials(it.name); }));
    else node.textContent = initials(it.name);
  }

  function pillEl() {
    const p = el('span', 'pill');
    p.title = 'Ollie hört gerade';
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
    if (isActive(it)) text += ', Ollie hört gerade';
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
      slot.append(pillEl());
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
    if (jump || left < scroller.scrollLeft || left + SLOT > scroller.scrollLeft + scroller.clientWidth) {
      scroller.scrollTo({ left: Math.max(0, left + SLOT / 2 - scroller.clientWidth / 2), behavior: jump ? 'auto' : 'smooth' });
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
    const pill = pillEl();
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
        inner.hidden = true;
        row.setAttribute('aria-controls', inner.id);
        row.setAttribute('aria-expanded', 'false');
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
      sub.textContent = it.type === 'category' ? `${it.count} Sender` : cur ? TEXT[state.phase] : (it.desc || '');
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
    document.title = isPlaying() && state.cur ? `${state.cur.name} · 1radiœ` : '1radiœ';
    if (media) media.playbackState = isPlaying() ? 'playing' : phase === 'paused' ? 'paused' : 'none';
    updateIndicators();
  }

  /* ---------- Audio ---------- */

  const audio = new Audio();
  audio.preload = 'none';
  let retryTimer = 0;
  let lastTime = 0;
  let lastProgress = 0;

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
    audio.src = state.cur.url;
    const started = audio.play();
    if (started && started.catch) {
      started.catch((err) => {
        if (err && err.name === 'NotAllowedError') setPhase('paused');   // autoplay blocked, needs a tap
        else if (!err || err.name !== 'AbortError') failed(0);            // AbortError: replaced by a newer connect
      });
    }
  }

  /** Pausing live radio means hanging up; playing again starts fresh at the live point. */
  function stop() {
    clearTimeout(retryTimer);
    retryTimer = 0;
    setPhase(state.cur ? 'paused' : 'idle');
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
    media.metadata = new MediaMetadata({
      title: it.name,
      artist: '1radiœ',
      album: cat ? cat.name : '',
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
    site.hidden = !it.website;
    if (it.website) {
      site.href = it.website;
      let host = it.website;
      try { host = new URL(it.website).host.replace(/^www\./, ''); } catch { /* keep the address */ }
      site.textContent = 'Website: ' + host;
    }
    $('[data-info="m3u"]').href = it.m3u;
    return true;
  }

  function openDialog(id) {
    const d = document.getElementById(id);
    if (!d || d.open || typeof d.showModal !== 'function') return;
    if (id === 'info' && !fillInfo()) return;
    d.showModal();
  }

  $$('[data-dialog]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    openDialog(a.dataset.dialog);
  }));
  $$('dialog').forEach((d) => {
    $$('[data-close]', d).forEach((b) => b.addEventListener('click', () => d.close()));
    // a click on the dark area around a dialog closes it
    d.addEventListener('click', (e) => {
      if (e.target !== d || d.classList.contains('sheet')) return;
      const r = d.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close();
    });
    d.addEventListener('close', () => {
      if (location.hash === '#' + d.id) history.replaceState(null, '', location.pathname + location.search);
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

  document.addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    if ($('dialog[open]')) return;
    const t = e.target;
    const tag = t && t.tagName ? t.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
    const k = e.key;
    if (k === ' ' || k === 'Spacebar') {
      if (tag === 'button' || tag === 'a') return;   // the focused control does its own thing
      e.preventDefault();
      toggle();
    } else if (k === 'ArrowRight' || k === 'ArrowLeft') {
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

  /* ---------- Start ---------- */

  const first = stationsOf('root')[0] || [...items.values()].find((x) => x.type === 'station');
  if (first) {
    state.cur = first;
    state.curLevel = first.level;
    renderPlayer();
  }
  renderList();
  renderScale();
  setPhase('idle');
  pollStatus();
  // links like 1radioe.net/#impressum open the dialog, also when only the anchor changes
  const openFromHash = () => {
    const id = location.hash.slice(1);
    if (['about', 'impressum', 'datenschutz'].includes(id)) openDialog(id);
  };
  window.addEventListener('hashchange', openFromHash);
  openFromHash();
})();
