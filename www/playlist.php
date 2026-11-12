<?php
/**
 * Playlist files for VLC and other players, public:
 *
 *   /streams.m3u, /streams.pls   all active stations in admin order
 *   /s/<smapi_id>.m3u            a single station
 *
 * The entries point at the original streams (playlists resolved), nothing runs through
 * this server. Listening through these files is deliberately not part of the status.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$format = (string) ($_GET['format'] ?? 'm3u');
$single = isset($_GET['id']) ? (string) $_GET['id'] : null;

function playlist_fail(int $status, string $text): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text . "\n";
    exit;
}

if (!in_array($format, ['m3u', 'pls'], true) || ($single !== null && $format !== 'm3u')) {
    playlist_fail(404, 'Nicht gefunden.');
}
try {
    db_migrate();
    $entries = public_catalog()['stations'];
    if ($single !== null) {
        $entries = array_values(array_filter($entries, static fn($e) => $e['row']['smapi_id'] === $single));
        if (!$entries) {
            playlist_fail(404, 'Sender nicht gefunden.');
        }
    }
    $items = array_map(static fn($e) => ['title' => playlist_title($e['row']['title']), 'url' => station_playback_url($e['row'])], $entries);
} catch (Throwable $e) {
    error_log('1radioe playlist: ' . $e->getMessage());
    playlist_fail(503, 'Gerade nicht verfügbar.');
}

if ($format === 'm3u') {
    $body = "#EXTM3U\n";
    foreach ($items as $it) {
        $body .= '#EXTINF:-1,' . $it['title'] . "\n" . $it['url'] . "\n";
    }
    $type = 'audio/x-mpegurl';
} else {
    $body = "[playlist]\n";
    foreach ($items as $i => $it) {
        $n     = $i + 1;
        $body .= "File{$n}={$it['url']}\nTitle{$n}={$it['title']}\nLength{$n}=-1\n";
    }
    $body .= 'NumberOfEntries=' . count($items) . "\nVersion=2\n";
    $type = 'audio/x-scpls';
}

$name = ($single ?? '1radioe') . '.' . $format;
header('Content-Type: ' . $type . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
etag_check('"' . catalog_token() . '-' . substr(sha1($body), 0, 12) . '"');
echo $body;
