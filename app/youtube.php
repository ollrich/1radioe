<?php
/**
 * YouTube playlists (kind = 'youtube'): a stack in the web app that fills itself from a
 * playlist on YouTube, the video added last first. Name, description and cover come from
 * the admin like for Deezer; the videos come from the YouTube Data API v3, once a day
 * (after the response of a page view) or on "Jetzt abgleichen" in the admin.
 *
 * Kept per video: id, title, channel, when it was added to the playlist, length and a
 * picture (16:9, stored in the media folder like logos, so the page asks nothing of
 * Google before the visitor agrees). Videos that are private, deleted, not embeddable or
 * gone from the playlist drop out at the next sync. YouTube's terms allow keeping such
 * data for 30 days at most: the daily sync refreshes the rows, the pictures are fetched
 * again after 25 days.
 *
 * The videos play only in the web app, in YouTube's own player and visible (YouTube does
 * not allow sound without the picture). Sonos, the playlist file and stations.json leave
 * them out, they count neither for the listening status nor for posting.
 *
 * The API key is stored encrypted like the posting secrets (secret_key in config.php).
 */
declare(strict_types=1);

const YOUTUBE_API        = 'https://www.googleapis.com/youtube/v3/';
const YOUTUBE_MAX_VIDEOS = 500;           // ten pages of the API
const YOUTUBE_EVERY      = 86400;         // sync once a day
const YOUTUBE_RETRY      = 3600;          // after a failed sync
const YOUTUBE_IMAGE_AGE  = 25 * 86400;    // pictures are fetched again before YouTube's 30 days
const YOUTUBE_IMAGE_W    = 640;           // the picture in the player (16:9)
const YOUTUBE_IMAGE_H    = 360;
const YOUTUBE_THUMB_W    = 256;           // tiles of 44 to 72 px, cropped to a square there
const YOUTUBE_THUMB_H    = 144;

final class YoutubeError extends RuntimeException
{
}

/**
 * The id of a YouTube playlist from what was pasted: the playlist address, a video in
 * the playlist (watch?v=…&list=…), YouTube Music, or the bare id. Null if there is none.
 */
function youtube_playlist_id(string $input): ?string
{
    $input = trim($input);
    if (preg_match('~^[A-Za-z0-9_-]{10,64}$~', $input) && preg_match('~^(PL|UU|LL|FL|OL|RD)~', $input)) {
        return $input;
    }
    $p = parse_url($input);
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['port'])) {
        return null;
    }
    $host = strtolower($p['host'] ?? '');
    if (!in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
        return null;
    }
    parse_str((string) ($p['query'] ?? ''), $q);
    $list = is_string($q['list'] ?? null) ? $q['list'] : '';
    return preg_match('~^[A-Za-z0-9_-]{10,64}$~', $list) ? $list : null;
}

function youtube_playlist_url(string $id): string
{
    return 'https://www.youtube.com/playlist?list=' . rawurlencode($id);
}

function youtube_watch_url(string $videoId): string
{
    return 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
}

/* ---------- API key ---------- */

function youtube_key(): ?string
{
    $sealed = setting('youtube_key');
    return $sealed !== null && $sealed !== '' ? social_unseal($sealed) : null;
}

function youtube_has_key(): bool
{
    return (string) setting('youtube_key', '') !== '';
}

/** Stores the key (encrypted) after a test call, or removes it ($key ''). */
function youtube_key_save(string $key): void
{
    $key = trim($key);
    if ($key === '') {
        setting_set('youtube_key', '');
        return;
    }
    if (!preg_match('~^[A-Za-z0-9_-]{30,60}$~', $key)) {
        throw new InputError(['yt_key' => 'Das sieht nicht nach einem API-Schlüssel aus (beginnt meist mit AIza).']);
    }
    try {
        // one unit of the daily quota: the first video on YouTube
        youtube_api('videos', ['part' => 'id', 'id' => 'jNQXAC9IVRw'], $key);
    } catch (YoutubeError $e) {
        throw new InputError(['yt_key' => $e->getMessage()]);
    }
    setting_set('youtube_key', social_seal($key));
}

