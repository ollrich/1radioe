<?php
/**
 * GET /api/stations.json: all active stations for an own app.
 *
 * Only streams, no tracks and no MP3 playlists. "version" is the catalog marker that
 * changes with every change in the admin; together with the resolved stream addresses
 * it makes the ETag, so a client can ask with If-None-Match and gets 304 when
 * nothing changed. Field names are documented in the README.
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
    $url = logo_url($file, 1);
    if ($url === null) {
        return null;
    }
    $path = media_dir() . '/' . basename((string) $file);
    return ['url' => $url, 'sha256' => hash_file('sha256', $path) ?: null, 'size' => LOGO_SIZE];
}

try {
    db_migrate();
    $catalog = public_catalog();
    $streams = [];
    foreach ($catalog['stations'] as $e) {
        $streams[$e['row']['smapi_id']] = station_playback_url($e['row']);
    }
    $version = catalog_token();
    // everything else is covered by the catalog marker; resolved playlists can change on their own
    header('Cache-Control: no-cache');
    etag_check('"' . $version . '-' . substr(sha1(json_encode($streams)), 0, 12) . '"');

    $base = base_url(1);
    $rows = stations_all(true);
    json_out([
        'version'    => $version,
        'generated'  => date(DATE_ATOM),
        'status_url' => $base . '/api/status',
        'ping_url'   => $base . '/api/ping',
        'categories' => array_map(static fn($c) => [
            'id'   => $c['row']['smapi_id'],
            'name' => $c['row']['title'],
            'sort' => $c['sort'],
            'logo' => json_logo(category_cover($c['row'], $rows)),
        ], $catalog['categories']),
        'stations'   => array_map(static fn($e) => [
            'id'          => $e['row']['smapi_id'],
            'name'        => $e['row']['title'],
            'description' => $e['row']['description'],
            'website'     => (string) ($e['row']['website'] ?? ''),
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
