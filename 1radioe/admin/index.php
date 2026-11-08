<?php
/**
 * 1radiœ admin: setup wizard, login and the station manager (app shell).
 * The station manager itself runs in assets/app.js and talks to api.php.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/admin.php';

$problem = admin_boot();

if ($problem === 'config') {
    render_card('Konfiguration fehlt', '<h1>Konfiguration fehlt</h1>'
        . '<p>Lege die Datei <code>config.php</code> in einen Ordner <code>' . CONFIG_DIR . '</code> oberhalb dieser App, am besten außerhalb des Web-Verzeichnisses, zum Beispiel:</p>'
        . '<pre>' . h(dirname(realpath(APP_DIR) ?: APP_DIR, 2)) . '/' . CONFIG_DIR . "/config.php</pre>"
        . '<p>Eine Vorlage liegt im Ordner <code>' . CONFIG_DIR . '</code> des Repositorys als <code>config.sample.php</code>.</p>');
}
if ($problem === 'db') {
    render_card('Datenbank', '<h1>Datenbank nicht erreichbar</h1>'
        . '<p>Bitte die Zugangsdaten unter <code>db</code> in der <code>config.php</code> prüfen und sicherstellen, dass die Datenbank angelegt ist.</p>');
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf'] ?? null)) {
    render_card('Sitzung abgelaufen', '<h1>Sitzung abgelaufen</h1><p>Bitte die Seite neu laden und es erneut versuchen.</p><p><a class="btn" href="./">Neu laden</a></p>');
}

/* ---------- Setup wizard: creates password hash and TOTP secret for config.php ---------- */

if (setup_needed()) {
    $setup = $_SESSION['setup'] ?? ['step' => 'password'];
    $error = '';
    $user  = (string) cfg('admin.user', '');

    if ($action === 'setup_reset') {
        $setup = ['step' => 'password'];
    } elseif ($action === 'setup_password') {
        $pw = (string) ($_POST['password'] ?? '');
        if (mb_strlen($pw) < 12) {
            $error = 'Das Passwort braucht mindestens 12 Zeichen.';
        } elseif ($pw !== ($_POST['password2'] ?? '')) {
            $error = 'Die beiden Passwörter stimmen nicht überein.';
        } else {
            $setup = ['step' => 'totp', 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'secret' => totp_new_secret()];
        }
    } elseif ($action === 'setup_verify' && ($setup['step'] ?? '') === 'totp') {
        if (totp_verify($setup['secret'], (string) ($_POST['code'] ?? '')) !== null) {
            $setup['step'] = 'done';
        } else {
            $error = 'Der Code passt nicht. Uhrzeit des Handys prüfen und den aktuellen Code eingeben.';
        }
    }
    $_SESSION['setup'] = $setup;

    $csrf = '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    $err  = $error !== '' ? '<p class="alert">' . h($error) . '</p>' : '';
    $html = '<h1>Einrichtung</h1>';
    if ($user === '') {
        $html .= '<p class="alert">In der <code>config.php</code> fehlt noch der Benutzername unter <code>admin.user</code>.</p>';
    }

    if ($setup['step'] === 'password') {
        $html .= '<p class="steps"><b>1 Passwort</b> · 2 Authenticator · 3 Übernehmen</p>' . $err
            . '<form method="post" class="form">' . $csrf . '<input type="hidden" name="action" value="setup_password">'
            . '<label>Neues Passwort <small>mindestens 12 Zeichen</small><input type="password" name="password" autocomplete="new-password" required minlength="12" autofocus></label>'
            . '<label>Passwort wiederholen<input type="password" name="password2" autocomplete="new-password" required minlength="12"></label>'
            . '<button class="btn primary">Weiter</button></form>';
    } elseif ($setup['step'] === 'totp') {
        $html .= '<p class="steps">1 Passwort · <b>2 Authenticator</b> · 3 Übernehmen</p>' . $err
            . '<p>Scanne den Code mit deiner Authenticator-App (z. B. Aegis, 2FAS, Google Authenticator) und gib den angezeigten Code ein.</p>'
            . '<div id="qr" class="qr" data-uri="' . h(totp_uri($setup['secret'], $user ?: 'admin')) . '"></div>'
            . '<p class="secret">Schlüssel zum Abtippen:<br><code>' . h(trim(chunk_split($setup['secret'], 4, ' '))) . '</code></p>'
            . '<form method="post" class="form">' . $csrf . '<input type="hidden" name="action" value="setup_verify">'
            . '<label>6-stelliger Code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required autofocus></label>'
            . '<button class="btn primary">Prüfen</button></form>';
    } else {
        $snippet = "'admin' => [\n    'user'          => " . var_export($user, true) . ",\n    'password_hash' => " . var_export($setup['hash'], true) . ",\n    'totp_secret'   => " . var_export($setup['secret'], true) . ",\n],";
        $html .= '<p class="steps">1 Passwort · 2 Authenticator · <b>3 Übernehmen</b></p>'
            . '<p>Ersetze in deiner <code>config.php</code> den Block <code>admin</code> durch diesen und lade die Datei wieder hoch:</p>'
            . '<pre class="snippet" id="snippet">' . h($snippet) . '</pre>'
            . '<p><button type="button" class="btn" data-copy="#snippet">Kopieren</button></p>'
            . '<p class="muted">Das Passwort steht dort nur als Hash. Danach meldest du dich hier mit Benutzer, Passwort und Code an.</p>';
    }
    if ($setup['step'] !== 'password') {
        $html .= '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="setup_reset"><button class="link">Von vorn beginnen</button></form>';
    }
    render_card('Einrichtung', $html, ['vendor/qrcode.js', 'setup.js']);
}

