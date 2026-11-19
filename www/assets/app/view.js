// 1radiœ web app: what shows the current station. Marks on scale and list, the
// stage, the docked and the full player, the running text and the announcement for
// screen readers.

import { $, $$, PAGE_TITLE, SLOT, T, countText, data, el, fillTile, isActive, isOwn, isPlaying, isSc, isYt, items, label, phaseText, state } from './core.js';
import { needleIndex, scale } from './scale.js';
import { rows } from './list.js';
import { ytPicture } from './youtube.js';
import { media, setMediaMetadata } from './media.js';
import { listenStart, listenStop } from './status.js';
import { currentDesc, currentTrack, now, pollNow, renderTrack, stopNow, trackLine } from './song.js';

/* ---------- Indicators: current station, needle, Ollie's status ---------- */

export function updateIndicators() {
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
    // a category shows its description, the number of stations only without one
    setText(sub, it.type === 'category' ? (it.desc || countText(it.count, it))
      : cur ? (trackLine(currentTrack()) || (state.phase === 'live' && currentDesc()) || phaseText(state.phase))
      : (it.desc || ''));
    // an open category shows the status at the station inside, not twice
    pill.hidden = !isActive(it) || (it.type === 'category' && state.openCat === it.id);
  }
  $$('[data-r1="pill"]').forEach((p) => { p.hidden = !(state.cur && state.active.has(state.cur.id)); });
}

/* ---------- Player views (stage, docked player, full player) ---------- */

export function renderPlayer() {
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
  $$('[data-r1="level"]').forEach((n) => { n.textContent = cat ? cat.name : T.all; });
  // SoundCloud's player has its own buttons: the stage and the full player hide theirs
  document.documentElement.classList.toggle('sc-cur', isSc(it));
  // YouTube: the video (or its picture) takes the place of the logo
  document.documentElement.classList.toggle('yt-cur', isYt(it));
  if (isYt(it)) ytPicture(it);
  placeInfoTip();   // the button moves with the length of the name
  setMediaMetadata();
}

/** The tooltip of "About the station" goes right of the button, below it if the window is too narrow. */
function placeInfoTip() {
  const tip = $('#r1-info-tip');
  if (!tip || !tip.offsetParent) return;   // the stage is hidden on phones
  const button = tip.parentElement.getBoundingClientRect();
  tip.classList.toggle('below', button.right + 8 + tip.offsetWidth > document.documentElement.clientWidth - 8);
}

export function setPhase(phase) {
  state.phase = phase;
  const radio = !isOwn(state.cur);
  // the docked player's line is set by renderTrack below (song, description or state)
  $$('[data-r1="state"]').forEach((n) => { if (!n.closest('.mini')) setText(n, phaseText(phase)); });
  $$('[data-r1="dot"]').forEach((n) => { n.className = 'dot ' + phase; });
  $$('[data-act="toggle"]').forEach((b) => {
    b.classList.toggle('is-playing', isPlaying());
    b.setAttribute('aria-label', isPlaying() ? T.pause : T.play);
  });
  if (phase !== 'idle') $('[data-r1="mini"]').hidden = false;
  if (phase === 'live' && radio) listenStart();
  else if (phase !== 'connecting') listenStop(false);
  if (phase === 'live' && radio && (now.id !== state.cur.id || !now.timer)) pollNow();
  else if (!radio || (phase !== 'live' && phase !== 'connecting')) stopNow();
  document.title = isPlaying() && state.cur ? `${state.cur.name} · 1radiœ`
    : state.cur && data.start && state.cur.id !== data.start ? (T.title || PAGE_TITLE) : PAGE_TITLE;
  if (media) media.playbackState = isPlaying() ? 'playing' : phase === 'paused' ? 'paused' : 'none';
  renderTrack();
  announce();
  // a station picked on a phone: its line in the list and in the docked player run once
  if (phase === 'live' && state.cur && state.mqPick === state.cur.id) {
    state.mqPick = null;
    const it = state.cur;
    setTimeout(() => {
      if (state.cur !== it) return;
      const r = rows.get(it.id);
      marquee(r && r.sub);
      marquee($('.mini [data-r1="state"]'));
    }, 1500);
  }
}

/* ---------- Running text ----------
 * A line cut off with "…" runs to its end on request, rests and jumps back: on hover
 * or keyboard focus (as long as it lasts), once for a station picked on a phone, once
 * for the song lines when the full player opens. Nothing moves on its own otherwise
 * (WCAG 2.2.2), nothing at all with "reduce motion". */

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
export const canHover = window.matchMedia('(hover: hover)');
const MQ_SPEED = 40;     // px per second
const MQ_REST = 1200;    // ms still at the start and at the end

export function setText(node, text) {
  if (node.textContent !== text) node.textContent = text;
}

/** Lets a cut-off line run once; again as long as `again()` says so. */
export function marquee(node, again) {
  if (!node || reduceMotion.matches || node.dataset.mq || typeof node.animate !== 'function') return;
  const overflow = node.scrollWidth - node.clientWidth;
  if (overflow < 4 || node.closest('[hidden]')) return;
  const text = node.textContent;
  const inner = el('span', 'mq', text);
  node.dataset.mq = '1';
  node.classList.add('mq-on');
  node.replaceChildren(inner);
  const move = (overflow / MQ_SPEED) * 1000;
  const total = move + 2 * MQ_REST;
  const run = inner.animate([
    { transform: 'translateX(0)', offset: 0 },
    { transform: 'translateX(0)', offset: MQ_REST / total },
    { transform: `translateX(${-overflow}px)`, offset: (MQ_REST + move) / total },
    { transform: `translateX(${-overflow}px)`, offset: 1 },
  ], { duration: total, easing: 'linear' });
  const end = () => {
    if (inner.parentNode === node) node.textContent = text;   // unless the line got a new text meanwhile
    node.classList.remove('mq-on');
    delete node.dataset.mq;
    node.mqRun = null;
  };
  node.mqRun = run;
  run.onfinish = () => {
    end();
    if (again && again()) setTimeout(() => { if (again()) marquee(node, again); }, 400);
  };
  run.oncancel = end;
}

export function stopMarquee(node) {
  if (node && node.mqRun) node.mqRun.cancel();
}

/* Screen readers hear every change in one polite announcement: the station and its
   state, only the state while the station stays the same. Nothing at page load. */
const announcer = $('[data-r1="announce"]');
const said = { id: null, phase: '' };
function announce() {
  if (!announcer || !state.cur || !state.touched) return;
  const phase = phaseText(state.phase);
  if (said.id === state.cur.id && said.phase === phase) return;   // e.g. a second connection attempt
  announcer.textContent = said.id === state.cur.id ? phase : `${state.cur.name}, ${phase}`;
  said.id = state.cur.id;
  said.phase = phase;
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  window.addEventListener('resize', placeInfoTip, { passive: true });
}
