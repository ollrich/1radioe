<?php
/**
 * POST /api/listen: the web player reports the listening status.
 * Body: {"station": "<smapi_id>", "state": "on" | "off"}
 *
 * Only a browser with a valid listening cookie may report; visitors never do. Against
 * reports triggered by other sites: the cookie is SameSite=Lax, the Origin header must
 * be this site, and "on" also needs the header X-1radioe: 1, which a foreign form or a
 * plain cross-site request cannot set. "off" may come without it, because
 * navigator.sendBeacon (used when a tab closes) cannot send own headers.
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
$origin = strtolower((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin === '' || $origin !== listen_origin_of(base_url(1))) {
    listen_stop(403, 'origin');
}
$in      = json_decode((string) file_get_contents('php://input', false, null, 0, 2048), true);
$station = is_array($in) ? (string) ($in['station'] ?? '') : '';
$state   = is_array($in) ? (string) ($in['state'] ?? '') : '';
if ($station === '' || !in_array($state, ['on', 'off'], true)) {
    listen_stop(400, 'station and state on|off expected');
}
if ($state === 'on' && ($_SERVER['HTTP_X_1RADIOE'] ?? '') !== '1') {
    listen_stop(403, 'header');
}

try {
    db_migrate();
    $token  = (string) ($_COOKIE[LISTEN_COOKIE] ?? '');
    $device = listen_device_by_token($token, 'browser');
    if ($device === null) {
        listen_stop(401, 'no listening device');
    }
    $s = station_by_smapi($station);
    if ($s === null || !listen_report($s, $state === 'on')) {
        listen_stop(404, 'unknown station');
    }
    listen_device_touch($device, $token);
    listen_stop(204);
} catch (Throwable $e) {
    error_log('1radioe listen: ' . $e->getMessage());
    listen_stop(503, 'unavailable');
}
