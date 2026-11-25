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

// ---------- the daily test of the streams ----------
$at = static fn(array $s): ?string => station_find((int) $s['id'])['checked_at'];
$setAt = static fn(array $s, ?int $time, ?int $ok) => q('UPDATE r1_stations SET checked_at = ?, check_ok = ? WHERE id = ?',
    [$time === null ? null : date('Y-m-d H:i:s', $time), $ok, (int) $s['id']]);
foreach ([$a, $b, $c] as $s) {
    $setAt($s, null, null);
}
$now = time();
$setAt($a, $now - 3 * 86400, 1);   // tested three days ago
$first = stations_check_claim($now);
check('never tested first, then the oldest', array_keys($first), [(int) $b['id'], (int) $c['id'], (int) $a['id']]);
check('claimed with its address', $first[(int) $a['id']] ?? null, 'https://a.example/live.mp3?token=1');
check('inactive streams not tested', isset($first[(int) $d['id']]), false);
check('claiming marks the time', $at($a) !== null && strtotime((string) $at($a)) >= $now - 5, true);
check('nothing due right after', stations_check_claim($now), []);
// a working stream again after a day, a failed one after two hours
$setAt($a, $now - 3 * 3600, 1);
$setAt($b, $now - 3 * 3600, 0);
$setAt($c, $now - 3600, 0);
check('due: failed for three hours', array_keys(stations_check_claim($now)), [(int) $b['id']]);
$setAt($a, $now - 25 * 3600, 1);
check('due: working, tested a day ago', array_keys(stations_check_claim($now)), [(int) $a['id']]);
$extra = station_save(['kind' => 'stream', 'title' => 'Epsilon', 'stream_url' => 'https://e.example/e.mp3', 'mime' => 'audio/mpeg', 'active' => 1]);
foreach ([$a, $b, $c, $extra] as $s) {
    $setAt($s, $now - 3 * 86400, 1);
}
check('at most three at once', count(stations_check_claim($now)), 3);
check('the fourth next time', array_keys(stations_check_claim($now)), [(int) $extra['id']]);
station_delete((int) $extra['id']);

// a test that fails once is repeated, only a second failure counts
$answers = [['ok' => false, 'info' => 'Zeitüberschreitung'], ['ok' => true, 'info' => 'MP3, 128 kbit/s', 'https' => true]];
$probe   = static function () use (&$answers): array {
    return array_shift($answers) ?? ['ok' => false, 'info' => 'leer'];
};
station_check_run((int) $c['id'], 'https://c.example/c.aac', $probe);
$sc = station_find((int) $c['id']);
check('hiccup: second test counts', [(int) $sc['check_ok'], $sc['check_info'], (int) $sc['https_ok']], [1, 'MP3, 128 kbit/s', 1]);
$answers = [['ok' => false, 'info' => 'Zeitüberschreitung'], ['ok' => false, 'info' => 'HTTP 404']];
station_check_run((int) $c['id'], 'https://c.example/c.aac', $probe);
$sc = station_find((int) $c['id']);
check('broken: red with the reason', [(int) $sc['check_ok'], $sc['check_info']], [0, 'HTTP 404']);
$answers = [['ok' => true, 'info' => 'alte Adresse']];
check('address changed meanwhile: nothing stored', station_check_run((int) $c['id'], 'https://c.example/old.aac', $probe), null);
check('address changed meanwhile: result kept', station_find((int) $c['id'])['check_info'], 'HTTP 404');

// ---------- "Zuletzt von Olli gehört" ----------
$session = static function (array $s, int $since, int $last, int $stopped): void {
    q('INSERT INTO r1_listen_status (station_id, last_seen, stopped, since, posted_since) VALUES (?, ?, ?, ?, 0)
       ON DUPLICATE KEY UPDATE last_seen = ?, stopped = ?, since = ?', [(int) $s['id'], $last, $stopped, $since, $last, $stopped, $since]);
};
check('nothing heard yet', listen_last(), null);
$session($a, $now - 7200, $now - 3600, 1);           // an hour long, two hours ago
check('a finished session', listen_last(), $a['smapi_id']);
$session($b, $now - 1900, $now - 1800, 1);           // under two minutes: zapping
check('zapping does not count', listen_last(), $a['smapi_id']);
$session($c, $now - 1200, $now - 30, 0);             // playing right now
check('playing now does not count', listen_last(), $a['smapi_id']);
$session($c, $now - 1200, $now - 600, 0);            // no report for ten minutes: over
check('timed out counts', listen_last(), $c['smapi_id']);
$session($d, $now - 1200, $now - 60, 1);             // inactive station
check('inactive station does not count', listen_last(), $c['smapi_id']);
q('DELETE FROM r1_listen_status');

// ---------- ids: readable, unique, never one the system uses itself (#97) ----------
$root   = station_save(['kind' => 'category', 'title' => 'Root', 'active' => 1]);
$inRoot = station_save(['kind' => 'stream', 'title' => 'In Root', 'stream_url' => 'https://r.example/r.mp3', 'mime' => 'audio/mpeg', 'active' => 1, 'category_id' => (int) $root['id']]);
$alpha2 = station_save(['kind' => 'stream', 'title' => 'Alpha FM', 'stream_url' => 'https://a2.example/a.mp3', 'mime' => 'audio/mpeg', 'active' => 1]);
check('"Root" is not the top level', $root['smapi_id'], 'root-2');
check('a taken id gets a number', $alpha2['smapi_id'], 'alpha-fm-2');
$levels = webapp_levels();
check('its stations in the web app', array_column($levels['root-2'] ?? [], 'id'), [$inRoot['smapi_id']]);
check('the top level stays the top level', in_array('root-2', array_column($levels['root'], 'id'), true), true);
check('in Sonos', array_column(sonos_visible(stations_all(true), playlist_stats(), (int) $root['id']), 'smapi_id'), [$inRoot['smapi_id']]);
foreach ([$inRoot, $root, $alpha2] as $s) {
    station_delete((int) $s['id']);
}
