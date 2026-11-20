<?php
/**
 * /streams.xspf: all active stations as an XSPF playlist, for Eter (Mac, iPad) and other
 * players that import XSPF. Same stations and order as /streams.m3u, built from the
 * database on every request; the logos as absolute addresses. An import is a snapshot:
 * a newer list means importing again, nothing syncs by itself.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    db_migrate();
    $body = xspf_playlist(public_catalog()['stations'], static fn(?string $file): ?string => logo_url($file));
} catch (Throwable $e) {
    error_log('1radioe xspf: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Gerade nicht verfügbar.\n";
    exit;
}

header('Content-Type: application/xspf+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="1radioe.xspf"');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
etag_check('"' . catalog_token() . '-' . substr(sha1($body), 0, 12) . '"');
echo $body;
