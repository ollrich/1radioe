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

$logout = '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="logout"><button class="btn ghost small">Abmelden</button></form>';
render_page('Sender', <<<HTML
<header class="topbar">
  <img class="brand" src="assets/logo.png" alt="1radiœ" width="116" height="40">
  <nav class="tabs" aria-label="Bereiche">
    <a href="#stations" data-view="stations" class="tab">Sender</a>
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
        <button class="btn primary" id="add-station">Sender hinzufügen</button>
      </div>
    </div>
    <p class="hint">Vorschau der Senderliste wie in der Sonos-App. Reihenfolge per Drag &amp; Drop ändern, Klick öffnet den Sender. Ausgegraute Sender sind inaktiv und in Sonos nicht sichtbar.</p>
    <div id="grid" class="grid" aria-live="polite"></div>
    <div id="empty" class="empty" hidden>
      <p>Noch keine Sender angelegt.</p>
      <p><button class="btn primary" id="add-first">Ersten Sender hinzufügen</button> <button class="btn ghost" id="import-lite">Die drei Sender aus RADIOE Lite übernehmen</button></p>
    </div>
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
        <div class="table-wrap"><table class="log"><thead><tr><th>Zeit</th><th>Methode</th><th>Sender</th><th>Status</th><th>Hinweis</th><th>Absender</th></tr></thead><tbody id="log"></tbody></table></div>
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
      <div class="logo-col">
        <div class="logo-stage" id="logo-stage">
          <img id="logo-preview" alt="" hidden>
          <span id="logo-placeholder" class="logo-placeholder">Kein Logo</span>
          <div id="crop-wrap" class="crop-wrap" hidden><img id="crop-img" alt="Zuschnitt"></div>
        </div>
        <div class="crop-tools" id="crop-tools" hidden>
          <button type="button" class="btn small ghost" data-crop="zoom-in" title="Vergrößern">＋</button>
          <button type="button" class="btn small ghost" data-crop="zoom-out" title="Verkleinern">－</button>
          <button type="button" class="btn small ghost" data-crop="rotate" title="90° drehen">⟳</button>
          <button type="button" class="btn small ghost" data-crop="fit" title="Ganzes Bild einpassen">Einpassen</button>
          <button type="button" class="btn small ghost" data-crop="fill" title="Fläche füllen">Füllen</button>
          <label class="bg-pick">Hintergrund
            <select id="crop-bg"><option value="">transparent</option><option value="#ffffff">weiß</option><option value="#000000">schwarz</option><option value="custom">Farbe …</option></select>
            <input type="color" id="crop-bg-color" value="#0a1328" hidden>
          </label>
        </div>
        <div class="logo-actions">
          <label class="btn small ghost file-btn">Bild hochladen<input type="file" id="logo-file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
          <button type="button" class="btn small ghost" id="logo-from-url">Von URL</button>
          <button type="button" class="btn small ghost danger-text" id="logo-remove" hidden>Entfernen</button>
        </div>
        <div class="url-row" id="logo-url-row" hidden>
          <input type="url" id="logo-url" placeholder="https://… Bildadresse">
          <button type="button" class="btn small" id="logo-url-load">Laden</button>
        </div>
        <p class="specs">Quadratisch 1:1, gespeichert als 600 × 600 px. Ideal: mindestens 600 × 600 px, PNG mit Transparenz oder JPG, höchstens 8 MB. Den Ausschnitt legst du im Editor fest.</p>
      </div>
      <div class="field-col">
        <label>Name<input name="title" maxlength="120" required><small class="err" data-err="title"></small></label>
        <label>Stream-URL
          <span class="row"><input name="stream_url" type="url" required placeholder="https://… .mp3, .aac, .ogg, .m3u8, .pls, .m3u"><button type="button" class="btn small" id="probe">Stream testen</button></span>
          <small class="err" data-err="stream_url"></small>
        </label>
        <p class="probe" id="probe-result" hidden></p>
        <label>Format<select name="mime" id="mime"></select><small class="muted">Wird beim Stream-Test automatisch gesetzt.</small><small class="err" data-err="mime"></small></label>
        <label>Beschreibung <small class="muted" id="desc-count"></small><textarea name="description" maxlength="500" rows="3"></textarea><small class="err" data-err="description"></small></label>
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
<div id="toast" class="toast" role="status" hidden></div>
HTML, ['vendor/sortable.min.js', 'vendor/cropper.min.js', 'app.js'], 'is-app');