/* ---------- Login / logout ---------- */

if ($action === 'logout') {
    logout();
    header('Location: ./');
    exit;
}

if (!is_logged_in()) {
    $error = '';
    if ($action === 'login') {
        $error = login_attempt(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['password'] ?? ''), (string) ($_POST['code'] ?? '')) ?? '';
        if ($error === '') {
            header('Location: ./');
            exit;
        }
    }
    render_card('Anmelden', '<h1>Anmelden</h1>' . ($error !== '' ? '<p class="alert">' . h($error) . '</p>' : '')
        . '<form method="post" class="form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="login">'
        . '<label>Benutzer<input name="user" autocomplete="username" required autofocus></label>'
        . '<label>Passwort<input type="password" name="password" autocomplete="current-password" required></label>'
        . '<label>Code aus der Authenticator-App<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required></label>'
        . '<button class="btn primary">Anmelden</button></form>');
}

/* ---------- Station manager ---------- */

/** Logo/cover editor markup, used in the station and the track dialog (wired up in app.js). */
function image_editor(string $empty): string
{
    return <<<HTML
<div class="image-editor" data-ie="root">
  <div class="logo-stage">
    <img data-ie="preview" alt="" hidden>
    <span data-ie="placeholder" class="logo-placeholder">{$empty}</span>
    <div data-ie="cropwrap" class="crop-wrap" hidden><img data-ie="cropimg" alt="Zuschnitt"></div>
  </div>
  <div class="crop-tools" data-ie="tools" hidden>
    <button type="button" class="btn small ghost" data-crop="zoom-in" title="Vergrößern">＋</button>
    <button type="button" class="btn small ghost" data-crop="zoom-out" title="Verkleinern">－</button>
    <button type="button" class="btn small ghost" data-crop="rotate" title="90° drehen">⟳</button>
    <button type="button" class="btn small ghost" data-crop="fit" title="Ganzes Bild einpassen">Einpassen</button>
    <button type="button" class="btn small ghost" data-crop="fill" title="Fläche füllen">Füllen</button>
    <label class="bg-pick">Hintergrund
      <select data-ie="bg"><option value="">transparent</option><option value="#ffffff">weiß</option><option value="#000000">schwarz</option><option value="custom">Farbe …</option></select>
      <input type="color" data-ie="bgcolor" value="#0b1328" hidden>
    </label>
  </div>
  <div class="logo-actions">
    <label class="btn small ghost file-btn">Bild hochladen<input type="file" data-ie="file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
    <button type="button" class="btn small ghost" data-ie="fromurl">Von URL</button>
    <button type="button" class="btn small ghost danger-text" data-ie="remove" hidden>Entfernen</button>
  </div>
  <div class="url-row" data-ie="urlrow" hidden>
    <input type="url" data-ie="url" placeholder="https://… Bildadresse">
    <button type="button" class="btn small" data-ie="urlload">Laden</button>
  </div>
  <p class="specs">Quadratisch 1:1, gespeichert als 600 × 600 px. Ideal: mindestens 600 × 600 px, PNG mit Transparenz oder JPG, höchstens 8 MB. Den Ausschnitt legst du im Editor fest.</p>
</div>
HTML;
}