/* ---------- API ---------- */

/** One call of the Data API. Returns the decoded answer, throws YoutubeError with a German message. */
function youtube_api(string $endpoint, array $params, ?string $key = null): array
{
    $key ??= youtube_key();
    if ($key === null || $key === '') {
        throw new YoutubeError(youtube_has_key()
            ? 'API-Schlüssel lässt sich nicht entschlüsseln. Wurde secret_key in config.php geändert? Dann bitte neu eingeben.'
            : 'Kein API-Schlüssel eingetragen.');
    }
    $res  = http_get(YOUTUBE_API . $endpoint . '?' . http_build_query($params + ['key' => $key]), 2 * 1024 * 1024, 15);
    $json = json_decode($res['body'], true);
    if ($res['ok'] && is_array($json)) {
        return $json;
    }
    if (!is_array($json) || !is_array($json['error'] ?? null)) {
        throw new YoutubeError('YouTube nicht erreichbar: ' . ($res['error'] !== '' ? $res['error'] : 'keine gültige Antwort'));
    }
    throw new YoutubeError(youtube_error_text($json['error']));
}

/** A German message for an error answer of the API ($err: its "error" object). */
function youtube_error_text(array $err): string
{
    $reasons = [];
    foreach ((array) ($err['errors'] ?? []) as $e) {
        $reasons[] = (string) ($e['reason'] ?? '');
    }
    foreach ((array) ($err['details'] ?? []) as $d) {
        $reasons[] = (string) ($d['reason'] ?? '');
    }
    $has = static fn(string ...$r): bool => (bool) array_intersect($r, $reasons);
    return match (true) {
        $has('keyInvalid', 'API_KEY_INVALID')                  => 'API-Schlüssel ungültig.',
        $has('accessNotConfigured', 'SERVICE_DISABLED')       => 'Die YouTube Data API v3 ist für diesen Schlüssel nicht aktiviert.',
        $has('quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded') => 'Tageskontingent der API ist aufgebraucht, morgen geht es weiter.',
        $has('API_KEY_IP_ADDRESS_BLOCKED', 'ipRefererBlocked', 'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_SERVICE_BLOCKED') => 'Der Schlüssel ist für diesen Server gesperrt. Einschränkungen des Schlüssels in der Google Cloud prüfen.',
        $has('playlistNotFound')                               => 'Playlist nicht gefunden. Ist sie öffentlich oder nicht gelistet?',
        default => 'YouTube meldet: ' . mb_substr(clean_text((string) ($err['message'] ?? 'Fehler ' . ($err['code'] ?? '?'))), 0, 160),
    };
}

