// 1radiœ admin: the editor for the texts of the public dialogs (Über, Impressum,
// Datenverarbeitung).

import { $, $$, api, confirmButton, toast } from './core.js';

/* ---------- Texts of the public dialogs ----------
 * A small editor on contenteditable, no library: paragraphs, subheadings, bold,
 * italic, lists and links. Pasted text comes in plain. The server cleans what it
 * gets (app/texts.php) and sends back what it stored, which the editor then shows. */

const texts = { data: null, name: 'about', lang: 'de', dirty: false };
const editor = $('#text-editor');

export async function loadTexts() {
  try {
    ({ texts: texts.data } = await api('texts'));
  } catch (e) {
    toast(e.message, 'error');
    return;
  }
  showText();
}

function showText() {
  const t = texts.data[texts.name];
  const stored = t[texts.lang];
  // without an own text: German shows its default; English what the English page
  // shows then, the own German text or else the English default
  editor.innerHTML = stored ?? (texts.lang === 'de' ? t.default.de : (t.de ?? t.default.en));
  texts.dirty = false;
  let note;
  if (texts.lang === 'de') {
    note = stored ? 'Eigener Text.' : 'Standardtext. Mit „Speichern“ wird er zu deinem eigenen.';
  } else {
    note = stored ? 'Eigener englischer Text.' : t.de ? 'Noch kein englischer Text. Die englische Seite zeigt deinen deutschen, er steht hier zum Übersetzen.' : 'Englischer Standardtext. Mit „Speichern“ wird er zu deinem eigenen.';
  }
  $('#text-state').textContent = note;
  $('#text-reset').disabled = !stored;
  $('#text-view').href = (texts.lang === 'de' ? '../de/' : '../?lang=en') + '#' + texts.name;
  $$('[data-text]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.text === texts.name)));
  $$('[data-text-lang]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.textLang === texts.lang)));
}

function leaveText() {
  return !texts.dirty || window.confirm('Die Änderungen sind noch nicht gespeichert. Verwerfen?');
}

async function saveText(html) {
  const { name, lang } = texts;
  try {
    const res = await api('texts.save', { name, lang, html });
    texts.data[name][lang] = res.html;
    showText();
    toast(res.html === null ? 'Zurückgesetzt' : 'Gespeichert');
  } catch (e) {
    toast((e.fields && e.fields.text) || e.message, 'error');
  }
}

/** Listeners and first state of this part, called once by the entry. */
export function init() {
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch { /* old browser */ }

  $$('[data-text]').forEach((b) => b.addEventListener('click', () => {
    if (b.dataset.text === texts.name || !texts.data || !leaveText()) return;
    texts.name = b.dataset.text;
    showText();
  }));
  $$('[data-text-lang]').forEach((b) => b.addEventListener('click', () => {
    if (b.dataset.textLang === texts.lang || !texts.data || !leaveText()) return;
    texts.lang = b.dataset.textLang;
    showText();
  }));
  editor.addEventListener('input', () => { texts.dirty = true; });
  editor.addEventListener('paste', (e) => {
    e.preventDefault();
    document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
  });
  $$('.rte-bar [data-cmd]').forEach((b) => {
    b.addEventListener('mousedown', (e) => e.preventDefault());   // keep the selection in the editor
    b.addEventListener('click', () => {
      editor.focus();
      const cmd = b.dataset.cmd;
      if (cmd === 'p' || cmd === 'h3') document.execCommand('formatBlock', false, `<${cmd}>`);
      else if (cmd === 'link') {
        const url = window.prompt('Adresse des Links: https://…, mailto:… oder eine Seite hier, etwa streams.m3u', 'https://');
        if (url && url.trim() && url.trim() !== 'https://') document.execCommand('createLink', false, url.trim());
      } else document.execCommand(cmd, false, null);
      texts.dirty = true;
    });
  });
  $('#text-save').addEventListener('click', () => saveText(editor.innerHTML));
  $('#text-reset').addEventListener('click', (e) => {
    if (confirmButton(e.currentTarget, 'Auf Standard zurücksetzen')) saveText('');
  });
  window.addEventListener('beforeunload', (e) => {
    if (texts.dirty && !$('#view-texts').hidden) e.preventDefault();
  });
}
