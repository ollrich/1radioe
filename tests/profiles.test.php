<?php
/**
 * Profiles (app/profiles.php): Olli's list is the public one (web app, Sonos, playlist
 * files, stations.json without a profile), Cathy's exists only for the app. MP3
 * playlists for the app (/api/playlists) in the same counting as the stations.
 */
declare(strict_types=1);

check('profile: none is Olli', profile_from_query(null), 'olli');
check('profile: empty is Olli', profile_from_query(''), 'olli');
check('profile: cathy', profile_from_query('cathy'), 'cathy');
check('profile: unknown', profile_from_query('Cathy'), null);
check('profile: not a string', profile_from_query(['cathy']), null);

// start clean: only this test's entries
foreach (q("SELECT id FROM r1_stations WHERE kind IN ('stream', 'playlist', 'category')")->fetchAll(PDO::FETCH_COLUMN) as $old) {
    station_delete((int) $old);
}
$save = static fn(array $in): array => station_save($in + ['mime' => 'audio/mpeg', 'active' => 1]);
$both   = $save(['kind' => 'stream', 'title' => 'Für beide', 'stream_url' => 'https://both.example/s.mp3', 'show_olli' => 1, 'show_cathy' => 1]);
$olli   = $save(['kind' => 'stream', 'title' => 'Nur Olli', 'stream_url' => 'https://olli.example/s.mp3']);
$cathy  = $save(['kind' => 'stream', 'title' => 'Nur Cathy', 'stream_url' => 'https://cathy.example/s.mp3', 'show_olli' => 0, 'show_cathy' => 1]);
$cat    = $save(['kind' => 'category', 'title' => 'Mixe']);
$inCat  = $save(['kind' => 'stream', 'title' => 'Cathy in Mixe', 'stream_url' => 'https://incat.example/s.mp3', 'show_olli' => 0, 'show_cathy' => 1, 'category_id' => (int) $cat['id']]);

check('default: Olli only', [(int) $olli['show_olli'], (int) $olli['show_cathy']], [1, 0]);
check('saved for Cathy alone', [(int) $cathy['show_olli'], (int) $cathy['show_cathy']], [0, 1]);
try {
    $save(['kind' => 'stream', 'title' => 'Niemand', 'stream_url' => 'https://none.example/s.mp3', 'show_olli' => 0, 'show_cathy' => 0]);
    check('no profile refused', false, true);
} catch (InputError $e) {
    check('no profile refused', isset($e->fields['show_olli']), true);
}
$catRow = station_save(['title' => 'Mixe', 'active' => 1, 'show_olli' => 0, 'show_cathy' => 1], (int) $cat['id']);
check('a category stays without own profiles', [(int) $catRow['show_olli'], (int) $catRow['show_cathy']], [1, 0]);
$kept = station_save(['title' => 'Nur Cathy', 'stream_url' => 'https://cathy.example/s.mp3', 'mime' => 'audio/mpeg', 'active' => 1], (int) $cathy['id']);
check('profiles kept when not sent', [(int) $kept['show_olli'], (int) $kept['show_cathy']], [0, 1]);
check('admin JSON', [station_public($kept)['show_olli'], station_public($kept)['show_cathy']], [false, true]);

// the lists
$names = static fn(array $entries): array => array_map(static fn($e) => $e['row']['title'], $entries);
$olliList  = public_catalog();
$cathyList = public_catalog(false, 'cathy');
check('Olli: his and both', $names($olliList['stations']), ['Für beide', 'Nur Olli']);
check('Olli: a category of Cathy\'s alone is hidden', $names($olliList['categories']), []);
check('Cathy: hers and both, the category opened in place', $names($cathyList['stations']), ['Für beide', 'Nur Cathy', 'Cathy in Mixe']);
check('Cathy: the category', $names($cathyList['categories']), ['Mixe']);
check('web app: Olli\'s', array_map(static fn($i) => $i['name'], webapp_levels()['root']), ['Für beide', 'Nur Olli']);
check('Sonos: Olli\'s', array_map(static fn($s) => $s['title'], sonos_visible(stations_all(true), playlist_stats(), null)), ['Für beide', 'Nur Olli']);
check('streams.xspf: Olli\'s', substr_count(xspf_playlist(public_catalog()['stations'], static fn() => null), '<track>'), 2);