/** Length of a video from ISO 8601 ("PT1H2M3S"), in seconds. */
function youtube_duration(string $iso): int
{
    if (!preg_match('~^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$~', $iso, $m)) {
        return 0;
    }
    return (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (int) ($m[4] ?? 0);
}

/**
 * The playable videos of a playlist, the one added last first. Returns
 * [videos, skipped]: videos as ['video_id', 'title', 'channel', 'added_at', 'duration',
 * 'thumb' (address of the best picture)], skipped counts entries left out (private,
 * deleted, not embeddable, not yet live). $api: youtube_api() or a stand-in for tests.
 */
function youtube_fetch(string $playlistId, ?callable $api = null): array
{
    $api ??= 'youtube_api';
    $entries = [];
    $page    = '';
    do {
        $params = ['part' => 'snippet,contentDetails', 'playlistId' => $playlistId, 'maxResults' => 50];
        if ($page !== '') {
            $params['pageToken'] = $page;
        }
        $json = $api('playlistItems', $params);
        foreach ((array) ($json['items'] ?? []) as $pos => $item) {
            $vid = (string) ($item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? '');
            if (!preg_match('~^[A-Za-z0-9_-]{6,20}$~', $vid)) {
                continue;
            }
            $added = strtotime((string) ($item['snippet']['publishedAt'] ?? '')) ?: 0;
            if (!isset($entries[$vid])) {
                $entries[$vid] = ['added_at' => $added, 'order' => count($entries)];
            } elseif ($added > $entries[$vid]['added_at']) {
                $entries[$vid]['added_at'] = $added;   // a video added twice counts once, as added last
            }
        }
        $page = (string) ($json['nextPageToken'] ?? '');
    } while ($page !== '' && count($entries) < YOUTUBE_MAX_VIDEOS);

    // details of what is still there: private and deleted videos do not come back
    $videos = [];
    foreach (array_chunk(array_keys($entries), 50) as $ids) {
        $json = $api('videos', ['part' => 'snippet,contentDetails,status', 'id' => implode(',', $ids), 'maxResults' => 50]);
        foreach ((array) ($json['items'] ?? []) as $v) {
            $vid = (string) ($v['id'] ?? '');
            if (!isset($entries[$vid])
                || ($v['status']['embeddable'] ?? true) === false
                || ($v['status']['privacyStatus'] ?? 'public') === 'private'
                || ($v['snippet']['liveBroadcastContent'] ?? 'none') === 'upcoming') {
                continue;
            }
            $thumbs = (array) ($v['snippet']['thumbnails'] ?? []);
            $thumb  = '';
            foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
                if (is_string($thumbs[$size]['url'] ?? null)) {
                    $thumb = $thumbs[$size]['url'];
                    break;
                }
            }
            $videos[] = [
                'video_id' => $vid,
                'title'    => mb_substr(clean_text((string) ($v['snippet']['title'] ?? '')), 0, 200) ?: $vid,
                'channel'  => mb_substr(clean_text((string) ($v['snippet']['channelTitle'] ?? '')), 0, 120),
                'added_at' => $entries[$vid]['added_at'],
                'order'    => $entries[$vid]['order'],
                'duration' => youtube_duration((string) ($v['contentDetails']['duration'] ?? '')),
                'thumb'    => $thumb,
            ];
        }
    }
    // the one added last first; same moment: as in the playlist
    usort($videos, static fn($a, $b) => [$b['added_at'], $a['order']] <=> [$a['added_at'], $b['order']]);
    return [$videos, count($entries) - count($videos)];
}

/* ---------- Pictures ---------- */

/**
 * File names of a video's pictures: "yt-<id>-<hash>.jpg" and "yt-<id>-<hash>-s.jpg".
 * The hash of the picture is part of the name: browsers keep media files for a year
 * (MEDIA_HTACCESS), so a new picture of the same video needs a new name, and the grid
 * of a playlist (category_collage) follows by itself.
 */
function youtube_image_name(string $videoId, string $data): string
{
    $hash = substr(sha1(YOUTUBE_IMAGE_W . 'x' . YOUTUBE_IMAGE_H . ',' . YOUTUBE_THUMB_W . 'x' . YOUTUBE_THUMB_H . ':' . $data), 0, 10);
    return 'yt-' . $videoId . '-' . $hash . '.jpg';
}

/**
 * Fetches the picture of a video from YouTube and stores it in two sizes, cut to 16:9
 * (the larger pictures of YouTube are 4:3 with black bars). Returns the file name or null.
 * $get fetches the picture instead of the web (tests): fn(string $url): ?string.
 */
