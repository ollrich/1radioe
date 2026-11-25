// 1radiœ admin: the system page, the request log and the listening devices.

import { $, $$, api, confirmButton, el, toast } from './core.js';

/* ---------- System ---------- */

function fillKv(target, entries, copy) {
  const dl = $(target);
  dl.replaceChildren();
  for (const [k, v] of Object.entries(entries)) {
    const dd = el('dd');
    dd.append(el('span', 'value', String(v)));
    if (copy) {
      const b = el('button', 'btn ghost small', 'Kopieren');
      b.type = 'button';
      b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(String(v)); toast('Kopiert'); } catch { toast('Kopieren nicht möglich', 'error'); }
      });
      dd.append(b);
    }
    dl.append(el('dt', '', k), dd);
  }
}

export async function loadSystem() {
  try {
    const data = await api('system');
    fillKv('#portal', data.portal, true);
    fillKv('#public', data.public, true);
    fillKv('#status', data.status, false);
    $('#log-enabled').checked = data.log_enabled;
    fillEndpoints(data.endpoints || []);
    loadLog();
    loadListen();
    loadDevices();
  } catch (e) {
    toast(e.message, 'error');
  }
}

/* Listening status, for testing: refreshes itself while the system page is visible. */
let listenTimer;
function ago(sec) {
  if (sec < 60) return `vor ${sec} s`;
  if (sec < 3600) return `vor ${Math.floor(sec / 60)} min`;
  if (sec < 86400) return `vor ${Math.floor(sec / 3600)} h`;
  const days = Math.floor(sec / 86400);
  return days === 1 ? 'vor 1 Tag' : `vor ${days} Tagen`;
}

/* Addresses Sonos called (System, Sonos-Adressen). */
function fillEndpoints(rows) {
  const body = $('#endpoints');
  body.replaceChildren();
  if (!rows.length) {
    const tr = el('tr');
    const td = el('td', 'muted', 'Noch keine Anfrage von Sonos seit Version 1.10.2.');
    td.colSpan = 4;
    tr.append(td);
    body.append(tr);
  }
  for (const r of rows) {
    const tr = el('tr');
    const addr = el('td', '', r.endpoint);
    if (r.current) addr.append(el('small', 'muted', ' (aktuelle Adresse)'));
    tr.append(addr, el('td', '', `${r.last} (${ago(r.age)})`), el('td', '', String(r.hits)), el('td', '', r.since));
    body.append(tr);
  }
}

export async function loadListen() {
  clearTimeout(listenTimer);
  if (location.hash !== '#system') return;
  let rows;
  try {
    ({ rows } = await api('listen'));
  } catch {
    rows = null;
  }
  const body = $('#listen');
  if (rows) {
    body.replaceChildren();
    if (!rows.length) {
      const tr = el('tr');
      const td = el('td', 'muted', 'Noch keine Meldung.');
      td.colSpan = 3;
      tr.append(td);
      body.append(tr);
    }
    for (const r of rows) {
      const tr = el('tr', r.state === 'läuft' ? 'is-live' : '');
      tr.append(el('td', '', r.title), el('td', 'listen-state', r.state === 'läuft' ? '● läuft' + (r.since ? ` seit ${r.since}` : '') : r.state), el('td', '', `${r.at} (${ago(r.age)})`));
      body.append(tr);
    }
  }
  listenTimer = setTimeout(loadListen, document.hidden ? 30000 : 10000);
}

export async function loadLog() {
  let log;
  try {
    ({ log } = await api('log'));
  } catch (e) {
    toast(e.message, 'error');
    return;
  }
  const body = $('#log');
  body.replaceChildren();
  if (!log.length) {
    const tr = el('tr');
    const td = el('td', 'muted', 'Keine Einträge.');
    td.colSpan = 7;
    tr.append(td);
    body.append(tr);
  }
  for (const r of log) {
    const tr = el('tr', Number(r.status) >= 400 ? 'is-error' : '');
    // entries without an address come from a version before 1.10.2, e.g. the old installation
    [r.at, r.method, r.item, r.status, r.note, r.endpoint || 'alte Version', `${r.ip} ${r.agent}`].forEach((v) => tr.append(el('td', '', String(v))));
    body.append(tr);
  }
}