// MP3 playlists: tracks with files in media/tracks, one without a file, an empty playlist
@mkdir(track_dir(), 0755, true);
$track = static function (string $title, bool $file): int {
    $name = bin2hex(random_bytes(6)) . '.mp3';
    if ($file) {
        file_put_contents(track_dir() . '/' . $name, 'ID3');
    }
    q('INSERT INTO r1_tracks (file, title, artist, album, duration, size, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$name, $title, 'Artist', '', 200, 3, now_sql(), now_sql()]);
    return (int) db()->lastInsertId();
};
$t1 = $track('Eins', true);
$t2 = $track('Zwei ohne Datei', false);
$t3 = $track('Drei', true);
$plBoth  = $save(['kind' => 'playlist', 'title' => 'Liste für beide', 'show_olli' => 1, 'show_cathy' => 1]);
$plOlli  = $save(['kind' => 'playlist', 'title' => 'Liste Olli']);
$plEmpty = $save(['kind' => 'playlist', 'title' => 'Leer', 'show_olli' => 1, 'show_cathy' => 1]);
$plCat   = $save(['kind' => 'playlist', 'title' => 'Liste in Mixe', 'show_olli' => 0, 'show_cathy' => 1, 'category_id' => (int) $cat['id']]);
playlist_set_tracks((int) $plBoth['id'], [$t1, $t2, $t3]);
playlist_set_tracks((int) $plOlli['id'], [$t3]);
playlist_set_tracks((int) $plCat['id'], [$t1]);

$olliPl  = playlists_api('olli');
$cathyPl = playlists_api('cathy');
check('playlists Olli', array_column($olliPl, 'name'), ['Liste für beide', 'Liste Olli']);
// in listening order: the category (created first) opens in place before the later playlist
check('playlists Cathy', array_column($cathyPl, 'name'), ['Liste in Mixe', 'Liste für beide']);
check('tracks without a file are missing', array_column($olliPl[0]['tracks'], 'title'), ['Eins', 'Drei']);
check('track fields', array_keys($olliPl[0]['tracks'][0]), ['id', 'title', 'artist', 'album', 'duration', 'url', 'cover']);
check('track id as in Sonos', $olliPl[0]['tracks'][0]['id'], 'track:' . $t1);
check('track address', str_starts_with($olliPl[0]['tracks'][0]['url'], 'https://radio.test/media/tracks/'), true);
check('empty album is null', $olliPl[0]['tracks'][0]['album'], null);
check('playlist in a category', [$cathyPl[0]['category'], $cathyPl[0]['sort']], [$cat['smapi_id'], 2]);

// one counting: stations.json and /api/playlists number one list per profile
$withLists = public_catalog(false, 'olli', true);
$top = [];
foreach ($withLists['stations'] as $e) {
    if ($e['category'] === null) {
        $top[$e['sort']] = $e['row']['title'];
    }
}
foreach ($withLists['playlists'] as $e) {
    if ($e['category'] === null) {
        $top[$e['sort']] = $e['row']['title'];
    }
}
ksort($top);
check('one counting (Olli)', $top, [1 => 'Für beide', 2 => 'Nur Olli', 3 => 'Liste für beide', 4 => 'Liste Olli']);
$cathyAll = public_catalog(false, 'cathy', true);
$inMixe = [];
foreach (array_merge($cathyAll['stations'], $cathyAll['playlists']) as $e) {
    if (($e['category']['smapi_id'] ?? null) === $cat['smapi_id']) {
        $inMixe[$e['sort']] = $e['row']['title'];
    }
}
ksort($inMixe);
check('one counting inside a category (Cathy)', $inMixe, [1 => 'Cathy in Mixe', 2 => 'Liste in Mixe']);
check('positions only with playlists asked for', array_column(public_catalog()['stations'], 'sort'), [1, 2]);

// Sonos keeps Olli's playlists, not Cathy's alone
check('Sonos with playlists', array_map(static fn($s) => $s['title'], sonos_visible(stations_all(true), playlist_stats(), null)),
    ['Für beide', 'Nur Olli', 'Liste für beide', 'Liste Olli']);

// the listening status is Olli's: a station of Cathy's alone never counts (audit 1.32.1)
check('status for Cathy alone refused', listen_report(station_find((int) $cathy['id']), true), false);
check('status for both', listen_report(station_find((int) $both['id']), true), true);
check('only Olli\'s playing', listen_active(), [$both['smapi_id']]);
// a session of Cathy's station from before (or by another way into the table) stays out
$t0 = time();
q('INSERT INTO r1_listen_status (station_id, last_seen, stopped, since, posted_since) VALUES (?, ?, 0, ?, 0)', [(int) $cathy['id'], $t0, $t0 - 60]);
check('Cathy alone never playing', listen_active(), [$both['smapi_id']]);
q('UPDATE r1_listen_status SET stopped = 1, last_seen = ?, since = ? WHERE station_id = ?', [$t0, $t0 - 3600, (int) $cathy['id']]);
q('UPDATE r1_listen_status SET stopped = 1, last_seen = ?, since = ? WHERE station_id = ?', [$t0 - 600, $t0 - 4000, (int) $both['id']]);
check('Cathy alone never heard last', listen_last(), $both['smapi_id']);
q('DELETE FROM r1_listen_status');

// keys: every profile reads its own playlists with its own key, only Olli's reports
$keyOlli  = listen_device_create('Ollis Handy', 'app');
$keyCathy = listen_device_create('Cathys Handy', 'app', 'cathy');
$browser  = listen_device_create('Browser', 'browser', 'cathy');
check('key default is Olli', $keyOlli['device']['profile'], 'olli');
check('key for Cathy', $keyCathy['device']['profile'], 'cathy');
check('a browser is always Olli', $browser['device']['profile'], 'olli');
check('admin JSON of a key', listen_device_public($keyCathy['device'])['profile'], 'cathy');
try {
    listen_device_create('X', 'app', 'bob');
    check('unknown profile for a key refused', false, true);
} catch (InputError $e) {
    check('unknown profile for a key refused', isset($e->fields['profile']), true);
}
check('Olli\'s playlists with his key', playlists_access('olli', $keyOlli['token']), 200);
check('Cathy\'s playlists with her key', playlists_access('cathy', $keyCathy['token']), 200);
check('Olli\'s playlists with Cathy\'s key', playlists_access('olli', $keyCathy['token']), 403);
check('Cathy\'s playlists with Olli\'s key', playlists_access('cathy', $keyOlli['token']), 403);
check('playlists without a key', [playlists_access('olli', ''), playlists_access('cathy', '')], [401, 401]);
check('playlists with a browser token', playlists_access('olli', $browser['token']), 401);
listen_device_revoke((int) $keyCathy['device']['id']);
check('playlists with a revoked key', playlists_access('cathy', $keyCathy['token']), 401);
check('listening status: Olli\'s key', listen_profile_allowed($keyOlli['device']), true);
check('listening status: Cathy\'s key', listen_profile_allowed($keyCathy['device']), false);

foreach ([$both, $olli, $cathy, $inCat, $cat, $plBoth, $plOlli, $plEmpty, $plCat] as $s) {
    station_delete((int) $s['id']);
}
foreach ([$t1, $t2, $t3] as $t) {
    track_delete($t);
}
