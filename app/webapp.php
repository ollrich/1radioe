<?php
/**
 * Data for the public web app (www/index.php). The page is rendered on the server
 * (works without JavaScript as a plain list of links) and gets the same data as JSON
 * for the player.
 */
declare(strict_types=1);

/**
 * The station tree for the web app: 'root' plus one list per category (keyed by its
 * SMAPI id). Root items are streams and categories in admin order; numbers count
 * within each level, as on the scale and in the list.
 */
function webapp_levels(): array
{
    $catalog = public_catalog(true);
    $levels  = ['root' => []];
    $root    = [];
    foreach ($catalog['stations'] as $e) {
        $item = webapp_station($e);
        if ($e['category'] === null) {
            $root[$e['sort']] = $item;
        } else {
            $levels[$e['category']['smapi_id']][] = $item;
        }
    }
    $inside = catalog_inside($catalog);
    foreach ($catalog['categories'] as $c) {
        $id    = $c['row']['smapi_id'];
        $isYt  = !empty($c['youtube']);
        if ($isYt) {
            $levels[$id] = webapp_youtube($c['row']);
        }
        // a grid of what the web app shows inside, a file of its own (category_cover)
        $cover = $isYt ? youtube_cover($c['row']) : category_cover($c['row'], $inside[(int) $c['row']['id']] ?? [], 'web');
        $root[$c['sort']] = [
            'id'     => $id,
            'type'   => 'category',
            'name'   => $c['row']['title'],
            'num'    => sprintf('%02d', $c['sort']),
            'logo'   => logo_url($cover),
            'thumb'  => logo_url(logo_thumb($cover)),
            'count'  => count($levels[$id] ?? []),
            'desc'   => (string) $c['row']['description'],
            'videos' => $isYt,   // counts videos, not stations
        ];
    }
    ksort($root);
    $levels['root'] = array_values($root);
    return $levels;
}

/** One station, SoundCloud track or Deezer link for the web app. $e: an entry of public_catalog()['stations']. */
function webapp_station(array $e): array
{
    $r = $e['row'];
    if ($r['kind'] === 'soundcloud' || $r['kind'] === 'deezer') {
        // SoundCloud plays in its widget, Deezer only opens: the address is the page there.
        // Deezer shows its "Export playlist" offer (Spotify, YouTube Music, Apple Music) only
        // with universal_link=1, which share links carry; the stored address stays plain.
        $url = $r['kind'] === 'deezer'
            ? $r['stream_url'] . (str_contains($r['stream_url'], '?') ? '&' : '?') . 'universal_link=1'
            : $r['stream_url'];
        return [
            'id'       => $r['smapi_id'],
            'type'     => $r['kind'],
            'name'     => $r['title'],
            'num'      => sprintf('%02d', $e['sort']),
            'logo'     => logo_url($r['logo']),
            'thumb'    => logo_url(logo_thumb($r['logo'])),
            'desc'     => $r['description'],
            'website'  => $url,
            'country'  => (string) ($r['country'] ?? ''),
            'city'     => (string) ($r['city'] ?? ''),
            'url'      => $url,
            'https'    => true,
            'category' => $e['category']['smapi_id'] ?? null,
        ];
    }
    $url = station_playback_url($r);
    return [
        'id'       => $r['smapi_id'],
        'type'     => 'station',
        'name'     => $r['title'],
        'num'      => sprintf('%02d', $e['sort']),
        'logo'     => logo_url($r['logo']),
        'thumb'    => logo_url(logo_thumb($r['logo'])),
        'desc'     => $r['description'],
        'website'  => (string) ($r['website'] ?? ''),
        'country'  => (string) ($r['country'] ?? ''),
        'city'     => (string) ($r['city'] ?? ''),
        'url'      => $url,
        'https'    => is_https_url($url),
        // HLS as found by the stream test, also for addresses without .m3u8 (app.js, hls.js)
        'hls'      => $r['mime'] === 'application/vnd.apple.mpegURL',
        'category' => $e['category']['smapi_id'] ?? null,
    ];
}

