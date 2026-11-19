<?php
/**
 * YouTube playlists: addresses, API data, sync with stand-ins for the API and the
 * pictures, picture names by content (#65), shared videos, web app data, and that
 * YouTube stays out of Sonos, streams.m3u and stations.json.
 */
declare(strict_types=1);

// ---------- addresses, lengths, error texts ----------
$pl = 'PLkPlDl7EHVHgZwXOMZ9k5Jz6PoNHR0_SC';
check('url', youtube_playlist_id("https://www.youtube.com/playlist?list=$pl"), $pl);
check('url with si', youtube_playlist_id("https://youtube.com/playlist?list=$pl&si=abc123"), $pl);
check('video in a playlist', youtube_playlist_id("https://www.youtube.com/watch?v=kW33vGTzwK4&list=$pl&index=2"), $pl);
check('YouTube Music', youtube_playlist_id("https://music.youtube.com/playlist?list=$pl"), $pl);
check('bare id', youtube_playlist_id($pl), $pl);
check('video only', youtube_playlist_id('https://www.youtube.com/watch?v=kW33vGTzwK4'), null);
check('other host', youtube_playlist_id("https://evil.example/playlist?list=$pl"), null);
check('garbage', youtube_playlist_id('hello'), null);

check('duration h m s', youtube_duration('PT1H2M3S'), 3723);
check('duration m', youtube_duration('PT47M'), 2820);
check('duration days', youtube_duration('P4DT12H'), 4 * 86400 + 12 * 3600);
check('duration live', youtube_duration('P0D'), 0);
check('length h', youtube_length(3723), '1:02:03');
check('length m', youtube_length(65), '1:05');
check('length 0', youtube_length(0), '');

check('error key', youtube_error_text(['code' => 400, 'message' => 'API key not valid.', 'errors' => [['reason' => 'badRequest']], 'details' => [['reason' => 'API_KEY_INVALID']]]), 'API-Schlüssel ungültig.');
check('error api off', youtube_error_text(['code' => 403, 'errors' => [['reason' => 'accessNotConfigured']]]), 'Die YouTube Data API v3 ist für diesen Schlüssel nicht aktiviert.');
check('error quota', youtube_error_text(['code' => 403, 'errors' => [['reason' => 'quotaExceeded']]]), 'Tageskontingent der API ist aufgebraucht, morgen geht es weiter.');
check('error ip', youtube_error_text(['code' => 403, 'details' => [['reason' => 'API_KEY_IP_ADDRESS_BLOCKED']]]), 'Der Schlüssel ist für diesen Server gesperrt. Einschränkungen des Schlüssels in der Google Cloud prüfen.');
check('error playlist', youtube_error_text(['code' => 404, 'errors' => [['reason' => 'playlistNotFound']]]), 'Playlist nicht gefunden. Ist sie öffentlich oder nicht gelistet?');
check('error other', youtube_error_text(['code' => 500, 'message' => 'Backend Error']), 'YouTube meldet: Backend Error');

// ---------- stand-ins for the API and the pictures ----------
// a playlist: newest added first after sorting; a private, a not embeddable and an
// upcoming entry; one video twice (counts once, with its newest date); three per page
$videos = [
    'kW33vGTzwK4' => ['Solomun Live at Kappa FuturFestival 2026', 'DJ Mag', 'PT2H1M5S'],
    'mUkZhbct7Eo' => ['Purple Disco Machine @ Magazine Open Air', 'Purple Disco Machine', 'PT1H30M'],
    's9r5sBZR_cU' => ['Bicep @TheLotRadio', 'The Lot Radio', 'PT58M12S'],
    'NOEMBED0001' => ['Not embeddable', 'X', 'PT1M'],
    'UPCOMING001' => ['Premiere', 'Y', 'P0D'],
    'djsItMOfCQA' => ['Basement Jaxx | Boiler Room: London', 'Boiler Room', 'PT1H2M'],
];
$entries = [
    ['kW33vGTzwK4', '2026-11-06T10:00:00Z'], ['mUkZhbct7Eo', '2026-11-04T10:00:00Z'], ['PRIVATE0001', '2026-11-03T10:00:00Z'],
    ['s9r5sBZR_cU', '2026-09-16T10:00:00Z'], ['NOEMBED0001', '2026-09-15T10:00:00Z'], ['UPCOMING001', '2026-09-14T10:00:00Z'],
    ['djsItMOfCQA', '2026-12-01T10:00:00Z'], ['kW33vGTzwK4', '2025-01-01T10:00:00Z'],
];
$calls = [];
$api = static function (array $entries) use ($videos, &$calls): Closure {
    return static function (string $endpoint, array $params) use ($entries, $videos, &$calls): array {
        $calls[] = $endpoint . (isset($params['pageToken']) ? '#' . $params['pageToken'] : '');
        if ($endpoint === 'playlistItems') {
            $page = (int) ($params['pageToken'] ?? 0);
            $out  = ['items' => array_map(static fn($e) => [
                'snippet'        => ['publishedAt' => $e[1], 'resourceId' => ['videoId' => $e[0]], 'title' => $e[0] === 'PRIVATE0001' ? 'Private video' : 't'],
                'contentDetails' => ['videoId' => $e[0]],
            ], array_slice($entries, $page * 3, 3))];
            if (($page + 1) * 3 < count($entries)) {
                $out['nextPageToken'] = (string) ($page + 1);
            }
            return $out;
        }
        $out = [];
        foreach (explode(',', (string) $params['id']) as $vid) {
            if (!isset($videos[$vid])) {
                continue;   // private and deleted videos are not returned
            }
            [$title, $channel, $duration] = $videos[$vid];
            $out[] = [
                'id'             => $vid,
                'snippet'        => ['title' => $title, 'channelTitle' => $channel, 'liveBroadcastContent' => $vid === 'UPCOMING001' ? 'upcoming' : 'none',
                    'thumbnails' => ['high' => ['url' => "https://i.ytimg.com/vi/$vid/hqdefault.jpg"]]],
                'contentDetails' => ['duration' => $duration],
                'status'         => ['embeddable' => $vid !== 'NOEMBED0001', 'privacyStatus' => 'public'],
            ];
        }
        return ['items' => $out];
    };
};
// pictures by video; changing an entry is a new picture on YouTube for the same video
$pics = [];
foreach (array_keys($videos) as $i => $vid) {
    $pics[$vid] = test_jpeg(40 * $i, 100, 200 - 20 * $i);
}
$get = static function (string $url) use (&$pics): ?string {
    return preg_match('~/vi/([\w-]+)/~', $url, $m) ? ($pics[$m[1]] ?? null) : null;
};
$file  = static fn(?string $name): bool => $name !== null && is_file(media_dir() . '/' . $name);
$image = static fn(int $list, string $vid): ?string => q('SELECT image FROM r1_youtube_videos WHERE list_id = ? AND video_id = ?', [$list, $vid])->fetchColumn() ?: null;
$age   = static fn(int $list) => q('UPDATE r1_youtube_videos SET image_at = 0 WHERE list_id = ?', [$list]);

