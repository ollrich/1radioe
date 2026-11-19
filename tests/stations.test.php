<?php
/**
 * Which station a player plays (listening status from Home Assistant and report.php):
 * by stream address, also the stream a playlist resolved to (#70), and by title.
 * Saving an order with doubles (#75).
 */
declare(strict_types=1);

$a = station_save(['kind' => 'stream', 'title' => 'Alpha FM', 'stream_url' => 'https://a.example/live.mp3?token=1', 'mime' => 'audio/mpeg', 'active' => 1]);
$b = station_save(['kind' => 'stream', 'title' => 'Beta Radio', 'stream_url' => 'https://b.example/radio.pls', 'mime' => 'audio/mpeg', 'active' => 1]);
$c = station_save(['kind' => 'stream', 'title' => 'Gamma', 'stream_url' => 'https://c.example/c.aac', 'mime' => 'audio/mpeg', 'active' => 1]);
$d = station_save(['kind' => 'stream', 'title' => 'Delta', 'stream_url' => 'https://d.example/off.mp3', 'mime' => 'audio/mpeg', 'active' => 0]);
check('playlist recognised', (int) $b['is_playlist'], 1);
// the stream the playlist of Beta resolved to, as playlist_resolve() stores it
q('INSERT INTO r1_playlist_cache (url_hash, stream, until_ts) VALUES (?, ?, ?)',
    [hash('sha256', 'https://b.example/radio.pls'), 'https://cdn.b.example/beta?sid=7', time() + 3600]);

$by = static fn(string $uri): ?string => station_by_play_uri($uri)['title'] ?? null;
check('same address and query', $by('https://a.example/live.mp3?token=1'), 'Alpha FM');
check('same address, other query', $by('https://a.example/live.mp3?token=2'), 'Alpha FM');
check('http counts as https', $by('http://a.example/live.mp3?token=1'), 'Alpha FM');
check('Sonos prefix', $by('x-rincon-mp3radio://https://c.example/c.aac'), 'Gamma');
check('stream of a playlist', $by('https://cdn.b.example/beta?sid=7'), 'Beta Radio');
check('stream of a playlist, other query', $by('https://cdn.b.example/beta?sid=8'), 'Beta Radio');
check('the playlist itself', $by('https://b.example/radio.pls'), 'Beta Radio');
check('Sonos service id', $by('x-sonosapi-stream:' . rawurlencode((string) $c['smapi_id']) . '?sid=254&flags=8224'), 'Gamma');
check('inactive station', $by('https://d.example/off.mp3'), null);
check('unknown address', $by('https://elsewhere.example/x.mp3'), null);
check('not an address', $by('hello'), null);

$title = static fn(string $t): ?string => station_by_title($t)['title'] ?? null;
check('title', $title('Gamma'), 'Gamma');
check('title, other case', $title('beta radio'), 'Beta Radio');
check('title as Sonos shows it', $title('Alpha FM • Die Beschreibung'), 'Alpha FM');
check('title of an inactive station', $title('Delta'), null);
check('empty title', $title(''), null);

// an order with a double: each station once, at its first place
stations_reorder([(int) $c['id'], (int) $a['id'], (int) $c['id'], (int) $b['id']]);
$sort = static fn(array $s): int => (int) station_find((int) $s['id'])['sort'];
check('order with a double', [$sort($c), $sort($a), $sort($b)], [1, 2, 3]);