// After login the admin offers to remember this browser for the listening status,
// unless it already is or the offer was dismissed in this session.
export async function loadDevices() {
  let data;
  try {
    data = await api('listen.devices');
  } catch {
    return;
  }
  $('#listen-keyed input').checked = Boolean(data.keyed_only);
  let dismissed = false;
  try { dismissed = sessionStorage.getItem('r1.listen.banner') === 'no'; } catch { /* not remembered */ }
  $('#listen-banner').hidden = Boolean(data.this) || dismissed;
  $('#listen-remember').hidden = Boolean(data.this);
  const here = $('#listen-this');
  here.replaceChildren();
  if (data.this) {
    here.append(`Dieser Browser ist gemerkt als „${data.this.name}“. `);
    const b = el('button', 'btn ghost small', 'Hier nicht mehr merken');
    b.type = 'button';
    b.addEventListener('click', async () => {
      if (!confirmButton(b, 'Hier nicht mehr merken')) return;
      try {
        await api('listen.forget', {});
        toast('Dieser Browser meldet nicht mehr');
        loadDevices();
      } catch (e) {
        toast(e.message, 'error');
      }
    });
    here.append(b);
  } else {
    here.textContent = 'Dieser Browser ist nicht gemerkt.';
  }
  const body = $('#listen-devices');
  body.replaceChildren();
  if (!data.devices.length) {
    const tr = el('tr');
    const td = el('td', 'muted', 'Noch kein Gerät.');
    td.colSpan = 6;
    tr.append(td);
    body.append(tr);
  }
  for (const d of data.devices) {
    const tr = el('tr', d.revoked ? 'is-revoked' : '');
    const status = d.revoked ? `widerrufen am ${d.revoked}` : d.this ? 'aktiv, dieser Browser' : 'aktiv';
    tr.append(el('td', '', d.name), el('td', '', d.kind !== 'app' ? 'Browser' : d.profile === 'cathy' ? 'Schlüssel Cathy, nur Playlists' : 'Schlüssel'), el('td', '', d.created),
      el('td', '', d.last_used || 'noch nie'), el('td', '', status));
    const cell = el('td');
    if (!d.revoked) {
      const b = el('button', 'btn ghost small danger-text', 'Widerrufen');
      b.type = 'button';
      b.addEventListener('click', async () => {
        if (!confirmButton(b, 'Widerrufen')) return;
        try {
          await api('listen.revoke', { id: d.id });
          toast(`„${d.name}“ widerrufen`);
          loadDevices();
        } catch (e) {
          toast(e.message, 'error');
        }
      });
      cell.append(b);
    }
    tr.append(cell);
    body.append(tr);
  }
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  /* ---------- Listening devices ---------- */

  // only reports with a key (Home Assistant, remembered browsers, the app), no anonymous Sonos reports
  $('#listen-keyed input').addEventListener('change', async (e) => {
    const box = e.currentTarget;
    try {
      const res = await api('listen.keyed', { on: box.checked });
      box.checked = res.keyed_only;
      toast(res.keyed_only ? 'Nur noch Meldungen mit Schlüssel' : 'Sonos-Meldungen zählen wieder');
    } catch (err) {
      box.checked = !box.checked;
      toast(err.message, 'error');
    }
  });

  $$('form[data-remember]').forEach((f) => f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = f.elements.namedItem('name').value.trim();
    try {
      const { device } = await api('listen.remember', { name });
      f.reset();
      toast(`Gemerkt als „${device.name}“. Hören auf 1radioe.net wird jetzt erfasst.`);
      loadDevices();
    } catch (err) {
      toast((err.fields && err.fields.name) || err.message, 'error');
    }
  }));
  // a key for the app or Home Assistant: shown once, alone and as the line for secrets.yaml
  $('#listen-key').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.currentTarget;
    const name = f.elements.namedItem('name').value.trim();
    const profile = f.elements.namedItem('profile').value;
    try {
      const { device, key } = await api('listen.key', { name, profile });
      $('#listen-key-name').textContent = device.name;
      $('#listen-key-plain').textContent = key;
      $('#listen-key-line').textContent = `radioe_listen_token: ${key}`;
      // Cathy's key only reads playlists in the app: no line for Home Assistant
      $('#listen-key-ha').hidden = device.profile !== 'olli';
      $('#listen-key-box').hidden = false;
      toast(`Schlüssel für „${device.name}“ erzeugt`);
      loadDevices();
    } catch (err) {
      toast((err.fields && err.fields.name) || err.message, 'error');
    }
  });
  $$('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText($(b.dataset.copy).textContent); toast('Kopiert'); } catch { toast('Kopieren nicht möglich', 'error'); }
  }));
  $('#listen-banner-dismiss').addEventListener('click', () => {
    $('#listen-banner').hidden = true;
    try { sessionStorage.setItem('r1.listen.banner', 'no'); } catch { /* not remembered */ }
  });
}
