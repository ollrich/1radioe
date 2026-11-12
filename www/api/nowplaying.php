<?php
/**
 * GET /api/nowplaying?id=<smapi_id>: the song a station is playing right now,
 * read from the stream's ICY metadata.
 *
 *   {"id": "fip", "track": {"artist": "Nina Simone", "title": "Sinnerman"}}
 *   {"id": "fip", "track": null}     the stream sends no title
 *
 * The web player asks while it plays. Answers are cached for a few seconds here and
 * in the browser, so many listeners cause one read of the stream at most.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    json_out(['error' => 'GET only'], 405);
}
$id = isset($_GET['id']) && is_string($_GET['id']) && preg_match('~^[A-Za-z0-9._-]{1,64}$~', $_GET['id']) ? $_GET['id'] : '';
if ($id === '') {
    json_out(['error' => 'id expected'], 400);
}

try {
    db_migrate();
    $station = station_by_smapi($id);
    if ($station === null || $station['kind'] !== 'stream' || empty($station['active'])) {
        json_out(['error' => 'unknown station'], 404);
    }
    $track = nowplaying($station);
} catch (Throwable $e) {
    error_log('1radioe nowplaying: ' . $e->getMessage());
    json_out(['id' => $id, 'track' => null], 503);
}

header('Cache-Control: public, max-age=' . (NOWPLAYING_TTL - 5));
json_out(['id' => $id, 'track' => $track], 200, false);