function youtube_image(string $videoId, string $url, ?callable $get = null): ?string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!function_exists('imagecreatetruecolor') || !preg_match('~(^|\.)(ytimg\.com|ggpht\.com|googleusercontent\.com)$~', $host)) {
        return null;
    }
    if ($get !== null) {
        $body = $get($url);
    } else {
        $res  = http_get($url, LOGO_MAX_BYTES, 10);
        $body = $res['ok'] ? $res['body'] : null;
    }
    $info = is_string($body) && strlen($body) <= LOGO_MAX_BYTES ? @getimagesizefromstring($body) : false;
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || !image_memory_ok($info[0], $info[1])) {
        return null;
    }
    $name = youtube_image_name($videoId, $body);
    if (is_file(media_dir() . '/' . $name) && is_file(media_dir() . '/' . logo_thumb_name($name))) {
        return $name;   // the same picture as before (or as in another playlist)
    }
    $src = @imagecreatefromstring($body);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    // the middle 16:9 part
    if ($w * 9 >= $h * 16) {
        $cw = intdiv($h * 16, 9);
        $ch = $h;
    } else {
        $cw = $w;
        $ch = intdiv($w * 9, 16);
    }
    $x    = intdiv($w - $cw, 2);
    $y    = intdiv($h - $ch, 2);
    if (!is_dir(media_dir()) && !@mkdir(media_dir(), 0755, true)) {
        return null;
    }
    foreach ([[$name, YOUTUBE_IMAGE_W, YOUTUBE_IMAGE_H, 84], [logo_thumb_name($name), YOUTUBE_THUMB_W, YOUTUBE_THUMB_H, 80]] as [$file, $tw, $th, $quality]) {
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $tw, $th, $cw, $ch);
        // under another name first: a request at the same time never sees half a file
        $path = media_dir() . '/' . $file;
        $tmp  = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!@imagejpeg($dst, $tmp, $quality) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return null;
        }
    }
    return $name;
}

function youtube_image_remove(?string $file): void
{
    if ($file !== null && $file !== '') {
        @unlink(media_dir() . '/' . basename($file));
        @unlink(media_dir() . '/' . logo_thumb_name($file));
    }
}

/* ---------- Sync ---------- */

/**
 * Fetches the playlist and brings the stored videos up to date: new ones in, the gone
 * ones out (with their pictures), order and titles as on YouTube. A failure keeps the
 * last list and is shown in the admin. Returns ['ok' => bool, 'info' => text].
 */
function youtube_sync(int $listId, ?callable $api = null, ?callable $get = null): array
{
    @set_time_limit(300);
    $row = station_find($listId);
    if ($row === null || $row['kind'] !== 'youtube') {
        return ['ok' => false, 'info' => 'Nicht gefunden.'];
    }
    try {
        $playlistId = youtube_playlist_id((string) $row['stream_url']) ?? throw new YoutubeError('Keine gültige Playlist-Adresse.');
        [$videos, $skipped] = youtube_fetch($playlistId, $api);
    } catch (YoutubeError $e) {
        station_set_check($listId, false, $e->getMessage());
        return ['ok' => false, 'info' => $e->getMessage()];
    }

    $old = [];
    foreach (q('SELECT video_id, image, image_at FROM r1_youtube_videos WHERE list_id = ?', [$listId])->fetchAll() as $r) {
        $old[$r['video_id']] = $r;
    }
    $keep = array_column($videos, 'video_id');
    foreach ($old as $vid => $r) {
        if (!in_array((string) $vid, $keep, true)) {
            q('DELETE FROM r1_youtube_videos WHERE list_id = ? AND video_id = ?', [$listId, (string) $vid]);
            if (!youtube_image_in_use($r['image'], $listId, (string) $vid)) {
                youtube_image_remove($r['image']);
            }
        }
    }
    $now = time();
    foreach ($videos as $pos => $v) {
        $prev  = $old[$v['video_id']] ?? null;
        $image = $prev['image'] ?? null;
        $at    = (int) ($prev['image_at'] ?? 0);
        if ($image === null || $at < $now - YOUTUBE_IMAGE_AGE || !is_file(media_dir() . '/' . $image)) {
            $fresh = $v['thumb'] !== '' ? youtube_image($v['video_id'], $v['thumb'], $get) : null;
            if ($fresh !== null) {
                // a new picture of the video: the old one goes, unless another playlist still shows it
                if ($image !== null && $image !== $fresh && !youtube_image_in_use($image, $listId, $v['video_id'])) {
                    youtube_image_remove($image);
                }
                $image = $fresh;
                $at    = $now;
            }
        }
        $set = [$v['title'], $v['channel'], $v['added_at'], $v['duration'], $pos, $image, $at];
        q('INSERT INTO r1_youtube_videos (list_id, video_id, title, channel, added_at, duration, pos, image, image_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE title = ?, channel = ?, added_at = ?, duration = ?, pos = ?, image = ?, image_at = ?',
            [$listId, $v['video_id'], ...$set, ...$set]);
    }
    $n    = count($videos);
    $info = ($n === 1 ? '1 Video' : $n . ' Videos')
        . ($skipped > 0 ? ', ' . $skipped . ' nicht abspielbar und ausgelassen' : '');
    station_set_check($listId, true, $info);
    return ['ok' => true, 'info' => $info];
}

