<?php
/**
 * GET /api/stations.json: all active stations for an own app.
 * ?profile=cathy: Cathy's list (app/profiles.php), without a key; an unknown profile is 400.
 *
 * Only streams, no tracks and no MP3 playlists. "version" is the catalog marker that
 * changes with every change in the admin; together with the resolved stream addresses
 * it makes the ETag, so a client can ask with If-None-Match and gets 304 when
 * nothing changed. Field names are documented in API.md.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    json_out(['error' => 'GET only'], 405);
}

/** Logo block for the JSON, or null. The file name changes with every new logo. */
function json_logo(?string $file): ?array
{
    return json_picture($file);
}

$profile = profile_from_query($_GET['profile'] ?? null);
if ($profile === null) {
    json_out(['error' => 'unknown profile', 'profiles' => array_keys(PROFILES)], 400);
}

try {
    db_migrate();
    // the MP3 playlists take their places in the same counting (positions only, the
    // playlists themselves come from /api/playlists)
    $catalog = public_catalog(false, $profile, true);
    $streams = [];
    foreach ($catalog['stations'] as $e) {
        $streams[$e['row']['smapi_id']] = station_playback_url($e['row']);
    }
    $version = catalog_token();
    // everything else is covered by the catalog marker; resolved playlists can change on their own
    header('Cache-Control: no-cache');
    // the format number changes the ETag once when the fields change, so apps that saved the
    // old answer load the new one instead of getting 304 (2: categories carry a description,
    // 3: positions counted together with the MP3 playlists); the profile is part of it, so a
    // switch of profiles never gets the other list's 304
    $format = 3;
    etag_check('"' . $version . '-' . $format . '-' . $profile . '-' . substr(sha1(json_encode($streams)), 0, 12) . '"');

    $base = base_url(1);
    $rows = stations_all(true);
    json_out([
        'version'    => $version,
        'profile'    => $profile,
        'generated'  => date(DATE_ATOM),
        'status_url' => $base . '/api/status',
        'listen_url' => $base . '/api/listen',
        'nowplaying_url' => $base . '/api/nowplaying?id={id}',
        'categories' => array_map(static fn($c) => [
            'id'          => $c['row']['smapi_id'],
            'name'        => $c['row']['title'],
            'description' => (string) ($c['row']['description'] ?? ''),
            'sort'        => $c['sort'],
            'logo'        => json_logo(category_cover($c['row'], $rows)),
        ], $catalog['categories']),
        'stations'   => array_map(static fn($e) => [
            'id'          => $e['row']['smapi_id'],
            'name'        => $e['row']['title'],
            'description' => $e['row']['description'],
            'website'     => (string) ($e['row']['website'] ?? ''),
            'country'     => ($e['row']['country'] ?? '') !== '' ? $e['row']['country'] : null,
            'city'        => ($e['row']['city'] ?? '') !== '' ? $e['row']['city'] : null,
            'category'    => $e['category']['smapi_id'] ?? null,
            'sort'        => $e['sort'],
            'streams'     => [[
                'url'    => $streams[$e['row']['smapi_id']],
                'format' => $e['row']['mime'],
                'https'  => is_https_url($streams[$e['row']['smapi_id']]),
            ]],
            'logo'        => json_logo($e['row']['logo']),
        ], $catalog['stations']),
    ], 200, false);
} catch (Throwable $e) {
    error_log('1radioe stations.json: ' . $e->getMessage());
    json_out(['error' => 'unavailable'], 503);
}
