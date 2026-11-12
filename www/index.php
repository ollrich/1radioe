<?php
/**
 * 1radiœ web app: all stations, a player in the browser and the listening status.
 *
 * Rendered on the server: without JavaScript it is a list of links to the streams and
 * the dialogs show as plain sections. assets/app.js turns it into the player: the
 * scale with the needle on wide screens, the list with a docked player on phones.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/webapp.php';

webapp_headers();
header('Vary: Cookie');   // Ollie's own browser gets the listening switch
$listener = null;
try {
    db_migrate();
    $levels   = webapp_levels();
    $listener = listen_current_device();
} catch (Throwable $e) {
    error_log('1radioe web app: ' . $e->getMessage());
    http_response_code(503);
    $levels = null;
}

$v    = h(APP_VERSION);
$data = $levels !== null ? webapp_json(['levels' => $levels, 'ttl' => LISTEN_TTL, 'listen' => $listener !== null, 'interval' => LISTEN_INTERVAL]) : '';

/** The plain list for browsers without JavaScript. */
function nojs_list(array $levels): string
{
    $html = '';
    foreach ($levels['root'] as $item) {
        if ($item['type'] === 'category') {
            $inner = '';
            foreach ($levels[$item['id']] ?? [] as $s) {
                $inner .= '<li><a href="' . h($s['url']) . '">' . h($s['name']) . '</a></li>';
            }
            $html .= '<li>' . h($item['name']) . '<ul>' . $inner . '</ul></li>';
        } else {
            $html .= '<li><a href="' . h($item['url']) . '">' . h($item['name']) . '</a></li>';
        }
    }
    return '<ul class="nojs-list">' . $html . '</ul>';
}

$close = '<button type="button" class="close" data-close aria-label="Schließen"><svg width="16" height="16" viewBox="0 0 14 14" fill="none" aria-hidden="true"><line x1="2" y1="2" x2="12" y2="12"/><line x1="12" y1="2" x2="2" y2="12"/></svg></button>';
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#fcfcfd" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b1328" media="(prefers-color-scheme: dark)">
<meta name="description" content="Ollies Radiosender: hören im Browser, auf Sonos und in VLC.">
<title>1radiœ</title>
<link rel="icon" href="assets/favicon.png?v=<?= $v ?>">
<link rel="preload" href="assets/fonts/MonaSans.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<noscript><link rel="stylesheet" href="assets/nojs.css?v=<?= $v ?>"></noscript>
<script src="assets/app.js?v=<?= $v ?>" defer></script>
</head>
<body>
<?php if ($data !== ''): ?>
<script type="application/json" id="r1-data"><?= $data ?></script>
<?php endif; ?>
<div class="page">
  <header class="top">
    <a class="wm" href="./"><span class="sr">1radiœ, Startseite</span><span aria-hidden="true">1radi<span class="oe">œ</span></span></a>
    <span class="top-actions">
<?php if ($listener !== null): ?>
      <button type="button" class="listen-switch" data-listen-switch aria-pressed="true" data-js title="Nur für dich sichtbar: Dein Hören in diesem Browser wird als Hörstatus erfasst (<?= h($listener['name']) ?>).">
        <span class="pill" aria-hidden="true"><i></i><i></i><i></i></span><span data-listen-label>Hören wird erfasst</span>
      </button>
<?php endif; ?>
      <a class="pillbtn" href="#about" data-dialog="about"><span class="wide-only">Über 1radiœ</span><span class="narrow-only">Über</span></a>
    </span>
  </header>

  <main id="main">
    <h1 class="sr">1radiœ: Ollies Radiosender</h1>
<?php if ($levels === null): ?>
    <p class="notice">Die Sender sind gerade nicht erreichbar. Bitte später noch einmal versuchen.</p>
<?php elseif (!$levels['root']): ?>
    <p class="notice">Noch keine Sender.</p>
