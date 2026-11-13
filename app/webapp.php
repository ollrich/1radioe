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
    $catalog = public_catalog();
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
    $rows = $catalog['categories'] ? stations_all(true) : [];
    foreach ($catalog['categories'] as $c) {
        $id = $c['row']['smapi_id'];
        $root[$c['sort']] = [
            'id'    => $id,
            'type'  => 'category',
            'name'  => $c['row']['title'],
            'num'   => sprintf('%02d', $c['sort']),
            'logo'  => logo_url(category_cover($c['row'], $rows)),
            'count' => count($levels[$id] ?? []),
        ];
    }
    ksort($root);
    $levels['root'] = array_values($root);
    return $levels;
}

/** One station for the web app. $e: an entry of public_catalog()['stations']. */
function webapp_station(array $e): array
{
    $r   = $e['row'];
    $url = station_playback_url($r);
    return [
        'id'       => $r['smapi_id'],
        'type'     => 'station',
        'name'     => $r['title'],
        'num'      => sprintf('%02d', $e['sort']),
        'logo'     => logo_url($r['logo']),
        'desc'     => $r['description'],
        'website'  => (string) ($r['website'] ?? ''),
        'url'      => $url,
        'https'    => is_https_url($url),
        'category' => $e['category']['smapi_id'] ?? null,
    ];
}

/**
 * Language of the page: German under /de/, English otherwise (1radioe.net).
 * Only the frame is translated: menu, titles, the texts from the admin and the info at
 * the bars. Status, buttons and station descriptions stay as they are for now.
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
        'description' => 'Ollis Radiosender: hören im Browser, auf Sonos und in VLC.',
        'h1'          => '1radiœ: Ollis Radiosender',
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
        'pill_note'   => 'Die kleinen Balken an einem Sender zeigen, dass ich ihn gerade höre. Mehr wird dabei nicht erfasst.',
        'listening'   => 'Olli hört gerade',
        'listening_to' => 'Olli hört gerade',
        'and'         => 'und',
    ];
    $en = [
        'description' => "Ollie's radio stations: listen in the browser, on Sonos and in VLC.",
        'h1'          => "1radiœ: Ollie's radio stations",
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
        'pill_note'   => "The small bars on a station show that I'm listening to it right now. Nothing more is recorded.",
        'listening'   => 'Ollie is listening',
        'listening_to' => 'Ollie is listening to',
        'and'         => 'and',
    ];
    return $lang === 'de' ? $de : $en;
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
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:$media; media-src https: http: blob:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self' https:; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    // the browser talks to the stations' servers directly; they need not know where it came from
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-cache');
}
