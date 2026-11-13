<?php
/**
 * /streams.m3u: all active stations as an M3U playlist for VLC and other players,
 * in admin order, built from the database on every request.
 *
 * The entries point at the original streams (playlists resolved), nothing runs through
 * this server. Listening through this file is deliberately not part of the status.
 * Until 1.12 there were also /streams.pls and /s/<smapi_id>.m3u, both answer 410 now.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

function playlist_fail(int $status, string $text): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text . "\n";
    exit;
}

try {
    db_migrate();
    $body = "#EXTM3U\n";
    foreach (public_catalog()['stations'] as $e) {
        $body .= '#EXTINF:-1,' . playlist_title($e['row']['title']) . "\n" . station_playback_url($e['row']) . "\n";
    }
} catch (Throwable $e) {
    error_log('1radioe playlist: ' . $e->getMessage());
    playlist_fail(503, 'Gerade nicht verfügbar.');
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: attachment; filename="1radioe.m3u"');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
etag_check('"' . catalog_token() . '-' . substr(sha1($body), 0, 12) . '"');
echo $body;
