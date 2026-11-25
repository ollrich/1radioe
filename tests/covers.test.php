<?php
/**
 * Category pictures (#87): a category without a picture of its own shows a grid of the
 * first logos inside, and every list makes it of what it shows itself: the web app
 * (Olli's streams, SoundCloud, Deezer), Sonos (Olli's streams and MP3 playlists), the app
 * per profile (stations.json), the admin (everything active). The grids of the lists are
 * files of their own and never delete each other; a picture of its own always wins.
 */
declare(strict_types=1);

$save = static fn(array $in): array => station_save($in + ['mime' => 'audio/mpeg', 'active' => 1]);
$logo = static function (array $row, int $shade): string {
    $name = 'logo-' . $row['smapi_id'] . '.jpg';
    file_put_contents(media_dir() . '/' . $name, test_jpeg($shade, 80, 160, 120, 120));
    q('UPDATE r1_stations SET logo = ? WHERE id = ?', [$name, (int) $row['id']]);
    return $name;
};
$cat   = $save(['kind' => 'category', 'title' => 'Gemischt']);
$in    = ['category_id' => (int) $cat['id']];
$olli  = $save($in + ['kind' => 'stream', 'title' => 'Olli A', 'stream_url' => 'https://a.example/a.mp3']);
$cathy = $save($in + ['kind' => 'stream', 'title' => 'Cathy B', 'stream_url' => 'https://b.example/b.mp3', 'show_olli' => 0, 'show_cathy' => 1]);
$sc    = $save($in + ['kind' => 'soundcloud', 'title' => 'Mix C', 'stream_url' => 'https://soundcloud.com/someone/mix-c']);
$list  = $save($in + ['kind' => 'playlist', 'title' => 'Liste D']);
$both  = $save($in + ['kind' => 'stream', 'title' => 'Beide E', 'stream_url' => 'https://e.example/e.mp3', 'show_olli' => 1, 'show_cathy' => 1]);
$L = [];
foreach (['a' => $olli, 'b' => $cathy, 'c' => $sc, 'd' => $list, 'e' => $both] as $k => $row) {
    $L[$k] = $logo($row, 40 * count($L));
}
// the playlist plays (a track with its file), so Sonos and the app show it
@mkdir(track_dir(), 0755, true);
$trackFile = bin2hex(random_bytes(6)) . '.mp3';
file_put_contents(track_dir() . '/' . $trackFile, 'ID3');
q('INSERT INTO r1_tracks (file, title, artist, album, duration, size, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    [$trackFile, 'Eins', 'Artist', '', 200, 3, now_sql(), now_sql()]);
$track = (int) db()->lastInsertId();
playlist_set_tracks((int) $list['id'], [$track]);

$id     = (int) $cat['id'];
$grid   = static fn(string $variant, array $logos): string => 'cat' . $id . '-' . ($variant !== '' ? $variant . '-' : '')
    . substr(sha1(implode(',', $logos)), 0, 12) . '.jpg';
$exists = static fn(?string $f): bool => $f !== null && is_file(media_dir() . '/' . $f);
$web    = static function () use ($cat): ?string {
    $tile = current(array_filter(webapp_levels()['root'], static fn($it) => $it['id'] === $cat['smapi_id'])) ?: [];
    return isset($tile['logo']) ? basename((string) $tile['logo']) : null;
};
$sonos  = static fn(): ?string => category_cover(station_find($id), sonos_visible(stations_all(true), playlist_stats(), $id));
$app    = static fn(string $profile): ?string => category_cover(station_find($id), catalog_inside(public_catalog(false, $profile, true))[$id] ?? [], $profile);
$admin  = static fn(): ?string => basename((string) station_public(station_find($id))['logo_url']);

check('web app: Olli\'s streams and SoundCloud, no MP3 playlist', $web(), $grid('web', [$L['a'], $L['c'], $L['e']]));
check('Sonos: Olli\'s streams and playlists, no SoundCloud', $sonos(), $grid('', [$L['a'], $L['d'], $L['e']]));
check('app Olli', $app('olli'), $grid('olli', [$L['a'], $L['d'], $L['e']]));
check('app Cathy: hers, never Olli\'s alone', $app('cathy'), $grid('cathy', [$L['b'], $L['e']]));
check('admin: everything active', $admin(), $grid('all', [$L['a'], $L['b'], $L['c'], $L['d']]));
$all = [$web(), $sonos(), $app('olli'), $app('cathy'), $admin()];
check('every list keeps its grid', array_map($exists, $all), [true, true, true, true, true]);
check('five different grids', count(array_unique($all)), 5);

// a new station for Cathy: only her grid changes (the admin's takes the first four), the others stay
$cathy2 = $save($in + ['kind' => 'stream', 'title' => 'Cathy F', 'stream_url' => 'https://f.example/f.mp3', 'show_olli' => 0, 'show_cathy' => 1]);
$L['f'] = $logo($cathy2, 220);
$cathyNew = $app('cathy');
check('Cathy\'s new grid', $cathyNew, $grid('cathy', [$L['b'], $L['e'], $L['f']]));
check('her old grid gone', $exists($all[3]), false);
check('the other grids stay', array_map($exists, [$all[0], $all[1], $all[2]]), [true, true, true]);
check('web app unchanged', $web(), $all[0]);

// a picture of its own wins everywhere
$own = $logo($cat, 10);
check('own picture', [$web(), $sonos(), $app('olli'), $app('cathy'), $admin()], [$own, $own, $own, $own, $own]);

foreach ([$olli, $cathy, $cathy2, $sc, $list, $both, $cat] as $s) {
    station_delete((int) $s['id']);
}
track_delete($track);