/**
 * True if a picture is still shown by another entry than this video of this playlist:
 * another playlist with the same video may not have fetched its newer picture yet.
 */
function youtube_image_in_use(?string $file, int $listId, string $videoId): bool
{
    if ($file === null || $file === '') {
        return false;
    }
    return (bool) q('SELECT 1 FROM r1_youtube_videos WHERE image = ? AND NOT (list_id = ? AND video_id = ?) LIMIT 1',
        [$file, $listId, $videoId])->fetchColumn();
}

/**
 * Starts the daily sync of the active YouTube playlists that are due, after the
 * response. Called when the web app is rendered; one request claims each playlist.
 */
function youtube_refresh_due(): void
{
    $now = time();
    foreach (q("SELECT id, checked_at, check_ok FROM r1_stations WHERE kind = 'youtube' AND active = 1")->fetchAll() as $r) {
        $last = $r['checked_at'] !== null ? (strtotime((string) $r['checked_at']) ?: 0) : 0;
        $wait = (int) $r['check_ok'] === 1 ? YOUTUBE_EVERY : YOUTUBE_RETRY;
        if ($last > $now - $wait) {
            continue;
        }
        $claimed = q('UPDATE r1_stations SET checked_at = ? WHERE id = ? AND checked_at <=> ?',
            [now_sql(), (int) $r['id'], $r['checked_at']])->rowCount() === 1;
        if ($claimed) {
            $id = (int) $r['id'];
            after_response('youtube ' . $id, static fn() => youtube_sync($id));
        }
    }
}

/** The stored videos of a playlist in their order. */
function youtube_videos(int $listId): array
{
    return q('SELECT * FROM r1_youtube_videos WHERE list_id = ? ORDER BY pos, id', [$listId])->fetchAll();
}

/** Number of stored videos per playlist: [list id => n]. */
function youtube_counts(): array
{
    $out = [];
    foreach (q('SELECT list_id, COUNT(*) AS n FROM r1_youtube_videos GROUP BY list_id')->fetchAll() as $r) {
        $out[(int) $r['list_id']] = (int) $r['n'];
    }
    return $out;
}

/** Picture of a playlist: its own cover, otherwise a grid of the first four videos. */
function youtube_cover(array $row): ?string
{
    if ($row['logo'] && is_file(media_dir() . '/' . basename($row['logo']))) {
        return $row['logo'];
    }
    $images = [];
    foreach (youtube_videos((int) $row['id']) as $v) {
        if ($v['image'] && is_file(media_dir() . '/' . basename($v['image']))) {
            $images[] = basename($v['image']);
            if (count($images) === 4) {
                break;
            }
        }
    }
    return $images ? category_collage((int) $row['id'], $images) : null;
}

/** Removes the videos of a deleted playlist and their pictures. */
function youtube_forget(int $listId): void
{
    foreach (youtube_videos($listId) as $v) {
        if (!youtube_image_in_use($v['image'], $listId, (string) $v['video_id'])) {
            youtube_image_remove($v['image']);
        }
    }
    q('DELETE FROM r1_youtube_videos WHERE list_id = ?', [$listId]);
}

/** "1:02:13" or "47:05": the length of a video for the web app. */
function youtube_length(int $seconds): string
{
    if ($seconds <= 0) {
        return '';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}
