<?php
/**
 * GET /api/status: which stations Ollie is listening to right now.
 * {"active": ["<smapi_id>", ...], "ttl": 150}
 * Public and uncached. Says nothing about device, source or place, only the stations.
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
    json_out(['active' => listen_active(), 'ttl' => LISTEN_TTL]);
} catch (Throwable $e) {
    error_log('1radioe status: ' . $e->getMessage());
    json_out(['active' => [], 'ttl' => LISTEN_TTL, 'error' => 'unavailable'], 503);
}
