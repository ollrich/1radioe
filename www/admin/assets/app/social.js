// 1radiœ admin: posting to Bluesky and Mastodon.

import { $, $$, api, clearErrors, confirmButton, el, showErrors, toast } from './core.js';

/* ---------- Posting to Bluesky and Mastodon ---------- */

const socialForm = $('#social-form');
const SF = (name) => socialForm.elements.namedItem(name);
const social = { settings: null, stations: [], timer: null };
const OUTCOME = { posted: 'gesendet', partial: 'teilweise gesendet', failed: 'fehlgeschlagen', skipped: 'übersprungen' };

export async function loadSocial() {
  try {
    const data = await api('social');
    social.stations = data.stations;
    fillSocialStations();
    fillSocial(data.settings);
    renderSocialLog(data.log);
    previewSocial();
  } catch (e) {
    toast(e.message, 'error');
  }
}

function fillSocialStations() {
  const sel = $('#social-station');
  const keep = sel.value;
  sel.replaceChildren();
  if (!social.stations.length) sel.append(new Option('Beispielsender', '0'));
  for (const st of social.stations) {
    const notes = [!st.social && 'vom Posten ausgenommen', !st.active && 'inaktiv'].filter(Boolean);
    sel.append(new Option(st.title + (notes.length ? ` (${notes.join(', ')})` : ''), String(st.id)));
  }
  if (keep && [...sel.options].some((o) => o.value === keep)) sel.value = keep;
}

function setCheckState(net, ok, text) {
  const p = $('#' + net + '-state');
  p.hidden = !text;
  p.className = 'probe' + (ok === true ? ' ok' : ok === false ? ' fail' : '');
  p.textContent = text || '';
}

function fillSocial(s) {
  social.settings = s;
  clearErrors(socialForm);
  SF('enabled').checked = s.enabled;
  for (const net of ['bsky', 'masto']) {
    SF(net + '.template').value = s[net].template;
    SF(net + '.lang').value = s[net].lang;
  }
  SF('hashtags').value = s.hashtags;
  SF('site_url').value = s.site_url || '';
  SF('site_url').placeholder = s.site_url_default;
  $('#site-hint').textContent = `Links zeigen auf ${s.site_url || s.site_url_default}/s/…`;
  ['delay', 'cooldown', 'daily'].forEach((k) => { SF(k).value = s[k]; });
  SF('card').checked = s.card;
  SF('bsky.handle').value = s.bsky.handle;
  SF('bsky.pds').value = s.bsky.pds;
  SF('masto.instance').value = s.masto.instance;
  SF('masto.visibility').value = s.masto.visibility;
  for (const net of ['bsky', 'masto']) {
    const n = s[net];
    const secret = SF(net === 'bsky' ? 'bsky.password' : 'masto.token');
    secret.value = '';
    secret.placeholder = n.secret_set ? 'gespeichert, leer lassen zum Behalten' : (net === 'bsky' ? 'xxxx-xxxx-xxxx-xxxx' : '');
    SF(net + '.enabled').checked = n.enabled;
    SF(net + '.clear').checked = false;
    $(`[data-secret="${net}"]`).hidden = !n.secret_set;
    setCheckState(net, n.checked ? true : null, n.checked || (n.secret_set ? 'Noch nicht geprüft.' : ''));
  }
  $('#social-key').hidden = s.key_ok;
  $('#social-key-line').textContent = s.key_ok ? '' : `'secret_key' => '${s.key_example}',`;
  $('#social-sodium').hidden = s.sodium;
  summarizeSocial();
}

function summarizeSocial() {
  const s = social.settings;
  const nets = [s.bsky.ready && 'Bluesky', s.masto.ready && 'Mastodon'].filter(Boolean);
  let text;
  if (!nets.length) text = 'Noch kein Netzwerk eingerichtet und eingeschaltet.';
  else if (!s.enabled) text = `Eingerichtet für ${nets.join(' und ')}, automatisches Posten ist aus.`;
  else text = `Postet an ${nets.join(' und ')}, wenn ein Sender ${s.delay} ${s.delay === 1 ? 'Minute' : 'Minuten'} läuft. Höchstens ${s.daily} pro Tag, derselbe Sender frühestens nach ${s.cooldown} ${s.cooldown === 1 ? 'Stunde' : 'Stunden'} wieder.`;
  if (!s.background) text += ' Dieser Server kann nicht erst antworten und dann posten, ein Beitrag hält die Antwort an Sonos kurz auf.';
  $('#social-summary').textContent = text;
}

function collectSocial() {
  return {
    enabled: SF('enabled').checked,
    hashtags: SF('hashtags').value,
    site_url: SF('site_url').value.trim(),
    delay: SF('delay').value,
    cooldown: SF('cooldown').value,
    daily: SF('daily').value,
    card: SF('card').checked,
    bsky: {
      enabled: SF('bsky.enabled').checked,
      template: SF('bsky.template').value,
      lang: SF('bsky.lang').value.trim(),
      handle: SF('bsky.handle').value,
      password: SF('bsky.password').value,
      pds: SF('bsky.pds').value,
      clear: SF('bsky.clear').checked,
    },
    masto: {
      enabled: SF('masto.enabled').checked,
      template: SF('masto.template').value,
      lang: SF('masto.lang').value.trim(),
      instance: SF('masto.instance').value,
      token: SF('masto.token').value,
      visibility: SF('masto.visibility').value,
      clear: SF('masto.clear').checked,
    },
  };
}