<?php else: ?>
    <section class="stage" aria-label="Player" data-js>
      <div class="stage-art">
        <span class="tile tile-xl" data-r1="art"></span>
        <span class="numchip" data-r1="num" aria-hidden="true"></span>
      </div>
      <div class="stage-info">
        <p class="kicker" data-r1="kicker" hidden></p>
        <h2 class="disp stage-name" data-r1="name"></h2>
        <p class="state" role="status"><span class="state-main"><span class="dot" data-r1="dot" aria-hidden="true"></span><span data-r1="state">Bereit</span></span><span class="pill" data-r1="pill" role="img" aria-label="Ollie hört gerade" title="Ollie hört gerade" hidden><i></i><i></i><i></i></span></p>
        <div class="controls">
          <button type="button" class="ic" data-act="prev" aria-label="Vorheriger Sender"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h2.5v14H6zM19 6.2v11.6a.9.9 0 0 1-1.4.75L9.8 12.75a.9.9 0 0 1 0-1.5l7.8-5.8A.9.9 0 0 1 19 6.2z"/></svg></button>
          <button type="button" class="play" data-act="toggle" aria-label="Abspielen"><svg class="i-play" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.2-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg><svg class="i-pause" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg></button>
          <button type="button" class="ic" data-act="next" aria-label="Nächster Sender"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 5H18v14h-2.5zM5 6.2v11.6a.9.9 0 0 0 1.4.75l7.8-5.8a.9.9 0 0 0 0-1.5L6.4 5.45A.9.9 0 0 0 5 6.2z"/></svg></button>
        </div>
        <div class="secondary">
          <label class="volume" data-volume>
            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h3.5L12 5v14l-4.5-4H4zM15.5 9.2l1.1-1.1a5.5 5.5 0 0 1 0 7.8l-1.1-1.1a4 4 0 0 0 0-5.6z"/></svg>
            <span class="sr">Lautstärke</span>
            <input type="range" min="0" max="100" step="1" value="80" data-r1="volume">
          </label>
          <button type="button" class="pillbtn" data-act="info"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 1.8a8.2 8.2 0 1 1 0 16.4 8.2 8.2 0 0 1 0-16.4zM11 10h2v7h-2zm0-3h2v2h-2z"/></svg>Über den Sender</button>
        </div>
      </div>
    </section>

    <section class="picker" aria-label="Senderauswahl">
      <div class="picker-head" data-js>
        <div class="crumbs" data-r1="crumbs"></div>
        <span class="tipwrap">
          <button type="button" class="qbtn" aria-label="Tastaturkürzel" aria-describedby="r1-keys"><span class="qmark" aria-hidden="true">?</span></button>
          <span class="tip" role="tooltip" id="r1-keys">
            <span><kbd>←</kbd><kbd>→</kbd>Sender zurück und vor</span>
            <span><kbd>1</kbd>bis <kbd>0</kbd>Sender direkt wählen</span>
            <span><kbd>Leertaste</kbd>Play und Pause</span>
            <span><kbd>Esc</kbd>zurück zu allen Sendern</span>
          </span>
        </span>
      </div>
      <div class="scale-row" data-js>
        <button type="button" class="ic scl" data-scroll="-1" aria-label="Weitere Sender links" hidden><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.7 4.3 8 12l7.7 7.7-1.4 1.4L5.2 12l9.1-9.1z"/></svg></button>
        <div class="scroller" data-r1="scroller"><div class="strip" data-r1="strip"></div></div>
        <button type="button" class="ic scl" data-scroll="1" aria-label="Weitere Sender rechts" hidden><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.3 4.3 16 12l-7.7 7.7 1.4 1.4 9.1-9.1-9.1-9.1z"/></svg></button>
      </div>
      <h2 class="list-title">Alle Sender</h2>
      <ul class="list" data-r1="list" data-js></ul>
      <?= nojs_list($levels) ?>
    </section>
<?php endif; ?>
  </main>

  <footer class="foot">
    <a href="#impressum" data-dialog="impressum">Impressum</a>
    <a href="#datenschutz" data-dialog="datenschutz">Datenschutz</a>
  </footer>
</div>

<div class="mini" data-r1="mini" hidden>
  <button type="button" class="mini-open" data-act="sheet">
    <span class="tile tile-s" data-r1="art"></span>
    <span class="rn"><span class="rname" data-r1="name"></span><span class="rsub"><span class="dot" data-r1="dot" aria-hidden="true"></span><span data-r1="state">Bereit</span></span></span>
  </button>
  <button type="button" class="ic ic-play" data-act="toggle" aria-label="Abspielen"><svg class="i-play" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.2-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg><svg class="i-pause" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg></button>
  <button type="button" class="ic ic-panel" data-act="next" aria-label="Nächster Sender"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 5H18v14h-2.5zM5 6.2v11.6a.9.9 0 0 0 1.4.75l7.8-5.8a.9.9 0 0 0 0-1.5L6.4 5.45A.9.9 0 0 0 5 6.2z"/></svg></button>
</div>

