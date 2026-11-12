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
        'm3u'      => 's/' . rawurlencode($r['smapi_id']) . '.m3u',
        'category' => $e['category']['smapi_id'] ?? null,
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
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:$media; media-src https: http:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    // the browser talks to the stations' servers directly; they need not know where it came from
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-cache');
}
