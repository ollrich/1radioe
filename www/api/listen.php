<?php
/**
 * POST /api/listen: a device reports the listening status.
 * Body: {"station": "<smapi_id>", "state": "on" | "off"}
 *   or  {"url": "<what the player plays>", "state": "on" | "off"}
 *
 * Only devices from the admin (System, Hörgeräte) may report, visitors never do.
 *
 * Browser (web player): with a valid listening cookie. Against reports triggered by
 * other sites: the cookie is SameSite=Lax, the Origin header must be this site, and
 * "on" also needs the header X-1radioe: 1, which a foreign form or a plain cross-site
 * request cannot set. "off" may come without it, because navigator.sendBeacon (used
 * when a tab closes) cannot send own headers.
 *
 * Key (Home Assistant, later an app): with the header X-1radioe-Token: <key>. A key is
 * shown once when it is created. Browsers never send it by themselves, so the cookie
 * checks are not needed. Home Assistant reports what a Sonos speaker plays as "url",
 * the station is found by its smapi id in a Sonos address or by its stream address
 * (station_by_play_uri). A url that is no station of 1radiœ is answered with
 * {"station": null}, so Home Assistant may report whatever plays. With the diagnostic
 * log on, every report with a key is logged.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function listen_stop(int $status, string $error = ''): never
{
    http_response_code($status);
    if ($error !== '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $error]);
    }
    exit;
}

/** scheme://host[:port] of an address, lower case. */
function listen_origin_of(string $url): string
{
    $p = parse_url($url);
    if (!isset($p['scheme'], $p['host'])) {
        return '';
    }
    return strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    listen_stop(405, 'POST only');
}
$key = trim((string) ($_SERVER['HTTP_X_1RADIOE_TOKEN'] ?? ''));
if ($key === '') {
    $origin = strtolower((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '' || $origin !== listen_origin_of(base_url(1))) {
        listen_stop(403, 'origin');
    }
}
$in      = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
$station = is_array($in) && is_string($in['station'] ?? null) ? $in['station'] : '';
$url     = is_array($in) && is_string($in['url'] ?? null) ? mb_substr(trim($in['url']), 0, 2000) : '';
$state   = is_array($in) && is_string($in['state'] ?? null) ? $in['state'] : '';
if (($station === '' && $url === '') || !in_array($state, ['on', 'off'], true)) {
    listen_stop(400, 'station or url and state on|off expected');
}
if ($key === '' && $state === 'on' && ($_SERVER['HTTP_X_1RADIOE'] ?? '') !== '1') {
    listen_stop(403, 'header');
}

try {
    db_migrate();
    $cookie = $key === '' ? (string) ($_COOKIE[LISTEN_COOKIE] ?? '') : '';
    $device = $key !== '' ? listen_device_by_token($key, 'app') : listen_device_by_token($cookie, 'browser');
    if ($device === null) {
        if ($key !== '') {
            sonos_log('listen ' . $state, '', 401, 'Unbekannter oder widerrufener Schlüssel');
        }
        listen_stop(401, $key !== '' ? 'unknown key' : 'no listening device');
    }
    $s  = $station !== '' ? station_by_smapi($station) : station_by_play_uri($url);
    $ok = $s !== null && listen_report($s, $state === 'on');
    listen_device_touch($device, $key === '' ? $cookie : null);
    if ($key !== '') {
        sonos_log('listen ' . $state, $ok ? $s['smapi_id'] : '', 200,
            ($ok ? 'Status: ' . ($state === 'on' ? 'läuft' : 'aus') : 'Kein Sender von 1radiœ') . ' · ' . $device['name'] . ($url !== '' ? ' · ' . $url : ''));
    }
    if ($station === '') {
        json_out(['station' => $ok ? $s['smapi_id'] : null]);
    }
    if (!$ok) {
        listen_stop(404, 'unknown station');
    }
    listen_stop(204);
} catch (Throwable $e) {
    error_log('1radioe listen: ' . $e->getMessage());
    listen_stop(503, 'unavailable');
}
