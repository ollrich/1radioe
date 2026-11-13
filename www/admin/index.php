<?php
/**
 * 1radiœ admin: setup wizard, login and the station manager (app shell).
 * The station manager itself runs in assets/app.js and talks to api.php.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

$problem = admin_boot();

if ($problem === 'config') {
    render_card('Konfiguration fehlt', '<h1>Konfiguration fehlt</h1>'
        . '<p>Lege die Datei <code>config.php</code> in einen Ordner <code>' . CONFIG_DIR . '</code> neben dem Checkout, also außerhalb des Web-Verzeichnisses:</p>'
        . '<pre>' . h(dirname(realpath(APP_ROOT) ?: APP_ROOT)) . '/' . CONFIG_DIR . "/config.php</pre>"
        . '<p>Eine Vorlage liegt im Repository als <code>config.sample.php</code>.</p>');
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
    <a href="#posting" data-view="posting" class="tab">Posting</a>
    <a href="#system" data-view="system" class="tab">System</a>
  </nav>
  {$logout}
</header>
<main class="container">
  <div class="banner" id="listen-banner" hidden>
    <div>
      <p class="banner-title">Dieses Gerät zum Hören merken?</p>
      <p class="muted">Dann zeigt 1radioe.net an deinem Sender, wenn du hier im Browser hörst. Das Gerät bekommt dafür ein eigenes Cookie, das nur den Hörstatus melden darf und nicht in die Verwaltung führt.</p>
    </div>
    <form class="banner-form" data-remember>
      <input name="name" maxlength="80" required placeholder="Name, z. B. Firmenrechner" aria-label="Name des Geräts">
      <button class="btn primary small">Merken</button>
      <button type="button" class="btn ghost small" id="listen-banner-dismiss">Nicht jetzt</button>
    </form>
  </div>
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

  <section id="view-posting" class="view" hidden>
    <div class="toolbar">
      <div>
        <h1>Posting</h1>
        <p class="muted" id="social-summary"></p>
      </div>
    </div>
    <p class="hint">Startest du einen Sender, egal ob auf Sonos oder im Browser, und läuft er nach der Wartezeit noch, geht ein Beitrag an Bluesky und Mastodon. Er verrät dasselbe wie das Status-Icon auf 1radioe.net, nie Gerät oder Quelle. Setzt du in der Web-App das Erfassen aus, wird auch nicht gepostet.</p>
    <div class="banner" id="social-key" hidden>
      <div>
        <p class="banner-title">Schlüssel für App-Passwort und Token fehlt</p>
        <p class="muted">Zugangsdaten werden verschlüsselt gespeichert. Dafür in <code>config.php</code> diese Zeile ergänzen, der Wert ist frisch erzeugt und wird nirgends gespeichert:</p>
        <p class="key-line"><code id="social-key-line"></code> <button type="button" class="btn ghost small" id="social-key-copy">Kopieren</button></p>
        <p class="muted" id="social-sodium" hidden>Außerdem fehlt die PHP-Erweiterung sodium. Sie gehört seit PHP 7.2 zum Standard, bitte im KAS die PHP-Version prüfen.</p>
      </div>
    </div>
    <form id="social-form" novalidate>
      <div class="panels">
        <div class="panel wide">
          <div class="panel-head">
            <h2>Beitrag</h2>
            <label class="switch"><input type="checkbox" name="enabled"><span>Automatisch posten</span></label>
          </div>
          <small class="err" data-err="enabled"></small>
          <div class="social-grid">
            <div class="field-col">
              <div class="tpl">
                <div class="tpl-head">
                  <label for="tpl-bsky">Text für Bluesky</label>
                  <label class="tpl-lang">Sprache<input name="bsky.lang" maxlength="2" pattern="[a-z]{2}" spellcheck="false" autocomplete="off" placeholder="en"></label>
                </div>
                <textarea id="tpl-bsky" name="bsky.template" rows="4" maxlength="1000" data-template></textarea>
                <small class="err" data-err="bsky.template"></small><small class="err" data-err="bsky.lang"></small>
              </div>
              <div class="tpl">
                <div class="tpl-head">
                  <label for="tpl-masto">Text für Mastodon</label>
                  <label class="tpl-lang">Sprache<input name="masto.lang" maxlength="2" pattern="[a-z]{2}" spellcheck="false" autocomplete="off" placeholder="de"></label>
                </div>
                <textarea id="tpl-masto" name="masto.template" rows="4" maxlength="1000" data-template></textarea>
                <small class="err" data-err="masto.template"></small><small class="err" data-err="masto.lang"></small>
              </div>
              <p class="chips" aria-label="Platzhalter in den zuletzt bearbeiteten Text einfügen">
                <button type="button" class="chip" data-insert="{sender}">{sender}</button>
                <button type="button" class="chip" data-insert="{link}">{link}</button>
                <button type="button" class="chip" data-insert="{website}">{website}</button>
                <button type="button" class="chip" data-insert="{hashtags}">{hashtags}</button>
              </p>
              <p class="muted small">{sender}: Name des Senders. {link}: seine Seite auf 1radioe.net, dort steht er gleich im Player. {website}: Website des Senders, entfällt, wenn keine eingetragen ist. {hashtags}: die Hashtags unten plus die am Sender. Wird ein Beitrag zu lang, fallen Hashtags von hinten weg. Die Sprache ist das Kürzel, das das Netz am Beitrag speichert, zum Beispiel en oder de.</p>
              <label>Hashtags für alle Sender<input name="hashtags" maxlength="255" placeholder="#Radio #NowPlaying"><small class="err" data-err="hashtags"></small></label>
              <label>Adresse der Web-App <small class="muted" id="site-hint"></small><input name="site_url" type="url" maxlength="200"><small class="err" data-err="site_url"></small></label>
            </div>
            <div class="field-col">
              <label>Vorschau für<select id="social-station"></select></label>
              <figure class="preview">
                <figcaption><span>Bluesky</span><span class="count" id="pv-bsky-count"></span></figcaption>
                <p class="preview-text" id="pv-bsky"></p>
              </figure>
              <figure class="preview">
                <figcaption><span>Mastodon</span><span class="count" id="pv-masto-count"></span></figcaption>
                <p class="preview-text" id="pv-masto"></p>
              </figure>
              <p class="muted small">Bluesky zeigt Links gekürzt und bekommt eine Link-Karte mit dem Senderlogo. Mastodon baut die Karte selbst aus der Senderseite und zählt jeden Link als 23 Zeichen.</p>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h2>Bluesky</h2>
            <label class="switch"><input type="checkbox" name="bsky.enabled"><span>Ein</span></label>
          </div>
          <small class="err" data-err="bsky.enabled"></small>
          <div class="field-col">
            <label>Handle<input name="bsky.handle" maxlength="253" placeholder="name.bsky.social" autocomplete="off" spellcheck="false"><small class="err" data-err="bsky.handle"></small></label>
            <label>App-Passwort<input name="bsky.password" type="password" maxlength="19" autocomplete="new-password" spellcheck="false"><small class="muted">Anlegen in Bluesky unter Einstellungen, Datenschutz und Sicherheit, App-Passwörter. Nie das Hauptpasswort.</small><small class="err" data-err="bsky.password"></small></label>
            <label class="switch" data-secret="bsky" hidden><input type="checkbox" name="bsky.clear"><span>Gespeichertes App-Passwort entfernen</span></label>
            <label>PDS <small class="muted">bei Konten auf bsky.social so lassen</small><input name="bsky.pds" maxlength="200" placeholder="https://bsky.social" spellcheck="false"><small class="err" data-err="bsky.pds"></small></label>
            <p class="row"><button type="button" class="btn ghost small" data-check="bsky">Verbindung prüfen</button></p>
            <p class="probe" id="bsky-state" hidden></p>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h2>Mastodon</h2>
            <label class="switch"><input type="checkbox" name="masto.enabled"><span>Ein</span></label>
          </div>
          <small class="err" data-err="masto.enabled"></small>
          <div class="field-col">
            <label>Instanz<input name="masto.instance" maxlength="200" placeholder="https://mastodon.social" spellcheck="false"><small class="err" data-err="masto.instance"></small></label>
            <label>Access-Token<input name="masto.token" type="password" maxlength="200" autocomplete="new-password" spellcheck="false"><small class="muted">In Mastodon unter Einstellungen, Entwicklung, Neue Anwendung. Die Berechtigung write:statuses genügt, mit profile zeigt die Prüfung auch den Kontonamen.</small><small class="err" data-err="masto.token"></small></label>
            <label class="switch" data-secret="masto" hidden><input type="checkbox" name="masto.clear"><span>Gespeichertes Token entfernen</span></label>
            <label>Sichtbarkeit<select name="masto.visibility"><option value="public">Öffentlich</option><option value="unlisted">Nicht gelistet</option><option value="private">Nur für Follower</option></select></label>
            <p class="row"><button type="button" class="btn ghost small" data-check="masto">Verbindung prüfen</button></p>
            <p class="probe" id="masto-state" hidden></p>
          </div>
        </div>

        <div class="panel wide">
          <h2>Bremsen</h2>
          <div class="brakes">
            <label>Wartezeit <small class="muted">Minuten, die ein Sender laufen muss</small><input name="delay" type="number" min="0" max="60" step="1"><small class="err" data-err="delay"></small></label>
            <label>Sperrfrist <small class="muted">Stunden bis zum nächsten Beitrag über denselben Sender</small><input name="cooldown" type="number" min="0" max="168" step="1"><small class="err" data-err="cooldown"></small></label>
            <label>Höchstens <small class="muted">Beiträge pro Tag</small><input name="daily" type="number" min="1" max="50" step="1"><small class="err" data-err="daily"></small></label>
          </div>
          <label class="switch"><input type="checkbox" name="card"><span>Link-Karte mit Senderlogo bei Bluesky</span></label>
          <p class="muted small">Die Wartezeit filtert Durchzappen und Fehlstarts. Einzelne Sender schließt du im Sender-Editor aus, dort gibt es auch eigene Hashtags.</p>
        </div>
      </div>
      <small class="err" data-err="secret"></small>
      <div class="form-foot">
        <button type="submit" class="btn primary" id="social-save">Speichern</button>
        <span class="spacer"></span>
        <button type="button" class="btn ghost" id="social-test">Testbeitrag senden</button>
      </div>
      <p class="muted small right">Der Testbeitrag geht wirklich raus, für den Sender aus der Vorschau, an alle eingeschalteten Netzwerke. Wartezeit und Bremsen gelten dafür nicht.</p>
    </form>

    <div class="panel wide log-panel">
      <div class="panel-head">
        <h2>Protokoll</h2>
        <button class="btn ghost small" id="social-log-refresh">Aktualisieren</button>
      </div>
      <p class="muted">Die letzten Beiträge, auch übersprungene und fehlgeschlagene. Nur hier sichtbar.</p>
      <div class="table-wrap"><table class="log"><thead><tr><th>Zeit</th><th>Sender</th><th>Ergebnis</th><th>Bluesky</th><th>Mastodon</th></tr></thead><tbody id="social-log"></tbody></table></div>
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
        <h2>Öffentliche Adressen</h2>
        <p class="muted">Alle aktiven Sender, ohne MP3-Playlists. Die Playlists zeigen direkt auf die Original-Streams, zum Beispiel für VLC unter „Medien, Netzwerkstream öffnen“.</p>
        <dl class="kv" id="public"></dl>
      </div>
      <div class="panel wide">
        <h2>Hörgeräte</h2>
        <p class="muted">Browser, die deinen Hörstatus melden dürfen. Das Cookie gilt 180 Tage und verlängert sich bei Benutzung. Es erlaubt nur Meldungen an <code>/api/listen</code>, nie den Zugang zur Verwaltung. Gespeichert ist nur eine Prüfsumme.</p>
        <p id="listen-this"></p>
        <form class="banner-form" data-remember id="listen-remember" hidden>
          <input name="name" maxlength="80" required placeholder="Name, z. B. Firmenrechner" aria-label="Name des Geräts">
          <button class="btn primary small">Dieses Gerät merken</button>
        </form>
        <div class="table-wrap"><table class="log"><thead><tr><th>Name</th><th>Angelegt</th><th>Zuletzt benutzt</th><th>Status</th><th></th></tr></thead><tbody id="listen-devices"></tbody></table></div>
      </div>
      <div class="panel wide">
        <div class="panel-head">
          <h2>Hörstatus</h2>
          <button class="btn ghost small" id="listen-refresh">Aktualisieren</button>
        </div>
        <p class="muted">Zum Testen: Ein Sender läuft, solange seine letzte Meldung jünger als 150 Sekunden ist und kein „aus“ folgte. Sonos meldet beim Start, danach jede Minute, und beim Stoppen. Gespeichert werden nur Sender und Zeitpunkt. Die Liste aktualisiert sich alle 10 Sekunden.</p>
        <div class="table-wrap"><table class="log"><thead><tr><th>Sender</th><th>Zustand</th><th>Letzte Meldung</th></tr></thead><tbody id="listen"></tbody></table></div>
      </div>
      <div class="panel wide">
        <h2>Sonos-Adressen</h2>
        <p class="muted">Unter welcher Adresse Sonos diesen Dienst aufruft, auch über eine alte Adresse, die nur noch hierher führt. Eine alte Adresse kann weg, wenn sie einige Tage nicht mehr aufgerufen wurde. Gezählt wird auch ohne Diagnose-Log, ohne IP-Adressen.</p>
        <div class="table-wrap"><table class="log"><thead><tr><th>Adresse</th><th>Zuletzt</th><th>Anfragen</th><th>Seit</th></tr></thead><tbody id="endpoints"></tbody></table></div>
      </div>
      <div class="panel wide">
        <div class="panel-head">
          <h2>Diagnose-Log</h2>
          <label class="switch"><input type="checkbox" id="log-enabled"><span>Protokollieren</span></label>
          <button class="btn ghost small" id="log-refresh">Aktualisieren</button>
          <button class="btn ghost small" id="log-clear">Leeren</button>
        </div>
        <p class="muted">Zeichnet die letzten 500 Anfragen von Sonos auf, mit IP-Adressen. Nur zur Fehlersuche einschalten.</p>
        <div class="table-wrap"><table class="log"><thead><tr><th>Zeit</th><th>Methode</th><th>Eintrag</th><th>Status</th><th>Hinweis</th><th>Adresse</th><th>Absender</th></tr></thead><tbody id="log"></tbody></table></div>
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
          <div class="probe probe-https" id="probe-https" hidden></div>
          <label>Format<select name="mime" id="mime"></select><small class="muted">Wird beim Stream-Test automatisch gesetzt.</small><small class="err" data-err="mime"></small></label>
        </div>
        <label class="stream-only">Website des Senders <small class="muted">für „Über den Sender“ in der Web-App</small><input name="website" type="url" maxlength="500" placeholder="https://…"><small class="err" data-err="website"></small></label>
        <div class="stream-only social-fields">
          <label class="switch"><input type="checkbox" name="social" checked><span>Posten, wenn ich diesen Sender höre</span></label>
          <label>Eigene Hashtags <small class="muted">kommen zu denen aus „Posting“ dazu</small><input name="hashtags" maxlength="255" placeholder="#Jazz #Köln"><small class="err" data-err="hashtags"></small></label>
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