// ---------- fetch ----------
[$fetched, $skipped] = youtube_fetch($pl, $api($entries));
check('fetch order, newest added first', array_column($fetched, 'video_id'), ['djsItMOfCQA', 'kW33vGTzwK4', 'mUkZhbct7Eo', 's9r5sBZR_cU']);
check('fetch skipped', $skipped, 3);
check('fetch pages', $calls, ['playlistItems', 'playlistItems#1', 'playlistItems#2', 'videos']);
check('fetch duplicate keeps newest date', $fetched[1]['added_at'] ?? null, strtotime('2026-11-06T10:00:00Z'));
check('fetch duration', $fetched[0]['duration'] ?? null, 3720);

// ---------- saving a playlist ----------
$a = station_save(['kind' => 'youtube', 'title' => 'Konzerte', 'stream_url' => "https://youtube.com/playlist?list=$pl&si=x", 'description' => 'DJ-Sets', 'active' => 1, 'category_id' => 99]);
$A = (int) $a['id'];
check('saved address', $a['stream_url'], "https://www.youtube.com/playlist?list=$pl");
check('no category', $a['category_id'], null);
check('no posting', (int) $a['social'], 0);
try {
    station_save(['kind' => 'youtube', 'title' => 'x', 'stream_url' => 'https://www.youtube.com/watch?v=abc']);
    check('video address refused', false, true);
} catch (InputError $e) {
    check('video address refused', isset($e->fields['stream_url']), true);
}

// ---------- first sync ----------
$res = youtube_sync($A, $api($entries), $get);
check('sync', $res, ['ok' => true, 'info' => '4 Videos, 3 nicht abspielbar und ausgelassen']);
$rows = youtube_videos($A);
check('stored order', array_column($rows, 'video_id'), ['djsItMOfCQA', 'kW33vGTzwK4', 'mUkZhbct7Eo', 's9r5sBZR_cU']);
foreach ($rows as $r) {
    $big   = media_dir() . '/' . $r['image'];
    $small = media_dir() . '/' . logo_thumb_name((string) $r['image']);
    check('picture name ' . $r['video_id'], (bool) preg_match('~^yt-' . preg_quote($r['video_id'], '~') . '-[0-9a-f]{10}\.jpg$~', (string) $r['image']), true);
    check('picture sizes ' . $r['video_id'], [@getimagesize($big)[0] ?? 0, @getimagesize($big)[1] ?? 0, @getimagesize($small)[0] ?? 0, @getimagesize($small)[1] ?? 0], [640, 360, 256, 144]);
}
$station = station_find($A);
check('check stored', [(int) $station['check_ok'], $station['check_info']], [1, '4 Videos, 3 nicht abspielbar und ausgelassen']);
$cover = youtube_cover($station);
check('grid as cover', (bool) preg_match('~^cat' . $A . '-[0-9a-f]{12}\.jpg$~', (string) $cover) && $file($cover), true);