<dialog class="sheet" id="sheet" aria-label="Player">
  <div class="sheet-top">
    <button type="button" class="ic" data-close aria-label="Player schließen"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.3 8.3 12 15l6.7-6.7 1.4 1.4L12 17.8 3.9 9.7z"/></svg></button>
    <p class="sheet-level" data-r1="level"></p>
    <button type="button" class="ic" data-act="info" aria-label="Über den Sender"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 1.8a8.2 8.2 0 1 1 0 16.4 8.2 8.2 0 0 1 0-16.4zM11 10h2v7h-2zm0-3h2v2h-2z"/></svg></button>
  </div>
  <div class="sheet-body">
    <div class="stage-art">
      <span class="tile tile-xl" data-r1="art"></span>
      <span class="numchip" data-r1="num" aria-hidden="true"></span>
    </div>
    <h2 class="disp sheet-name" data-r1="name"></h2>
    <p class="state"><span class="state-main"><span class="dot" data-r1="dot" aria-hidden="true"></span><span data-r1="state">Bereit</span></span><span class="pill" data-r1="pill" role="img" aria-label="Ollie hört gerade" title="Ollie hört gerade" hidden><i></i><i></i><i></i></span></p>
    <div class="controls">
      <button type="button" class="ic" data-act="prev" aria-label="Vorheriger Sender"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h2.5v14H6zM19 6.2v11.6a.9.9 0 0 1-1.4.75L9.8 12.75a.9.9 0 0 1 0-1.5l7.8-5.8A.9.9 0 0 1 19 6.2z"/></svg></button>
      <button type="button" class="play" data-act="toggle" aria-label="Abspielen"><svg class="i-play" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.2-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg><svg class="i-pause" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg></button>
      <button type="button" class="ic" data-act="next" aria-label="Nächster Sender"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 5H18v14h-2.5zM5 6.2v11.6a.9.9 0 0 0 1.4.75l7.8-5.8a.9.9 0 0 0 0-1.5L6.4 5.45A.9.9 0 0 0 5 6.2z"/></svg></button>
    </div>
  </div>
</dialog>

<dialog class="dialog" id="info" aria-labelledby="info-title">
  <?= $close ?>
  <div class="info-head">
    <span class="tile tile-m" data-info="art"></span>
    <div>
      <p class="dkicker">Über den Sender</p>
      <h2 class="disp dh" id="info-title" data-info="name"></h2>
    </div>
  </div>
  <p class="dp" data-info="desc"></p>
  <p class="dp dlinks">
    <a data-info="website" target="_blank" rel="noopener" hidden></a>
    <a data-info="m3u">Als Playlist für VLC</a>
  </p>
</dialog>

<dialog class="dialog" id="about" aria-labelledby="about-title">
  <?= $close ?>
  <h2 class="disp dh" id="about-title">Über 1radiœ</h2>
  <p class="dp">1radiœ ist meine persönliche Auswahl an Internetradios. Gepflegt wird sie an einer Stelle und läuft überall: hier im Browser, auf Sonos, als Playlist in VLC und später in einer eigenen App.</p>
  <p class="dp">Die Sender spielen direkt vom Original-Stream. Über diesen Server läuft kein Ton. Es gibt kein Tracking und keine externen Dienste.</p>
  <p class="dp with-pill"><span class="pill" aria-hidden="true"><i></i><i></i><i></i></span><span>Die kleinen Balken an einem Sender zeigen, dass ich ihn gerade höre. Mehr wird dabei nicht erfasst.</span></p>
  <h3 class="d3">Mitnehmen</h3>
  <p class="dp">Alle Sender als Playlist für VLC und andere Player: <a href="streams.m3u">streams.m3u</a> oder <a href="streams.pls">streams.pls</a></p>
  <h3 class="d3">Quellcode</h3>
  <p class="dp"><a href="https://github.com/ollrich/1radioe" target="_blank" rel="noopener">github.com/ollrich/1radioe</a>, MIT-Lizenz</p>
  <p class="dnote">Ein Projekt von Ollie.</p>
</dialog>

<dialog class="dialog" id="impressum" aria-labelledby="impressum-title">
  <?= $close ?>
  <h2 class="disp dh" id="impressum-title">Impressum</h2>
  <p class="dp">[Name]<br>[Anschrift]<br>[Postleitzahl, Ort]</p>
  <h3 class="d3">Kontakt</h3>
  <p class="dp">[E-Mail-Adresse]</p>
  <h3 class="d3">Verantwortlich für den Inhalt</h3>
  <p class="dp">[Name, Anschrift wie oben]</p>
</dialog>

<dialog class="dialog" id="datenschutz" aria-labelledby="datenschutz-title">
  <?= $close ?>
  <h2 class="disp dh" id="datenschutz-title">Datenschutz</h2>
  <h3 class="d3">Kein Tracking</h3>
  <p class="dp">Diese Seite nutzt keine Analyse-Werkzeuge und keine Werbung und lädt nichts von fremden Servern. Schriften und Skripte liegen auf diesem Server.</p>
  <h3 class="d3">Beim Abspielen</h3>
  <p class="dp">Dein Browser verbindet sich direkt mit dem Server des jeweiligen Senders. Dieser erhält dabei deine IP-Adresse. Es gelten die Datenschutzhinweise des Senders.</p>
  <h3 class="d3">Lautstärke</h3>
  <p class="dp">Die gewählte Lautstärke liegt im lokalen Speicher deines Browsers und verlässt dein Gerät nicht.</p>
  <h3 class="d3">Cookies</h3>
  <p class="dp">Besucher bekommen keine Cookies. Ein Cookie gibt es nur für Ollie selbst, damit sein Hörstatus erfasst werden kann.</p>
  <h3 class="d3">Hosting und Verantwortlicher</h3>
  <p class="dp">[Hoster, Server-Logfiles, Speicherdauer]<br>[Name, Kontakt]</p>
</dialog>
</body>
</html>
