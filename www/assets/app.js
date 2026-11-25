// 1radiœ web app: player, scale with needle (wide screens), list with docked player
// (phones), listening status. Station data comes from the page (#r1-data).
// No framework and no requests to anyone but this server and the streams.
//
// The entry: the parts are ES modules in app/, loaded without a build step. index.php
// loads this file as assets/v<version>/app.js (www/.htaccess maps that to assets/), so
// the modules carry the version in their path and a new version never meets old parts
// in the browser's cache. The server keeps only the current files, under any version:
// a page that loaded just before a deploy and fetches its parts after it would get new
// ones. The page itself is never cached (no-cache), so that needs a deploy within the
// moment of loading; accepted (issue #95), a build step for it is not worth it.
// Each part only declares at load; its listeners and first state come from its init(),
// called here in the order the old single file ran them, the menu's first.

import { $, data, isSc, isYt, items, levels, state, stationsOf } from './app/core.js';
import { renderScale, init as initScale } from './app/scale.js';
import { renderList } from './app/list.js';
import { renderPlayer, setPhase, init as initView } from './app/view.js';
import { init as initAudio } from './app/audio.js';
import { scShow, init as initSoundcloud } from './app/soundcloud.js';
import { ytShow, init as initYoutube } from './app/youtube.js';
import { init as initMedia } from './app/media.js';
import { pollStatus, init as initStatus } from './app/status.js';
import { openPage, pageFromHash, init as initDialogs } from './app/dialogs.js';
import { init as initKeys } from './app/keys.js';
import { init as initSong } from './app/song.js';
import { init as initTip } from './app/tip.js';

// the menu with its pages (Impressum, Datenverarbeitung, Über) and the language work
// always, also without station data (the database is away) or without stations
initDialogs();
// links like 1radioe.net/#impressum open the page, also when only the anchor changes
const openFromHash = () => {
  const id = pageFromHash();
  if (id) openPage(id);
};
window.addEventListener('hashchange', openFromHash);

// the player only with stations: without them the page has no scale, list or player
if (data && (levels.root || []).length) {
  initScale();
  initView();
  initAudio();
  initSoundcloud();
  initYoutube();
  initMedia();
  initStatus();
  initKeys();
  initSong();
  initTip();

  /* ---------- Start ---------- */

  // a station page (?s=<id>, the link in posts) puts that station in the player
  const start = data.start ? items.get(data.start) : null;
  const first = (start && (start.type === 'station' || start.type === 'soundcloud' || start.type === 'youtube') ? start : null)
    || stationsOf('root').find((x) => x.type === 'station') || [...items.values()].find((x) => x.type === 'station');
  if (first) {
    state.cur = first;
    state.curLevel = first.level;
    if (first === start && first.level !== 'root') {
      state.view = first.level;      // the scale shows its category
      state.openCat = first.level;   // the phone list opens it
    }
    renderPlayer();
    if (first === start) $('[data-r1="mini"]').hidden = false;   // on phones: one tap to play
    if (isSc(first)) scShow(first, false);
    if (isYt(first)) ytShow(first, false);
  }
  renderList();
  renderScale();
  setPhase('idle');
  pollStatus();
}
openFromHash();