$logo         = 'assets/logo.png?v=' . APP_VERSION;
$logout       = '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="logout"><button class="btn ghost small">Abmelden</button></form>';
$stationImage = image_editor('Kein Logo');
$trackImage   = image_editor('Kein Cover');
render_page('Sender', <<<HTML
<header class="topbar">
  <img class="brand" src="{$logo}" alt="1radiœ" width="116" height="40">
  <nav class="tabs" aria-label="Bereiche">
    <a href="#stations" data-view="stations" class="tab">Sender</a>
    <a href="#tracks" data-view="tracks" class="tab">Tracks</a>
    <a href="#system" data-view="system" class="tab">System</a>
  </nav>
  {$logout}
</header>
<main class="container">
  <section id="view-stations" class="view" hidden>
    <div class="toolbar">
      <div>
        <h1>Sender</h1>
        <p class="muted" id="summary"></p>
      </div>
      <div class="toolbar-actions">
        <button class="btn ghost" id="check-all">Alle Streams prüfen</button>
        <button class="btn ghost" id="add-category">Kategorie hinzufügen</button>
        <button class="btn ghost" id="add-playlist">Playlist hinzufügen</button>
        <button class="btn primary" id="add-station">Sender hinzufügen</button>
      </div>
    </div>
    <p class="hint">Vorschau der Liste wie in der Sonos-App. Reihenfolge per Drag &amp; Drop ändern, Klick öffnet den Sender, die Playlist oder die Kategorie. Kategorien erscheinen in Sonos als Ordner. Ausgegraute Einträge sind inaktiv oder leer und in Sonos nicht sichtbar.</p>
    <div id="grid" class="grid" aria-live="polite"></div>
    <div id="empty" class="empty" hidden>
      <p>Noch keine Sender angelegt.</p>
      <p><button class="btn primary" id="add-first">Ersten Sender hinzufügen</button> <button class="btn ghost" id="import-lite">Die drei Sender aus RADIOE Lite übernehmen</button></p>
    </div>
  </section>

  <section id="view-category" class="view" hidden>
    <a href="#stations" class="back">‹ Alle Sender</a>
    <div class="pl-head">
      <div class="pl-cover" id="cat-cover"></div>
      <div class="pl-info">
        <p class="eyebrow">Kategorie <span class="badge-inline" id="cat-inactive" hidden>inaktiv</span></p>
        <h1 id="cat-title"></h1>
        <p class="muted" id="cat-meta"></p>
        <p id="cat-desc"></p>
        <div class="toolbar-actions">
          <button class="btn ghost" id="cat-edit">Bearbeiten</button>
          <button class="btn ghost" id="cat-pick">Vorhandene zuordnen</button>
          <button class="btn primary" id="cat-add">Sender hinzufügen</button>
        </div>
      </div>
    </div>
    <p class="hint">In Sonos ein Ordner mit diesen Einträgen. Reihenfolge per Drag &amp; Drop ändern. Herausnehmen kannst du einen Eintrag im Editor unter „Kategorie“.</p>
    <div id="cat-grid" class="grid"></div>
    <p class="empty small" id="cat-empty" hidden>Noch leer. Ordne vorhandene Sender und Playlists zu oder lege einen neuen Sender an.</p>
  </section>

  <section id="view-playlist" class="view" hidden>
    <a href="#stations" class="back" id="pl-back">‹ Alle Sender</a>
    <div class="pl-head">
      <div class="pl-cover" id="pl-cover"></div>
      <div class="pl-info">
        <p class="eyebrow">Playlist <span class="badge-inline" id="pl-inactive" hidden>inaktiv</span></p>
        <h1 id="pl-title"></h1>
        <p class="muted" id="pl-meta"></p>
        <p id="pl-desc"></p>
        <div class="toolbar-actions">
          <button class="btn ghost" id="pl-edit">Bearbeiten</button>
          <button class="btn ghost" id="pl-pick">Aus Bibliothek hinzufügen</button>
          <label class="btn primary file-btn">MP3s hochladen<input type="file" id="pl-files" accept=".mp3,audio/mpeg" multiple></label>
        </div>
      </div>
    </div>
    <div class="dropzone" data-drop="playlist">MP3-Dateien hierher ziehen, sie landen in der Bibliothek und am Ende dieser Playlist.</div>
    <ul class="uploads" id="pl-uploads"></ul>
    <p class="hint">Reihenfolge per Drag &amp; Drop am Griff ändern. Zufallswiedergabe schaltest du in der Sonos-App ein.</p>
    <ol class="tracklist sortable" id="pl-tracks"></ol>
    <p class="empty small" id="pl-empty" hidden>Noch keine Tracks. Lade MP3s hoch oder füge welche aus der Bibliothek hinzu.</p>
  </section>

  <section id="view-tracks" class="view" hidden>
    <div class="toolbar">
      <div>
        <h1>Tracks</h1>
        <p class="muted" id="lib-summary"></p>
      </div>
      <div class="toolbar-actions">
        <button class="btn ghost" id="inbox-import" hidden></button>
        <label class="btn primary file-btn">MP3s hochladen<input type="file" id="lib-files" accept=".mp3,audio/mpeg" multiple></label>
      </div>
    </div>
    <div class="dropzone" data-drop="library">MP3-Dateien hierher ziehen. Titel, Interpret, Album und Cover werden aus den MP3-Tags übernommen. Große Sammlungen kannst du auch per FTP in den Ordner <code>1radioe-private/inbox/</code> legen und hier importieren.</div>
    <ul class="uploads" id="lib-uploads"></ul>
    <ul class="tracklist" id="lib-tracks"></ul>
    <p class="empty small" id="lib-empty" hidden>Noch keine Tracks in der Bibliothek.</p>
  </section>

  <section id="view-system" class="view" hidden>
    <h1>System</h1>
    <div class="panels">
      <div class="panel">
        <h2>Werte für das Sonos-Entwicklerportal</h2>
        <dl class="kv" id="portal"></dl>
      </div>
      <div class="panel">
        <h2>Status</h2>
        <dl class="kv" id="status"></dl>
      </div>
      <div class="panel wide">
        <div class="panel-head">
          <h2>Diagnose-Log</h2>
          <label class="switch"><input type="checkbox" id="log-enabled"><span>Protokollieren</span></label>
          <button class="btn ghost small" id="log-refresh">Aktualisieren</button>
          <button class="btn ghost small" id="log-clear">Leeren</button>
        </div>
        <p class="muted">Zeichnet die letzten 500 Anfragen von Sonos auf, mit IP-Adressen. Nur zur Fehlersuche einschalten.</p>
        <div class="table-wrap"><table class="log"><thead><tr><th>Zeit</th><th>Methode</th><th>Eintrag</th><th>Status</th><th>Hinweis</th><th>Absender</th></tr></thead><tbody id="log"></tbody></table></div>
      </div>
    </div>
  </section>
</main>

<dialog id="editor" class="editor">
  <form id="station-form" method="dialog" novalidate>
    <header class="editor-head">
      <h2 id="editor-title">Sender</h2>
      <button type="button" class="icon-btn" data-close aria-label="Schließen">✕</button>
    </header>
    <div class="editor-body">
      <div class="logo-col">{$stationImage}</div>
      <div class="field-col">
        <label>Name<input name="title" maxlength="120" required><small class="err" data-err="title"></small></label>
        <div class="stream-only">
          <label>Stream-URL
            <span class="row"><input name="stream_url" type="url" placeholder="https://… .mp3, .aac, .ogg, .m3u8, .pls, .m3u"><button type="button" class="btn small" id="probe">Stream testen</button></span>
            <small class="err" data-err="stream_url"></small>
          </label>
          <p class="probe" id="probe-result" hidden></p>
          <label>Format<select name="mime" id="mime"></select><small class="muted">Wird beim Stream-Test automatisch gesetzt.</small><small class="err" data-err="mime"></small></label>
        </div>
        <label>Beschreibung <small class="muted" id="desc-count"></small><textarea name="description" maxlength="500" rows="3"></textarea><small class="err" data-err="description"></small></label>
        <label class="cat-field">Kategorie<select name="category_id" id="category-select"></select><small class="muted">Ohne Kategorie steht der Eintrag in Sonos direkt in der Liste.</small><small class="err" data-err="category_id"></small></label>
        <label class="switch"><input type="checkbox" name="active" checked><span>Aktiv, in Sonos sichtbar</span></label>
        <input type="hidden" name="is_playlist" value="0">
        <p class="muted small" id="smapi-id"></p>
      </div>
    </div>
    <footer class="editor-foot">
      <button type="button" class="btn ghost danger-text" id="delete" hidden>Löschen</button>
      <span class="spacer"></span>
      <button type="button" class="btn ghost" data-close>Abbrechen</button>
      <button type="submit" class="btn primary" id="save">Speichern</button>
    </footer>
  </form>
</dialog>

<dialog id="track-editor" class="editor">
  <form id="track-form" method="dialog" novalidate>
    <header class="editor-head">
      <h2 id="track-editor-title">Track</h2>
      <button type="button" class="icon-btn" data-close aria-label="Schließen">✕</button>
    </header>
    <div class="editor-body">
      <div class="logo-col">{$trackImage}</div>
      <div class="field-col">
        <label>Titel<input name="title" maxlength="200" required><small class="err" data-err="title"></small></label>
        <label>Interpret<input name="artist" maxlength="200"><small class="err" data-err="artist"></small></label>
        <label>Album<input name="album" maxlength="200"><small class="err" data-err="album"></small></label>
        <audio id="track-audio" controls preload="none"></audio>
        <p class="muted small" id="track-info"></p>
        <p class="muted small">Ohne eigenes Cover zeigt Sonos das Bild der Playlist.</p>
      </div>
    </div>
    <footer class="editor-foot">
      <button type="button" class="btn ghost danger-text" id="track-delete">Aus Bibliothek löschen</button>
      <span class="spacer"></span>
      <button type="button" class="btn ghost" data-close>Abbrechen</button>
      <button type="submit" class="btn primary" id="track-save">Speichern</button>
    </footer>
  </form>
</dialog>

<dialog id="picker" class="editor narrow">
  <form method="dialog" id="picker-form">
    <header class="editor-head">
      <h2>Tracks aus der Bibliothek</h2>
      <button type="button" class="icon-btn" data-close aria-label="Schließen">✕</button>
    </header>
    <div class="picker-body">
      <input type="search" id="picker-filter" placeholder="Suchen nach Titel, Interpret, Album">
      <ul class="picker-list" id="picker-list"></ul>
    </div>
    <footer class="editor-foot">
      <span class="muted small" id="picker-count"></span>
      <span class="spacer"></span>
      <button type="button" class="btn ghost" data-close>Abbrechen</button>
      <button type="submit" class="btn primary" id="picker-add">Hinzufügen</button>
    </footer>
  </form>
</dialog>
<dialog id="st-picker" class="editor narrow">
  <form method="dialog" id="st-picker-form">
    <header class="editor-head">
      <h2>Sender und Playlists zuordnen</h2>
      <button type="button" class="icon-btn" data-close aria-label="Schließen">✕</button>
    </header>
    <div class="picker-body">
      <ul class="picker-list" id="st-picker-list"></ul>
    </div>
    <footer class="editor-foot">
      <span class="muted small" id="st-picker-count"></span>
      <span class="spacer"></span>
      <button type="button" class="btn ghost" data-close>Abbrechen</button>
      <button type="submit" class="btn primary" id="st-picker-add">Zuordnen</button>
    </footer>
  </form>
</dialog>
<div id="toast" class="toast" role="status" hidden></div>
HTML, ['vendor/sortable.min.js', 'vendor/cropper.min.js', 'app.js'], 'is-app');