async function saveSocial(quiet = false) {
  clearErrors(socialForm);
  const btn = $('#social-save');
  btn.disabled = true;
  try {
    const { settings } = await api('social.save', collectSocial());
    fillSocial(settings);
    if (!quiet) toast('Gespeichert');
    return true;
  } catch (err) {
    showErrors(socialForm, err);
    toast(err.message, 'error');
    return false;
  } finally {
    btn.disabled = false;
  }
}

function schedulePreview() {
  clearTimeout(social.timer);
  social.timer = setTimeout(previewSocial, 300);
}

async function previewSocial() {
  let preview;
  try {
    ({ preview } = await api('social.preview', {
      bsky: { template: SF('bsky.template').value },
      masto: { template: SF('masto.template').value },
      hashtags: SF('hashtags').value,
      site_url: SF('site_url').value.trim(),
      station: Number($('#social-station').value) || 0,
    }));
  } catch {
    return;   // the preview is a help, not a must
  }
  for (const net of ['bsky', 'masto']) {
    const p = preview[net];
    $('#pv-' + net).textContent = p.text;
    const count = $('#pv-' + net + '-count');
    count.textContent = `${p.length} von ${p.max}` + (p.dropped ? `, ${p.dropped} ${p.dropped === 1 ? 'Hashtag' : 'Hashtags'} weggelassen` : '');
    count.classList.toggle('over', p.too_long);
  }
}

async function checkSocial(net, btn) {
  if (!(await saveSocial(true))) return;
  btn.disabled = true;
  setCheckState(net, null, 'Prüfe …');
  try {
    const r = await api('social.check', { network: net });
    if (r.settings) fillSocial(r.settings);
    setCheckState(net, r.ok, r.info);
    previewSocial();
  } catch (e) {
    setCheckState(net, false, e.message);
  } finally {
    btn.disabled = false;
  }
}

async function testSocial(btn) {
  if (!confirmButton(btn, 'Testbeitrag senden')) return;
  if (!(await saveSocial(true))) return;
  btn.disabled = true;
  try {
    const { entry, log } = await api('social.test', { station: Number($('#social-station').value) || 0 });
    renderSocialLog(log);
    if (entry.outcome === 'posted') toast('Testbeitrag gesendet');
    else toast(entry.outcome === 'partial' ? 'Nur teilweise gesendet, Details im Protokoll' : 'Nicht gesendet, Details im Protokoll', 'error');
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

function netCell(n) {
  const td = el('td');
  if (n.url && /^https?:\/\//.test(n.url)) {
    const a = el('a', '', 'ansehen');
    a.href = n.url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    td.append(a);
  } else if (n.error) {
    td.append(el('span', 'text-danger', n.error));
  }
  return td;
}

function renderSocialLog(log) {
  const body = $('#social-log');
  body.replaceChildren();
  if (!log.length) {
    const tr = el('tr');
    const td = el('td', 'muted', 'Noch keine Beiträge.');
    td.colSpan = 5;
    tr.append(td);
    body.append(tr);
  }
  for (const r of log) {
    const tr = el('tr', r.outcome === 'failed' ? 'is-error' : r.outcome === 'skipped' ? 'is-skipped' : '');
    const result = el('td', '', OUTCOME[r.outcome] || r.outcome);
    if (r.note) result.append(el('small', 'muted block', r.note));
    if (r.text) tr.title = r.text;
    tr.append(el('td', '', r.at), el('td', '', r.title), result, netCell(r.bsky), netCell(r.masto));
    body.append(tr);
  }
}

// the placeholder chips insert into the text that was edited last
let lastTemplate = SF('bsky.template');

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  socialForm.addEventListener('submit', (e) => {
    e.preventDefault();
    saveSocial();
  });
  ['bsky.template', 'masto.template', 'hashtags', 'site_url'].forEach((n) => SF(n).addEventListener('input', schedulePreview));
  $('#social-station').addEventListener('change', previewSocial);

  $$('[data-template]').forEach((ta) => ta.addEventListener('focus', () => { lastTemplate = ta; }));
  $$('[data-insert]').forEach((b) => b.addEventListener('click', () => {
    const ta = lastTemplate;
    ta.setRangeText(b.dataset.insert, ta.selectionStart, ta.selectionEnd, 'end');
    ta.focus();
    schedulePreview();
  }));
  $$('[data-check]').forEach((b) => b.addEventListener('click', () => checkSocial(b.dataset.check, b)));
  $('#social-test').addEventListener('click', (e) => testSocial(e.currentTarget));
  $('#social-log-refresh').addEventListener('click', async () => {
    try {
      const { log } = await api('social.log');
      renderSocialLog(log);
    } catch (e) {
      toast(e.message, 'error');
    }
  });
  $('#social-key-copy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText($('#social-key-line').textContent); toast('Kopiert'); } catch { toast('Kopieren nicht möglich', 'error'); }
  });
}