/**
 * The videos of a YouTube playlist for the web app, in its order. They play in YouTube's
 * player (app.js); the picture is the 16:9 one, tiles cut a square out of it.
 */
function webapp_youtube(array $row): array
{
    $out = [];
    foreach (youtube_videos((int) $row['id']) as $i => $v) {
        $url = youtube_watch_url((string) $v['video_id']);
        $out[] = [
            'id'       => 'yt.' . $v['video_id'],   // a dot never occurs in the ids of stations
            'type'     => 'youtube',
            'video'    => (string) $v['video_id'],
            'name'     => $v['title'],
            'num'      => sprintf('%02d', $i + 1),
            'logo'     => logo_url($v['image']),
            'thumb'    => $v['image'] ? logo_url(logo_thumb_name((string) $v['image'])) : null,
            'desc'     => implode(' · ', array_filter([(string) $v['channel'], youtube_length((int) $v['duration'])])),
            'channel'  => (string) $v['channel'],   // while it plays, the time runs beside it (app.js)
            'length'   => (int) $v['duration'],
            'website'  => $url,
            'country'  => '',
            'city'     => '',
            'url'      => $url,
            'https'    => true,
            'category' => $row['smapi_id'],
        ];
    }
    return $out;
}

/**
 * Language of the page: German under /de/, English otherwise (1radioe.net).
 * Everything of the page is translated, the texts from the admin per language;
 * only the station descriptions exist once and stay as they are.
 */
function webapp_lang(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return $path === '/de' || str_starts_with($path, '/de/') ? 'de' : 'en';
}

/** True if the browser's first language is German (Accept-Language). */
function webapp_prefers_german(): bool
{
    $best = '';
    $bestQ = -1.0;
    foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $bits = explode(';', trim($part));
        $tag  = strtolower(trim($bits[0]));
        if ($tag === '' || $tag === '*') {
            continue;
        }
        $q = 1.0;
        foreach (array_slice($bits, 1) as $param) {
            if (preg_match('~^\s*q=([0-9.]+)~', $param, $m)) {
                $q = (float) $m[1];
            }
        }
        if ($q > $bestQ) {   // equal q: the first one wins, as browsers list them in order
            $best  = $tag;
            $bestQ = $q;
        }
    }
    return $bestQ > 0 && ($best === 'de' || str_starts_with($best, 'de-'));
}