// ---------- web app ----------
$levels = webapp_levels();
$stack  = current(array_filter($levels['root'], static fn($it) => $it['id'] === $station['smapi_id'])) ?: [];
check('stack', [$stack['type'] ?? null, $stack['count'] ?? null, $stack['videos'] ?? null], ['category', 4, true]);
$first = $levels[$station['smapi_id']][0] ?? [];
check('first video', [$first['id'] ?? null, $first['type'] ?? null, $first['video'] ?? null, $first['num'] ?? null],
    ['yt.djsItMOfCQA', 'youtube', 'djsItMOfCQA', '01']);
check('first video line', [$first['desc'] ?? null, $first['channel'] ?? null, $first['length'] ?? null], ['Boiler Room · 1:02:00', 'Boiler Room', 3720]);
check('first video pictures', [$first['logo'] ?? null, $first['thumb'] ?? null],
    ['https://radio.test/media/' . $rows[0]['image'], 'https://radio.test/media/' . logo_thumb_name((string) $rows[0]['image'])]);

// ---------- never in Sonos, streams.m3u or stations.json ----------
check('not in Sonos', array_values(array_filter(sonos_visible(stations_all(true), playlist_stats(), null), static fn($s) => $s['kind'] === 'youtube')), []);
$public = public_catalog(false);
check('not in the public stations', array_values(array_filter($public['stations'], static fn($e) => $e['row']['kind'] === 'youtube')), []);
check('not in the public categories', array_values(array_filter($public['categories'], static fn($c) => $c['row']['kind'] === 'youtube' || !empty($c['youtube']))), []);

// ---------- the same picture again after 25 days: same name, nothing new (#65) ----------
$before = array_column(youtube_videos($A), 'image', 'video_id');
$age($A);
youtube_sync($A, $api($entries), $get);
check('same picture, same names', array_column(youtube_videos($A), 'image', 'video_id'), $before);
check('same picture, same grid', youtube_cover(station_find($A)), $cover);

// ---------- a new picture of the same video: new name, old files go, new grid (#65) ----------
$old = $image($A, 'djsItMOfCQA');
$pics['djsItMOfCQA'] = test_jpeg(250, 30, 30);
$age($A);
youtube_sync($A, $api($entries), $get);
$new = $image($A, 'djsItMOfCQA');
check('new picture, new name', $new !== null && $new !== $old && $file($new) && $file(logo_thumb_name($new)), true);
check('old picture removed', [$file($old), $file(logo_thumb_name($old))], [false, false]);
$cover2 = youtube_cover(station_find($A));
check('new grid', $cover2 !== $cover && $file($cover2), true);
check('old grid removed', $file($cover) || $file(logo_thumb_name($cover)), false);

// ---------- a video in two playlists shares its picture ----------
$b = station_save(['kind' => 'youtube', 'title' => 'Zweite', 'stream_url' => 'https://www.youtube.com/playlist?list=PLsecond0000000000000000000000000', 'active' => 1]);
$B = (int) $b['id'];
$apiB = $api([['kW33vGTzwK4', '2026-11-10T10:00:00Z']]);
youtube_sync($B, $apiB, $get);
$shared = $image($A, 'kW33vGTzwK4');
check('shared picture', $image($B, 'kW33vGTzwK4'), $shared);
// a new picture: the first playlist takes it, the old one stays for the second
$pics['kW33vGTzwK4'] = test_jpeg(10, 250, 10);
$age($A);
youtube_sync($A, $api($entries), $get);
$newer = $image($A, 'kW33vGTzwK4');
check('first playlist has the new picture', $newer !== $shared && $file($newer), true);
check('old picture kept for the second', $file($shared), true);
$age($B);
youtube_sync($B, $apiB, $get);
check('second playlist follows', $image($B, 'kW33vGTzwK4'), $newer);
check('old picture removed when nobody shows it', $file($shared), false);

// ---------- a video leaves the playlist: row and pictures go ----------
$gone    = $image($A, 'mUkZhbct7Eo');
$entries2 = array_values(array_filter($entries, static fn($e) => $e[0] !== 'mUkZhbct7Eo'));
youtube_sync($A, $api($entries2), $get);
check('left the playlist', array_column(youtube_videos($A), 'video_id'), ['djsItMOfCQA', 'kW33vGTzwK4', 's9r5sBZR_cU']);
check('its pictures removed', [$file($gone), $file(logo_thumb_name($gone))], [false, false]);

// ---------- deleting a playlist keeps what another one still shows ----------
station_delete($B);
check('deleted playlist, rows gone', youtube_videos($B), []);
check('shared picture kept', $file($newer), true);

// ---------- a failed sync keeps the list and says why ----------
$res = youtube_sync($A, static function (): array {
    throw new YoutubeError('API-Schlüssel ungültig.');
}, $get);
check('failed sync', [$res, count(youtube_videos($A)), (int) station_find($A)['check_ok'], station_find($A)['check_info']],
    [['ok' => false, 'info' => 'API-Schlüssel ungültig.'], 3, 0, 'API-Schlüssel ungültig.']);
// without a key nothing goes out to the network
check('no key', youtube_sync($A)['info'], 'Kein API-Schlüssel eingetragen.');
