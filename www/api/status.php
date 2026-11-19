<?php
/**
 * GET /api/status: which stations Ollie is listening to right now, and the one he heard
 * last (a session of five minutes or more that is over), for "Zuletzt von Olli gehört".
 * {"active": ["<smapi_id>", ...], "last": "<smapi_id>" or null, "ttl": 150}
 * Public and uncached. Says nothing about device, source, place or time, only the stations.
 * Also starts the daily test of a few streams, after the response.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    json_out(['error' => 'GET only'], 405);
}
try {
    db_migrate();
    stations_check_due();
    json_out(['active' => listen_active(), 'last' => listen_last(), 'ttl' => LISTEN_TTL]);
} catch (Throwable $e) {
    error_log('1radioe status: ' . $e->getMessage());
    json_out(['active' => [], 'last' => null, 'ttl' => LISTEN_TTL, 'error' => 'unavailable'], 503);
}