function webapp_strings(string $lang): array
{
    $de = [
        'title'       => '1radiœ · Ollis Lieblingsmusik',
        'description' => 'Ollis persönliche Auswahl an Internetradios, von FIP bis BBC Radio 1, dazu DJ-Mixe und Konzerte. Alles im Browser, das Radio auch auf Sonos und in VLC. Ohne Tracking.',
        'h1'          => '1radiœ: Ollis Lieblingsmusik',
        'menu'        => 'Menü',
        'menu_open'   => 'Menü öffnen',
        'pages'       => 'Seiten',
        'close'       => 'Schließen',
        'back'        => 'Zurück zur Übersicht',
        'about'       => 'Über',
        'about_title' => 'Über 1radiœ',
        'impressum'   => 'Impressum',
        'datenverarbeitung' => 'Datenverarbeitung',
        'language'    => 'Sprache',
        'pill_note'   => 'Die kleinen Balken an einem Sender zeigen, dass ich ihn gerade höre.',
        'listening'   => 'Olli hört gerade',
        'listening_to' => 'Olli hört gerade',
        'last_heard'  => 'Zuletzt von Olli gehört',
        'and'         => 'und',
        'listen_on'   => 'Hören wird erfasst',
        'listen_title' => 'Nur für dich sichtbar: Dein Hören in diesem Browser wird als Hörstatus erfasst (%s).',
        'origin'      => 'Herkunft',
        'sc_title'    => 'Spielt über SoundCloud',
        'sc_text'     => 'Der Player von SoundCloud lädt erst nach deinem Klick. Dann setzt SoundCloud Cookies und erfährt deine IP-Adresse.',
        'sc_more'     => 'Mehr dazu',
        'sc_load'     => 'SoundCloud laden',
        'yt_title'    => 'Spielt über YouTube',
        'yt_text'     => 'Das Video lädt erst nach deinem Klick, von youtube-nocookie.com. Dann erfährt Google deine IP-Adresse und kann Daten in deinem Browser speichern. Beim Abspielen gelten die Nutzungsbedingungen von YouTube.',
        'yt_terms'    => 'Nutzungsbedingungen',
        'yt_load'     => 'YouTube laden',
        'dz_kicker'   => 'Playlist bei Deezer',
        'dz_text'     => 'Diese Playlist spielt nicht hier, sondern bei Deezer. Der Button öffnet sie in einem neuen Tab oder in der Deezer-App.',
        'dz_transfer' => 'Hörst du mit einem anderen Dienst? Bei Deezer kannst du die Playlist auch in deine Musik-App exportieren, etwa zu Spotify, YouTube Music oder Apple Music.',
        'dz_go'       => 'Zu Deezer',
        'unreachable' => 'Die Sender sind gerade nicht erreichbar. Bitte später noch einmal versuchen.',
        'no_stations' => 'Noch keine Sender.',
        'idle'        => 'Bereit',
        'prev'        => 'Vorheriger Sender',
        'play'        => 'Abspielen',
        'next'        => 'Nächster Sender',
        'volume'      => 'Lautstärke',
        'station_info' => 'Über den Sender',
        'picker'      => 'Senderauswahl',
        'keys'        => 'Tastaturkürzel',
        'key_arrows'  => 'Sender zurück und vor',
        'key_digits'  => '<kbd>1</kbd>bis <kbd>0</kbd>Sender direkt wählen, sobald du im Player oder in der Senderauswahl bist',
        'home'        => '1radiœ, Startseite',
        'key_space'   => '<kbd>Leertaste</kbd>Play und Pause',
        'key_esc'     => 'zurück zu allen Sendern',
        'more_left'   => 'Weitere Sender links',
        'more_right'  => 'Weitere Sender rechts',
        'all'         => 'Alle Sender',
        'close_player' => 'Player schließen',
    ];
    $en = [
        'title'       => "1radiœ · Ollie's favorite music",
        'description' => "Ollie's personal selection of internet radio stations, from FIP to BBC Radio 1, plus DJ mixes and concerts. All in the browser, the radio also on Sonos and in VLC. No tracking.",
        'h1'          => "1radiœ: Ollie's favorite music",
        'menu'        => 'Menu',
        'menu_open'   => 'Open menu',
        'pages'       => 'Pages',
        'close'       => 'Close',
        'back'        => 'Back to the menu',
        'about'       => 'About',
        'about_title' => 'About 1radiœ',
        'impressum'   => 'Legal notice',
        'datenverarbeitung' => 'Data processing',
        'language'    => 'Language',
        'pill_note'   => "The small bars on a station show that I'm listening to it right now.",
        'listening'   => 'Ollie is listening',
        'listening_to' => 'Ollie is listening to',
        'last_heard'  => 'Last heard by Ollie',
        'and'         => 'and',
        'listen_on'   => 'Listening is recorded',
        'listen_title' => 'Only visible to you: listening in this browser counts as the listening status (%s).',
        'origin'      => 'Origin',
        'sc_title'    => 'Plays via SoundCloud',
        'sc_text'     => "SoundCloud's player loads only after your click. SoundCloud then sets cookies and learns your IP address.",
        'sc_more'     => 'More about it',
        'sc_load'     => 'Load SoundCloud',
        'yt_title'    => 'Plays via YouTube',
        'yt_text'     => "The video loads only after your click, from youtube-nocookie.com. Google then learns your IP address and may store data in your browser. Playing it means agreeing to YouTube's Terms of Service.",
        'yt_terms'    => 'Terms of Service',
        'yt_load'     => 'Load YouTube',
        'dz_kicker'   => 'Playlist on Deezer',
        'dz_text'     => 'This playlist does not play here but on Deezer. The button opens it in a new tab or in the Deezer app.',
        'dz_transfer' => 'Using another service? On Deezer you can also export the playlist to your music app, such as Spotify, YouTube Music or Apple Music.',
        'dz_go'       => 'Open Deezer',
        'unreachable' => 'The stations cannot be reached right now. Please try again later.',
        'no_stations' => 'No stations yet.',
        'idle'        => 'Ready',
        'prev'        => 'Previous station',
        'play'        => 'Play',
        'next'        => 'Next station',
        'volume'      => 'Volume',
        'station_info' => 'About the station',
        'picker'      => 'Choose a station',
        'keys'        => 'Keyboard shortcuts',
        'key_arrows'  => 'Previous and next station',
        'key_digits'  => '<kbd>1</kbd>to <kbd>0</kbd>Pick a station directly, once you are in the player or the station picker',
        'home'        => '1radiœ, home',
        'key_space'   => '<kbd>Space</kbd>Play and pause',
        'key_esc'     => 'Back to all stations',
        'more_left'   => 'More stations to the left',
        'more_right'  => 'More stations to the right',
        'all'         => 'All stations',
        'close_player' => 'Close player',
    ];
    return $lang === 'de' ? $de : $en;
}

