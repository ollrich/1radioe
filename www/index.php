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

// A station page: 1radioe.net/s/<smapi_id> (the link in posts) forwards to ?s=<smapi_id>,
// which opens the app with that station in the player and carries Open Graph tags
// for link cards on Mastodon and elsewhere.
$sid = isset($_GET['s']) && is_string($_GET['s']) && preg_match('~^[A-Za-z0-9._-]{1,64}$~', $_GET['s']) ? $_GET['s'] : null;
if ($sid !== null && isset($_GET['go'])) {
    // back to the page the station page belongs to: /s/<id> to /, /de/s/<id> to /de/
    $page = (string) preg_replace('~s/[^/]*$~', '', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    header('Location: ' . ($page !== '' ? $page : '/') . '?s=' . rawurlencode($sid), true, 302);
    exit;
}

$lang = webapp_lang();
// 1radioe.net is English; a browser whose first language is German goes to /de/,
// unless it chose English in the menu (?lang=en). No cookie needed for that.
header('Vary: Cookie, Accept-Language');   // Ollie's own browser gets the listening switch
if ($lang === 'en' && !isset($_GET['lang']) && webapp_prefers_german()) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: de/' . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}
$L = webapp_strings($lang);
webapp_headers();
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
// texts of the dialogs, edited in the admin (Texte); the defaults if the database is away
$texts = [];
foreach (array_keys(TEXT_NAMES) as $name) {
    try {
        $texts[$name] = text_html($name, $lang);
    } catch (Throwable) {
        $texts[$name] = text_clean(text_defaults()[$name][$lang]);
    }
}

$start = null;
foreach ($sid !== null ? ($levels ?? []) : [] as $list) {
    foreach ($list as $item) {
        if ($item['type'] === 'station' && $item['id'] === $sid) {
            $start = $item;
            break 2;
        }
    }
}
$og = [
    'title'       => $start !== null ? $start['name'] : APP_NAME,
    'description' => $start !== null && $start['desc'] !== '' ? $start['desc'] : $L['description'],
    'url'         => base_url() . ($lang === 'de' ? '/de' : '') . ($start !== null ? '/s/' . rawurlencode($start['id']) : '/'),
    'image'       => $start['logo'] ?? null,
];

$v    = h(APP_VERSION);
$data = $levels !== null ? webapp_json(['levels' => $levels, 'ttl' => LISTEN_TTL, 'listen' => $listener !== null, 'interval' => LISTEN_INTERVAL,
    'start' => $start['id'] ?? null, 'lang' => $lang,
    'text' => ['listening' => $L['listening'], 'listeningTo' => $L['listening_to'], 'and' => $L['and']] + webapp_script_strings($lang)]) : '';
// the other language, with the station of a station page
$langLinks = [
    'en' => ($lang === 'de' ? '../' : './') . '?lang=en',
    'de' => ($lang === 'de' ? './' : 'de/'),
];

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

$close = '<button type="button" class="close" data-close aria-label="' . h($L['close']) . '"><svg width="16" height="16" viewBox="0 0 14 14" fill="none" aria-hidden="true"><line x1="2" y1="2" x2="12" y2="12"/><line x1="12" y1="2" x2="2" y2="12"/></svg></button>';
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#fcfcfd" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b1328" media="(prefers-color-scheme: dark)">
<meta name="description" content="<?= h($L['description']) ?>">
<link rel="alternate" hreflang="en" href="<?= h(base_url()) ?>/">
<link rel="alternate" hreflang="de" href="<?= h(base_url()) ?>/de/">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h(APP_NAME) ?>">
<meta property="og:title" content="<?= h($og['title']) ?>">
<meta property="og:description" content="<?= h($og['description']) ?>">
<meta property="og:url" content="<?= h($og['url']) ?>">
<?php if ($og['image'] !== null): ?>
<meta property="og:image" content="<?= h($og['image']) ?>">
<meta name="twitter:card" content="summary">
<?php endif; ?>
<title><?= $start !== null ? h($start['name']) . ' · ' : '' ?>1radiœ</title>
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
      <button type="button" class="listen-switch" data-listen-switch aria-pressed="true" data-js title="<?= h(sprintf($L['listen_title'], $listener['name'])) ?>">
        <span class="pill" aria-hidden="true"><i></i><i></i><i></i></span><span data-listen-label><?= h($L['listen_on']) ?></span>
      </button>
<?php endif; ?>
      <button type="button" class="menu-btn" data-menu aria-controls="menu" aria-expanded="false" aria-label="<?= h($L['menu_open']) ?>" data-js><svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg></button>
    </span>
  </header>

  <main id="main">
    <h1 class="sr"><?= h($L['h1']) ?></h1>
<?php if ($levels === null): ?>
    <p class="notice"><?= h($L['unreachable']) ?></p>
<?php elseif (!$levels['root']): ?>
    <p class="notice"><?= h($L['no_stations']) ?></p>
<?php else: ?>
    <section class="stage" aria-label="Player" data-js>
      <div class="stage-art">
        <span class="tile tile-xl" data-r1="art"></span>
        <span class="numchip" data-r1="num" aria-hidden="true"></span>
      </div>
      <div class="stage-info">
        <p class="kicker" data-r1="kicker" hidden></p>
        <h2 class="disp stage-name" data-r1="name"></h2>
        <p class="track" data-r1="track" hidden><span class="track-title" data-r1="track-title"></span><span class="track-artist" data-r1="track-artist"></span></p>
        <p class="state" role="status"><span class="state-main"><span class="dot" data-r1="dot" aria-hidden="true"></span><span data-r1="state"><?= h($L['idle']) ?></span></span><span class="pill" data-r1="pill" role="img" aria-label="<?= h($L['listening']) ?>" hidden><i></i><i></i><i></i></span></p>
        <div class="controls">
          <button type="button" class="ic" data-act="prev" aria-label="<?= h($L['prev']) ?>"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h2.5v14H6zM19 6.2v11.6a.9.9 0 0 1-1.4.75L9.8 12.75a.9.9 0 0 1 0-1.5l7.8-5.8A.9.9 0 0 1 19 6.2z"/></svg></button>
          <button type="button" class="play" data-act="toggle" aria-label="<?= h($L['play']) ?>"><svg class="i-play" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.2-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg><svg class="i-pause" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg></button>
          <button type="button" class="ic" data-act="next" aria-label="<?= h($L['next']) ?>"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 5H18v14h-2.5zM5 6.2v11.6a.9.9 0 0 0 1.4.75l7.8-5.8a.9.9 0 0 0 0-1.5L6.4 5.45A.9.9 0 0 0 5 6.2z"/></svg></button>
        </div>
        <div class="secondary">
          <label class="volume" data-volume>
            <svg class="vol-ic" width="25" height="22" viewBox="0 0 27 24" aria-hidden="true"><path d="M2 9h3.5L10 5v14l-4.5-4H2z"/><g class="waves" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M13.28 9.12A4 4 0 0 1 13.28 14.88"/><path d="M15.9 6.79A7.5 7.5 0 0 1 15.9 17.21"/><path d="M18.67 4.64A11 11 0 0 1 18.67 19.36"/><path d="M21.61 2.68A14.5 14.5 0 0 1 21.61 21.32"/></g></svg>
            <span class="sr"><?= h($L['volume']) ?></span>
            <input type="range" min="0" max="100" step="1" value="80" data-r1="volume">
          </label>
          <button type="button" class="pillbtn" data-act="info"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 1.8a8.2 8.2 0 1 1 0 16.4 8.2 8.2 0 0 1 0-16.4zM11 10h2v7h-2zm0-3h2v2h-2z"/></svg><?= h($L['station_info']) ?></button>
        </div>
      </div>
    </section>

    <section class="picker" aria-label="<?= h($L['picker']) ?>">
      <div class="picker-head" data-js>
        <div class="crumbs" data-r1="crumbs"></div>
        <span class="tipwrap">
          <button type="button" class="qbtn" aria-label="<?= h($L['keys']) ?>" aria-describedby="r1-keys"><span class="qmark" aria-hidden="true">?</span></button>
          <span class="tip" role="tooltip" id="r1-keys">
            <span><kbd>←</kbd><kbd>→</kbd><?= h($L['key_arrows']) ?></span>
            <span><?= $L['key_digits'] ?></span>
            <span><?= $L['key_space'] ?></span>
            <span><kbd>Esc</kbd><?= h($L['key_esc']) ?></span>
          </span>
        </span>
      </div>
      <div class="scale-row" data-js>
        <button type="button" class="ic scl" data-scroll="-1" aria-label="<?= h($L['more_left']) ?>" hidden><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.7 4.3 8 12l7.7 7.7-1.4 1.4L5.2 12l9.1-9.1z"/></svg></button>
        <div class="scroller" data-r1="scroller"><div class="strip" data-r1="strip"></div></div>
        <button type="button" class="ic scl" data-scroll="1" aria-label="<?= h($L['more_right']) ?>" hidden><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.3 4.3 16 12l-7.7 7.7 1.4 1.4 9.1-9.1-9.1-9.1z"/></svg></button>
      </div>
      <h2 class="list-title"><?= h($L['all']) ?></h2>
      <ul class="list" data-r1="list" data-js></ul>
      <?= nojs_list($levels) ?>
    </section>
<?php endif; ?>
  </main>

  <!-- only without JavaScript, the menu has these -->
  <footer class="foot">
    <a href="#impressum"><?= h($L['impressum']) ?></a>
    <a href="#datenverarbeitung"><?= h($L['datenverarbeitung']) ?></a>
    <a href="<?= h($langLinks[$lang === 'de' ? 'en' : 'de']) ?>"><?= $lang === 'de' ? 'English' : 'Deutsch' ?></a>
  </footer>
</div>

<div class="mini" data-r1="mini" hidden>
  <button type="button" class="mini-open" data-act="sheet">
    <span class="tile tile-s" data-r1="art"></span>
    <span class="rn"><span class="rname" data-r1="name"></span><span class="rsub"><span class="dot" data-r1="dot" aria-hidden="true"></span><span data-r1="state"><?= h($L['idle']) ?></span></span></span>
  </button>
  <button type="button" class="ic ic-play" data-act="toggle" aria-label="<?= h($L['play']) ?>"><svg class="i-play" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.2-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg><svg class="i-pause" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg></button>
  <button type="button" class="ic ic-panel" data-act="next" aria-label="<?= h($L['next']) ?>"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 5H18v14h-2.5zM5 6.2v11.6a.9.9 0 0 0 1.4.75l7.8-5.8a.9.9 0 0 0 0-1.5L6.4 5.45A.9.9 0 0 0 5 6.2z"/></svg></button>
</div>

<dialog class="sheet" id="sheet" aria-label="Player">
  <div class="sheet-top">
    <button type="button" class="ic" data-close aria-label="<?= h($L['close_player']) ?>"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.3 8.3 12 15l6.7-6.7 1.4 1.4L12 17.8 3.9 9.7z"/></svg></button>
    <p class="sheet-level" data-r1="level"></p>
    <button type="button" class="ic" data-act="info" aria-label="<?= h($L['station_info']) ?>"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 1.8a8.2 8.2 0 1 1 0 16.4 8.2 8.2 0 0 1 0-16.4zM11 10h2v7h-2zm0-3h2v2h-2z"/></svg></button>
  </div>
  <div class="sheet-body">
    <div class="stage-art">
      <span class="tile tile-xl" data-r1="art"></span>
      <span class="numchip" data-r1="num" aria-hidden="true"></span>
    </div>
    <h2 class="disp sheet-name" data-r1="name"></h2>
    <p class="track" data-r1="track" hidden><span class="track-title" data-r1="track-title"></span><span class="track-artist" data-r1="track-artist"></span></p>
    <p class="state"><span class="state-main"><span class="dot" data-r1="dot" aria-hidden="true"></span><span data-r1="state"><?= h($L['idle']) ?></span></span><span class="pill" data-r1="pill" role="img" aria-label="<?= h($L['listening']) ?>" hidden><i></i><i></i><i></i></span></p>
    <div class="controls">
      <button type="button" class="ic" data-act="prev" aria-label="<?= h($L['prev']) ?>"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h2.5v14H6zM19 6.2v11.6a.9.9 0 0 1-1.4.75L9.8 12.75a.9.9 0 0 1 0-1.5l7.8-5.8A.9.9 0 0 1 19 6.2z"/></svg></button>
      <button type="button" class="play" data-act="toggle" aria-label="<?= h($L['play']) ?>"><svg class="i-play" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.2-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z"/></svg><svg class="i-pause" width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg></button>
      <button type="button" class="ic" data-act="next" aria-label="<?= h($L['next']) ?>"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 5H18v14h-2.5zM5 6.2v11.6a.9.9 0 0 0 1.4.75l7.8-5.8a.9.9 0 0 0 0-1.5L6.4 5.45A.9.9 0 0 0 5 6.2z"/></svg></button>
    </div>
  </div>
</dialog>

<dialog class="dialog" id="info" aria-labelledby="info-title">
  <?= $close ?>
  <div class="info-head">
    <span class="tile tile-m" data-info="art"></span>
    <div>
      <p class="dkicker"><?= h($L['station_info']) ?></p>
      <h2 class="disp dh" id="info-title" data-info="name"></h2>
    </div>
  </div>
  <p class="dp" data-info="desc"></p>
  <p class="dp dlinks" hidden>
    <a data-info="website" target="_blank" rel="noopener"></a>
  </p>
</dialog>

<dialog class="drawer" id="menu" aria-label="<?= h($L['menu']) ?>">
  <div class="drawer-bar">
    <button type="button" class="back" data-back aria-label="<?= h($L['back']) ?>" hidden><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><polyline points="11 5 4 12 11 19"/><line x1="4" y1="12" x2="20" y2="12"/></svg></button>
    <?= $close ?>
  </div>
  <nav class="drawer-nav" aria-label="<?= h($L['pages']) ?>">
    <a href="#about" data-dialog="about"><?= h($L['about']) ?></a>
    <a href="#impressum" data-dialog="impressum"><?= h($L['impressum']) ?></a>
    <a href="#datenverarbeitung" data-dialog="datenverarbeitung"><?= h($L['datenverarbeitung']) ?></a>
    <div class="drawer-lang" role="group" aria-label="<?= h($L['language']) ?>">
      <a href="<?= h($langLinks['en']) ?>" hreflang="en" lang="en" data-lang-link<?= $lang === 'en' ? ' aria-current="true"' : '' ?>>English</a>
      <a href="<?= h($langLinks['de']) ?>" hreflang="de" lang="de" data-lang-link<?= $lang === 'de' ? ' aria-current="true"' : '' ?>>Deutsch</a>
    </div>
  </nav>
  <!-- filled by app.js with the text of the dialog of the same name -->
  <section class="drawer-page" data-page aria-labelledby="menu-page-title" hidden></section>
</dialog>

<dialog class="dialog" id="about" aria-labelledby="about-title">
  <?= $close ?>
  <h2 class="disp dh" id="about-title"><?= h($L['about_title']) ?></h2>
  <div class="rich"><?= $texts['about'] ?></div>
  <!-- not editable: explains the bars, with the icon itself -->
  <p class="dnote with-pill"><span class="pill" aria-hidden="true"><i></i><i></i><i></i></span><span><?= h($L['pill_note']) ?></span></p>
</dialog>

<dialog class="dialog" id="impressum" aria-labelledby="impressum-title">
  <?= $close ?>
  <h2 class="disp dh" id="impressum-title"><?= h($L['impressum']) ?></h2>
  <div class="rich"><?= $texts['impressum'] ?></div>
</dialog>

<dialog class="dialog" id="datenverarbeitung" aria-labelledby="datenverarbeitung-title">
  <?= $close ?>
  <h2 class="disp dh" id="datenverarbeitung-title"><?= h($L['datenverarbeitung']) ?></h2>
  <div class="rich"><?= $texts['datenverarbeitung'] ?></div>
</dialog>
</body>
</html>