/** Texts the script builds itself, in the page's language. {name} and {n} are filled in there. */
function webapp_script_strings(string $lang): array
{
    if ($lang === 'de') {
        return [
            'phase'    => ['idle' => 'Bereit', 'connecting' => 'Verbindet', 'live' => 'Live', 'paused' => 'Pausiert', 'error' => 'Keine Verbindung'],
            'all'      => 'Alle Sender',
            'category' => 'Kategorie {name}',
            'count1'   => '1 Sender',
            'countN'   => '{n} Sender',
            'holdsCur' => 'enthält den aktuellen Sender',
            'play'     => 'Abspielen',
            'pause'    => 'Pause',
            'noDesc'   => 'Keine Beschreibung.',
            'website'  => 'Website: {host}',
            'listenOn' => 'Hören wird erfasst',
            'listenOff' => 'Erfassung pausiert',
            'scPlaying' => 'Spielt',
            'scFrame'   => 'Player von SoundCloud',
            'ytFrame'   => 'Player von YouTube',
            'ytGone'    => 'Video nicht verfügbar',
            'videos1'   => '1 Video',
            'videosN'   => '{n} Videos',
        ];
    }
    return [
        'phase'    => ['idle' => 'Ready', 'connecting' => 'Connecting', 'live' => 'Live', 'paused' => 'Paused', 'error' => 'No connection'],
        'all'      => 'All stations',
        'category' => 'Category {name}',
        'count1'   => '1 station',
        'countN'   => '{n} stations',
        'holdsCur' => 'contains the current station',
        'play'     => 'Play',
        'pause'    => 'Pause',
        'noDesc'   => 'No description.',
        'website'  => 'Website: {host}',
        'listenOn' => 'Listening is recorded',
        'listenOff' => 'Recording paused',
        'scPlaying' => 'Playing',
        'scFrame'   => 'SoundCloud player',
        'ytFrame'   => 'YouTube player',
        'ytGone'    => 'Video not available',
        'videos1'   => '1 video',
        'videosN'   => '{n} videos',
    ];
}

/** JSON that is safe inside a <script type="application/json"> block. */
function webapp_json(array $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Security and privacy headers of the public page. */
function webapp_headers(): void
{
    $media = media_origin(0);
    // frame-src: the players of SoundCloud and YouTube, loaded only after a click in the web app (see app.js)
    // connect-src https: hls.js (Firefox, HLS stations such as BBC Radio 1) fetches the
    // playlist and the audio parts of a stream from the station's servers; 'self' alone
    // would silence them (#74)
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:$media; media-src https: http: blob:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self' https:; frame-src https://w.soundcloud.com https://www.youtube-nocookie.com; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    // the browser talks to the stations' servers directly; they need not know where it came from
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-cache');
}
