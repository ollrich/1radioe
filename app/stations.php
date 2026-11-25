<?php
/**
 * Stations, playlists (kind = 'playlist'), categories (kind = 'category') and SoundCloud
 * tracks (kind = 'soundcloud'): reading, validation, saving, ordering. A category is a
 * folder in Sonos; streams, playlists and SoundCloud tracks belong to at most one
 * category (category_id), everything else stays at the top level. One sort column
 * orders both the top level and the content of each category.
 *
 * A SoundCloud track exists only in the web app, which plays it in SoundCloud's own
 * widget. stream_url holds its address on soundcloud.com, name, cover and description
 * come from the admin. Sonos, the playlist file and stations.json leave it out: the
 * SoundCloud API terms rule out streaming it through a radio app (see README).
 *
 * A Deezer entry (kind = 'deezer') is a link to a playlist on deezer.com, web app only as
 * well: it does not play there, a note leads to Deezer.
 *
 * A YouTube playlist (kind = 'youtube', top level only) is a stack in the web app whose
 * videos come from YouTube once a day (app/youtube.php); stream_url is the playlist.
 *
 * Each station has a stable "smapi_id". Sonos stores it in favorites and alarms,
 * so it is created once from the title and never changes afterwards.
 */
declare(strict_types=1);

/** Audio formats Sonos accepts for streams, with labels for the admin. */
const MIME_TYPES = [
    'audio/mpeg'                    => 'MP3',
    'audio/aac'                     => 'AAC, HE-AAC',
    'application/ogg'               => 'Ogg Vorbis',
    'application/vnd.apple.mpegURL' => 'HLS',
    'audio/flac'                    => 'FLAC',
];

/** All stations in display order. */
function stations_all(bool $activeOnly = false): array
{
    $where = $activeOnly ? 'WHERE active = 1' : '';
    return q("SELECT * FROM r1_stations $where ORDER BY sort, id")->fetchAll();
}

function station_find(int $id): ?array
{
    return q('SELECT * FROM r1_stations WHERE id = ?', [$id])->fetch() ?: null;
}

function station_by_smapi(string $smapiId): ?array
{
    return q('SELECT * FROM r1_stations WHERE smapi_id = ?', [$smapiId])->fetch() ?: null;
}

/**
 * The stream station a player is playing, found by what the player names as its source:
 * "x-sonosapi-stream:<smapi_id>?sid=…" when Sonos plays it through 1radiœ, otherwise the
 * stream address, also behind a Sonos prefix such as "aac://" or "x-rincon-mp3radio://".
 * A playlist station (.pls, .m3u) also matches the stream it was resolved to. Home
 * Assistant reports Sonos speakers this way. Same address with the same query wins,
 * the same address with another query counts too.
 */
function station_by_play_uri(string $uri): ?array
{
    $uri = trim($uri);
    if (preg_match('~^x-sonosapi-[a-z]+:([^?]+)~i', $uri, $m)) {
        $s = station_by_smapi(rawurldecode($m[1]));
        return $s !== null && $s['kind'] === 'stream' ? $s : null;
    }
    $want = play_uri_key($uri);
    if ($want === null) {
        return null;
    }
    $loose    = null;
    $stations = q("SELECT * FROM r1_stations WHERE kind = 'stream' AND active = 1 ORDER BY sort, id")->fetchAll();
    // the streams the playlist stations resolved to, all with one query (Home Assistant reports often)
    $hashes = [];
    foreach ($stations as $s) {
        if ($s['is_playlist']) {
            $hashes[] = hash('sha256', (string) $s['stream_url']);
        }
    }
    $cached = $hashes
        ? q('SELECT url_hash, stream FROM r1_playlist_cache WHERE url_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')', $hashes)
            ->fetchAll(PDO::FETCH_KEY_PAIR)
        : [];
    foreach ($stations as $s) {
        $urls = [$s['stream_url']];
        if ($s['is_playlist']) {
            $hit = $cached[hash('sha256', (string) $s['stream_url'])] ?? null;
            if (is_string($hit) && $hit !== '') {
                $urls[] = $hit;
            }
        }
        foreach ($urls as $u) {
            $key = play_uri_key($u);
            if ($key === null) {
                continue;
            }
            if ($key[0] === $want[0]) {
                return $s;
            }
            if ($loose === null && $key[1] === $want[1]) {
                $loose = $s;
            }
        }
    }
    return $loose;
}

/**
 * The stream station with this title, for a player that names what it plays. Sonos shows
 * a station from 1radiœ as "<title> • <description>" (Home Assistant: media_channel), so
 * the part before " • " counts as well. Needed because many streams redirect, FluxFM for
 * example to a changing streaming server, and the speaker then reports that address.
 * Case does not matter.
 */
function station_by_title(string $title): ?array
{
    $title = trim($title);
    if ($title === '') {
        return null;
    }
    $want = array_unique([mb_strtolower($title), mb_strtolower(trim(explode(' • ', $title)[0]))]);
    foreach (q("SELECT * FROM r1_stations WHERE kind = 'stream' AND active = 1 ORDER BY sort, id")->fetchAll() as $s) {
        $t = mb_strtolower(trim((string) $s['title']));
        if ($t !== '' && in_array($t, $want, true)) {
            return $s;
        }
    }
    return null;
}

/**
 * A stream address in comparable form: [with query, without query], without scheme
 * (http and https count the same), host in lower case, no slash at the end.
 */
function play_uri_key(string $uri): ?array
{
    if (preg_match('~^[a-z][a-z0-9+.-]*://(https?://.+)$~i', $uri, $m)) {
        $uri = $m[1];   // aac://https://…, x-rincon-mp3radio://https://…
    } elseif (preg_match('~^x-rincon-mp3radio://(.+)$~i', $uri, $m)) {
        $uri = 'http://' . $m[1];   // older Sonos form without a scheme
    }
    $p = parse_url($uri);
    if (!is_array($p) || !isset($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
        return null;
    }
    $base = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($p['path'] ?? '', '/');
    return [$base . (($p['query'] ?? '') !== '' ? '?' . $p['query'] : ''), $base];
}

/** Public URL of a logo or cover file, or null if there is none or the file is missing. */
function logo_url(?string $file, int $levels = 0): ?string
{
    if ($file === null || $file === '' || !is_file(media_dir() . '/' . basename($file))) {
        return null;
    }
    return media_url($levels) . '/' . rawurlencode($file);
}

/** True if the URL looks like a .pls or .m3u playlist (HLS .m3u8 is not a playlist here). */
function looks_like_playlist(string $url): bool
{
    return (bool) preg_match('~\.(pls|m3u)$~i', (string) parse_url($url, PHP_URL_PATH));
}

/**
 * A SoundCloud address in the form the widget takes, https://soundcloud.com/<user>/<track>
 * (also a set, or a private link with its s-… part), without tracking parameters.
 * Short links (on.soundcloud.com) are followed once to the full address.
 * Returns null if it is no SoundCloud address.
 */
function soundcloud_url(string $url, bool $follow = true): ?string
{
    $url = trim($url);
    $p   = parse_url($url);
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['port'])) {
        return null;
    }
    $host = strtolower($p['host'] ?? '');
    if ($host === 'on.soundcloud.com' && $follow) {
        $res = http_get('https://on.soundcloud.com' . ($p['path'] ?? ''), 65536, 6);
        return $res['url'] !== $url && !str_contains((string) parse_url($res['url'], PHP_URL_HOST), 'on.soundcloud.com')
            ? soundcloud_url($res['url'], false) : null;
    }
    $path = rtrim($p['path'] ?? '', '/');
    if (!in_array($host, ['soundcloud.com', 'www.soundcloud.com', 'm.soundcloud.com'], true)
        || !preg_match('~^(/[A-Za-z0-9_-]+){2,4}$~', $path)) {
        return null;
    }
    return 'https://soundcloud.com' . $path;
}

/**
 * A Deezer address in plain form, https://www.deezer.com/playlist/<id> (also album,
 * artist, track), without language, tracking parameters and the sharer's "host" number.
 * Share links (link.deezer.com, deezer.page.link) are followed to the full address.
 * Returns null if it is no Deezer address.
 */
function deezer_url(string $url, bool $follow = true): ?string
{
    $url = trim($url);
    $p   = parse_url($url);
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['port'])) {
        return null;
    }
    $host = strtolower($p['host'] ?? '');
    if (in_array($host, ['link.deezer.com', 'deezer.page.link'], true) && $follow) {
        $res = http_get('https://' . $host . ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : ''), 65536, 8, 5);
        return deezer_url($res['url'], false);
    }
    if (!in_array($host, ['deezer.com', 'www.deezer.com'], true)
        || !preg_match('~^(?:/[a-z]{2}(?:-[a-z]{2})?)?/(playlist|album|artist|track)/(\d{1,20})/?$~', $p['path'] ?? '', $m)) {
        return null;
    }
    return 'https://www.deezer.com/' . $m[1] . '/' . $m[2];
}

/** Builds a readable, unique SMAPI id from a title, e.g. "bbc-radio-1-dance". */
function station_make_smapi_id(string $title): string
{
    $map  = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'à' => 'a'];
    $slug = strtr(mb_strtolower($title, 'UTF-8'), $map);
    $slug = trim((string) preg_replace('~[^a-z0-9]+~', '-', $slug), '-');
    $slug = substr($slug !== '' ? $slug : 'station', 0, 40);
    $candidate = $slug;
    for ($n = 2; station_by_smapi($candidate) !== null; $n++) {
        $candidate = $slug . '-' . $n;
    }
    return $candidate;
}

/**
 * Validates input and creates ($id = null) or updates a station.
 * Returns the saved row. Throws InputError with messages per field.
 */
function station_save(array $in, ?int $id = null): array
{
    $title       = clean_text((string) ($in['title'] ?? ''));
    $url         = trim((string) ($in['stream_url'] ?? ''));
    $description = clean_text((string) ($in['description'] ?? ''));
    $mime        = (string) ($in['mime'] ?? '');
    $active      = !empty($in['active']) ? 1 : 0;
    $existing    = $id !== null ? station_find($id) : null;
    $kind        = $existing['kind'] ?? (in_array($in['kind'] ?? '', ['playlist', 'category', 'soundcloud', 'deezer', 'youtube'], true) ? $in['kind'] : 'stream');
    $isLink      = in_array($kind, ['soundcloud', 'deezer', 'youtube'], true);   // web app only, an address elsewhere
    // website: kept as it is when the field is not sent (e.g. by an older admin)
    $website     = array_key_exists('website', $in) ? trim((string) $in['website']) : (string) ($existing['website'] ?? '');
    // posting (1.9): kept as they are when not sent
    $social      = array_key_exists('social', $in) ? (!empty($in['social']) ? 1 : 0) : (int) ($existing['social'] ?? 1);
    $hashtags    = array_key_exists('hashtags', $in) ? social_tags_text((string) $in['hashtags']) : (string) ($existing['hashtags'] ?? '');
    // origin (1.21): ISO country code and city, for streams and SoundCloud; kept when not sent
    $country     = array_key_exists('country', $in) ? strtoupper(trim((string) $in['country'])) : (string) ($existing['country'] ?? '');
    $city        = array_key_exists('city', $in) ? clean_text((string) $in['city']) : (string) ($existing['city'] ?? '');
    // profiles (1.32, app/profiles.php): streams and MP3 playlists say for whom they are
    // visible, kept when not sent; everything else is Olli's alone
    $showOlli    = array_key_exists('show_olli', $in) ? (!empty($in['show_olli']) ? 1 : 0) : (int) ($existing['show_olli'] ?? 1);
    $showCathy   = array_key_exists('show_cathy', $in) ? (!empty($in['show_cathy']) ? 1 : 0) : (int) ($existing['show_cathy'] ?? 0);
    if (!in_array($kind, ['stream', 'playlist'], true)) {
        $showOlli  = 1;
        $showCathy = 0;
    }
    if ($kind !== 'stream') {
        // playlists, categories, SoundCloud and Deezer have no stream and no website of
        // their own; SoundCloud and Deezer keep their address, but never post (no listening status)
        $url      = $isLink ? $url : '';
        $mime     = 'audio/mpeg';
        $website  = '';
        $hashtags = '';
        $social   = $isLink ? 0 : $social;
        if ($kind !== 'soundcloud') {
            $country = '';
            $city    = '';
        }
    }

    $errors = [];
    if ($kind === 'soundcloud') {
        $sc = soundcloud_url($url);
        if ($sc === null) {
            $errors['stream_url'] = 'Bitte die Adresse eines Tracks auf soundcloud.com eingeben, etwa https://soundcloud.com/künstler/track.';
        }
        $url = $sc ?? $url;
    }
    if ($kind === 'deezer') {
        $dz = deezer_url($url);
        if ($dz === null) {
            $errors['stream_url'] = 'Bitte den Link zu einer Playlist bei Deezer eingeben, etwa https://www.deezer.com/playlist/123 oder einen Teilen-Link von link.deezer.com.';
        }
        $url = $dz ?? $url;
    }
    if ($kind === 'youtube') {
        $yt = youtube_playlist_id($url);
        if ($yt === null) {
            $errors['stream_url'] = 'Bitte die Adresse einer Playlist auf YouTube eingeben, etwa https://www.youtube.com/playlist?list=PL…';
        }
        $url = $yt !== null ? youtube_playlist_url($yt) : $url;
    }
    if ($title === '' || mb_strlen($title) > 120) {
        $errors['title'] = 'Name fehlt oder ist länger als 120 Zeichen.';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($kind === 'stream' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || strlen($url) > 1000)) {
        $errors['stream_url'] = 'Bitte eine gültige http(s)-Adresse eingeben.';
    }
    if (mb_strlen($description) > 500) {
        $errors['description'] = 'Höchstens 500 Zeichen.';
    }
    $webScheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
    if ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL) || !in_array($webScheme, ['http', 'https'], true) || strlen($website) > 500)) {
        $errors['website'] = 'Bitte eine gültige http(s)-Adresse eingeben.';
    }
    if (mb_strlen($hashtags) > 255) {
        $errors['hashtags'] = 'Höchstens 255 Zeichen.';
    }
    if ($country !== '' && !preg_match('~^[A-Z]{2}$~', $country)) {
        $errors['country'] = 'Bitte ein Land aus der Liste wählen.';
    }
    if (mb_strlen($city) > 80) {
        $errors['city'] = 'Höchstens 80 Zeichen.';
    }
    if (!isset(MIME_TYPES[$mime])) {
        $errors['mime'] = 'Bitte ein Format wählen oder den Stream testen.';
    }
    if ($showOlli === 0 && $showCathy === 0) {
        $errors['show_olli'] = 'Bitte mindestens ein Profil wählen, sonst sieht ihn niemand.';
    }
    // category: kept as it is when the field is not sent, categories themselves never nest
    // (nor do YouTube playlists, which are stacks of their own)
    $categoryId = $existing !== null && $existing['category_id'] !== null ? (int) $existing['category_id'] : null;
    if ($kind === 'category' || $kind === 'youtube') {
        $categoryId = null;
    } elseif (array_key_exists('category_id', $in)) {
        $categoryId = (int) $in['category_id'] ?: null;
        if ($categoryId !== null && (station_find($categoryId)['kind'] ?? '') !== 'category') {
            $errors['category_id'] = 'Kategorie nicht gefunden.';
        }
    }
    if ($errors) {
        throw new InputError($errors);
    }
    $isPlaylist = $kind === 'stream' && (!empty($in['is_playlist']) || looks_like_playlist($url)) && $mime !== 'application/vnd.apple.mpegURL' ? 1 : 0;
    // result of a stream test the editor ran for exactly this address (optional)
    $check = $kind === 'stream' && is_array($in['check'] ?? null) && isset($in['check']['ok'])
        ? [(bool) $in['check']['ok'], clean_text((string) ($in['check']['info'] ?? '')),
           isset($in['check']['https']) && is_bool($in['check']['https']) ? $in['check']['https'] : null]
        : null;

    if ($id === null) {
        q('INSERT INTO r1_stations (smapi_id, kind, category_id, title, description, website, country, city, social, hashtags, stream_url, mime, is_playlist, active, show_olli, show_cathy, sort, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            station_make_smapi_id($title), $kind, $categoryId, $title, $description, $website, $country, $city, $social, $hashtags, $url, $mime, $isPlaylist, $active, $showOlli, $showCathy, station_next_sort(), now_sql(), now_sql(),
        ]);
        $id = (int) db()->lastInsertId();
    } else {
        $old = $existing ?? throw new InputError([], 'Sender nicht gefunden.');
        q('UPDATE r1_stations SET category_id = ?, title = ?, description = ?, website = ?, country = ?, city = ?, social = ?, hashtags = ?, stream_url = ?, mime = ?, is_playlist = ?, active = ?, show_olli = ?, show_cathy = ?, updated_at = ? WHERE id = ?',
            [$categoryId, $title, $description, $website, $country, $city, $social, $hashtags, $url, $mime, $isPlaylist, $active, $showOlli, $showCathy, now_sql(), $id]);
        if (($old['category_id'] !== null ? (int) $old['category_id'] : null) !== $categoryId) {
            q('UPDATE r1_stations SET sort = ? WHERE id = ?', [station_next_sort(), $id]);   // moved: goes to the end
        }
        if ($old['stream_url'] !== $url) {
            // the cached playlist entry and the last test belong to the old address
            q('DELETE FROM r1_playlist_cache WHERE url_hash = ?', [hash('sha256', $old['stream_url'])]);
            q('UPDATE r1_stations SET check_ok = NULL, check_info = NULL, https_ok = NULL, checked_at = NULL WHERE id = ?', [$id]);
        }
    }
    if ($check !== null) {
        station_set_check($id, $check[0], $check[1], $check[2]);
    }
    catalog_bump();
    return station_find($id);
}

/** Sort value behind everything else. */
function station_next_sort(): int
{
    return (int) q('SELECT COALESCE(MAX(sort), 0) + 1 FROM r1_stations')->fetchColumn();
}

/** Moves streams, playlists and SoundCloud tracks into a category ($categoryId) or to the top level (null), at the end. */
function stations_assign(?int $categoryId, array $ids): void
{
    if ($categoryId !== null && (station_find($categoryId)['kind'] ?? '') !== 'category') {
        throw new InputError([], 'Kategorie nicht gefunden.');
    }
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        q("UPDATE r1_stations SET category_id = ?, sort = ? WHERE id = ? AND kind NOT IN ('category', 'youtube')", [$categoryId, station_next_sort(), $id]);
    }
    catalog_bump();
}

/**
 * The entries Sonos shows at the top level ($categoryId null) or inside a category:
 * active, playlists only with tracks, categories only with something to show,
 * never SoundCloud, Deezer or YouTube (web app only), never Cathy's alone (Sonos is Olli's).
 * $rows: stations_all() in display order, $stats: playlist_stats().
 */
function sonos_visible(array $rows, array $stats, ?int $categoryId): array
{
    $out = [];
    foreach ($rows as $s) {
        $in = $s['category_id'] !== null ? (int) $s['category_id'] : null;
        if (!$s['active'] || $in !== $categoryId || in_array($s['kind'], ['soundcloud', 'deezer', 'youtube'], true)) {
            continue;
        }
        if ($s['kind'] !== 'category' && !profile_visible($s, 'olli')) {
            continue;   // Cathy's alone: only in her app
        }
        if ($s['kind'] === 'playlist' && ($stats[(int) $s['id']]['tracks'] ?? 0) === 0) {
            continue;
        }
        if ($s['kind'] === 'category' && !sonos_visible($rows, $stats, (int) $s['id'])) {
            continue;
        }
        $out[] = $s;
    }
    return $out;
}

/**
 * Picture of a category: its own logo, otherwise a grid of the first four logos inside.
 * Returns a file name in the media folder or null.
 */
function category_cover(array $category, array $rows): ?string
{
    if ($category['logo'] && is_file(media_dir() . '/' . basename($category['logo']))) {
        return $category['logo'];
    }
    $logos = [];
    foreach ($rows as $s) {
        if ((int) ($s['category_id'] ?? 0) === (int) $category['id'] && $s['active'] && $s['logo']
            && is_file(media_dir() . '/' . basename($s['logo']))) {
            $logos[] = basename($s['logo']);
            if (count($logos) === 4) {
                break;
            }
        }
    }
    return $logos ? category_collage((int) $category['id'], $logos) : null;
}

function station_set_active(int $id, bool $active): void
{
    q('UPDATE r1_stations SET active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, now_sql(), $id]);
    catalog_bump();
}

/**
 * Stores the result of a stream test. $https: can a browser on an HTTPS page play
 * the stream (true), only via plain HTTP (false), unknown (null).
 */
function station_set_check(int $id, bool $ok, string $info, ?bool $https = null): void
{
    q('UPDATE r1_stations SET check_ok = ?, check_info = ?, https_ok = ?, checked_at = ? WHERE id = ?',
        [$ok ? 1 : 0, mb_substr($info, 0, 255), $https === null ? null : ($https ? 1 : 0), now_sql(), $id]);
}

/* ---------- Daily test of the streams ----------
 * The same test as "Alle Streams prüfen" in the admin, on the server once a day for each
 * active stream, so a broken or moved stream shows its red dot before a listener finds
 * it. No cron on the host: a few streams per request (web app, api/status), after the
 * response, each claimed by one request only (as the YouTube sync). */

const STATION_CHECK_EVERY = 86400;   // a working stream once a day
const STATION_CHECK_RETRY = 7200;    // a failed one again after two hours, it may be back
const STATION_CHECK_BATCH = 3;       // streams per request; one test takes up to 8 s, with HTTPS 16 s

/** Claims the streams that are due and tests them after the response. */
function stations_check_due(): void
{
    $claimed = stations_check_claim(time());
    if ($claimed) {
        after_response('stations check', static function () use ($claimed): void {
            foreach ($claimed as $id => $url) {
                station_check_run($id, $url);
            }
        });
    }
}

/**
 * The active streams that are due, at most STATION_CHECK_BATCH, never tested first, then
 * the oldest test. Each is claimed by setting checked_at, so a request at the same time
 * takes others. Returns [station id => stream address].
 */
function stations_check_claim(int $now): array
{
    $rows = q("SELECT id, stream_url, checked_at FROM r1_stations
               WHERE kind = 'stream' AND active = 1
                 AND (checked_at IS NULL OR checked_at < IF(check_ok = 0, ?, ?))
               ORDER BY checked_at IS NOT NULL, checked_at, id
               LIMIT " . STATION_CHECK_BATCH,
        [date('Y-m-d H:i:s', $now - STATION_CHECK_RETRY), date('Y-m-d H:i:s', $now - STATION_CHECK_EVERY)])->fetchAll();
    $claimed = [];
    foreach ($rows as $r) {
        if (q('UPDATE r1_stations SET checked_at = ? WHERE id = ? AND checked_at <=> ?', [now_sql(), (int) $r['id'], $r['checked_at']])->rowCount() === 1) {
            $claimed[(int) $r['id']] = (string) $r['stream_url'];
        }
    }
    return $claimed;
}

/**
 * Tests one stream and stores the result, as the admin's test does. A failed test is
 * repeated once right away: a short hiccup should not turn the dot red. Nothing is
 * stored if the address was changed in the meantime. $probe stands in for the network
 * in tests: fn(string $url): array as probe_stream() plus probe_https().
 */
function station_check_run(int $id, string $url, ?callable $probe = null): ?array
{
    $probe ??= static function (string $u): array {
        $r = probe_stream($u);
        return $r + probe_https($u, $r);
    };
    $result = $probe($url);
    if (empty($result['ok'])) {
        $result = $probe($url);
    }
    $station = station_find($id);
    if ($station === null || $station['stream_url'] !== $url) {
        return null;
    }
    station_set_check($id, !empty($result['ok']), (string) ($result['info'] ?? ''), $result['https'] ?? null);
    return $result;
}

function station_set_logo(int $id, ?string $file): void
{
    $old = station_find($id);
    if ($old !== null && $old['logo'] && $old['logo'] !== $file) {
        @unlink(media_dir() . '/' . basename($old['logo']));
        @unlink(media_dir() . '/' . logo_thumb_name($old['logo']));   // the web app's small picture
    }
    q('UPDATE r1_stations SET logo = ?, updated_at = ? WHERE id = ?', [$file, now_sql(), $id]);
    catalog_bump();
}

/** Saves a new order. $ids: station ids in the desired order. */
function stations_reorder(array $ids): void
{
    $stmt = db()->prepare('UPDATE r1_stations SET sort = ? WHERE id = ?');
    db()->beginTransaction();
    try {
        // each station once, at its first place (a list with doubles would write a row twice)
        foreach (array_values(array_unique(array_map('intval', $ids))) as $pos => $id) {
            $stmt->execute([$pos + 1, $id]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    catalog_bump();
}

function station_delete(int $id): void
{
    $s = station_find($id);
    if ($s === null) {
        return;
    }
    if ($s['kind'] === 'category') {
        // the content stays and moves back to the top level
        q('UPDATE r1_stations SET category_id = NULL WHERE category_id = ?', [$id]);
    }
    if ($s['kind'] === 'youtube') {
        youtube_forget($id);   // its videos and their pictures
    }
    if ($s['kind'] === 'category' || $s['kind'] === 'youtube') {
        foreach (glob(media_dir() . '/cat' . $id . '-*.jpg') ?: [] as $collage) {
            @unlink($collage);
        }
    }
    station_set_logo($id, null);
    q('DELETE FROM r1_playlist_tracks WHERE playlist_id = ?', [$id]);
    q('DELETE FROM r1_listen_status WHERE station_id = ?', [$id]);
    q('DELETE FROM r1_now_playing WHERE station_id = ?', [$id]);
    q('DELETE FROM r1_stations WHERE id = ?', [$id]);
    catalog_bump();
}

/** Station, playlist or category for the admin (JSON). $stats: playlist_stats(), $rows: stations_all(). */
function station_public(array $s, ?array $stats = null, ?array $rows = null): array
{
    $stats ??= $s['kind'] === 'playlist' ? playlist_stats() : [];
    $cover   = null;
    if ($s['kind'] === 'category') {
        $rows ??= stations_all();
        $cover = category_cover($s, $rows);
    }
    if ($s['kind'] === 'youtube') {
        $cover = youtube_cover($s);
    }
    return [
        'id'          => (int) $s['id'],
        'smapi_id'    => $s['smapi_id'],
        'kind'        => $s['kind'],
        'category_id' => $s['category_id'] !== null ? (int) $s['category_id'] : null,
        'has_logo'    => logo_url($s['logo'], 1) !== null,
        'tracks'      => $stats[(int) $s['id']]['tracks'] ?? 0,
        'videos'      => $s['kind'] === 'youtube' ? (int) q('SELECT COUNT(*) FROM r1_youtube_videos WHERE list_id = ?', [(int) $s['id']])->fetchColumn() : 0,
        'duration'    => $stats[(int) $s['id']]['duration'] ?? 0,
        'title'       => $s['title'],
        'description' => $s['description'],
        'website'     => (string) ($s['website'] ?? ''),
        'country'     => (string) ($s['country'] ?? ''),
        'city'        => (string) ($s['city'] ?? ''),
        'social'      => (int) ($s['social'] ?? 1) === 1,
        'show_olli'   => profile_visible($s, 'olli'),
        'show_cathy'  => profile_visible($s, 'cathy'),
        'hashtags'    => (string) ($s['hashtags'] ?? ''),
        'stream_url'  => $s['stream_url'],
        'mime'        => $s['mime'],
        'is_playlist' => (bool) $s['is_playlist'],
        'logo_url'    => logo_url($cover ?? $s['logo'], 1),
        'active'      => (bool) $s['active'],
        'check_ok'    => $s['check_ok'] === null ? null : (bool) $s['check_ok'],
        'check_info'  => $s['check_info'],
        'https_ok'    => ($s['https_ok'] ?? null) === null ? null : (bool) $s['https_ok'],
        'checked_at'  => $s['checked_at'],
    ];
}

/**
 * The address to play. Direct streams and HLS are returned as stored. Playlists (.pls,
 * .m3u) are resolved to their first stream and cached for 6 hours. Once that is over,
 * the known address is answered at once and the playlist is fetched again after the
 * response (only by one request), so no visitor and no speaker waits for a station's
 * server. Without a known address, or on a server that cannot answer first, it is
 * fetched right away as before.
 */
function station_playback_url(array $s): string
{
    $url = $s['stream_url'];
    if (!$s['is_playlist']) {
        return $url;
    }
    $key = hash('sha256', $url);
    $hit = q('SELECT stream, until_ts FROM r1_playlist_cache WHERE url_hash = ?', [$key])->fetch() ?: null;
    if ($hit !== null && (int) $hit['until_ts'] > time()) {
        return $hit['stream'];
    }
    if ($hit !== null && can_answer_first()) {
        // claim the refresh for two minutes; parallel requests keep the known address
        $claimed = q('UPDATE r1_playlist_cache SET until_ts = ? WHERE url_hash = ? AND until_ts = ?',
            [time() + 120, $key, (int) $hit['until_ts']])->rowCount() === 1;
        if ($claimed) {
            $known = (string) $hit['stream'];
            after_response('playlist ' . $key, static fn() => playlist_resolve($url, $known));
        }
        return $hit['stream'];
    }
    return playlist_resolve($url, $hit['stream'] ?? null);
}

/**
 * Fetches a playlist and caches its first stream for 6 hours. On failure the known
 * address stays (retry after 10 minutes), or as a last resort the playlist address.
 */
function playlist_resolve(string $url, ?string $known): string
{
    $res    = http_get($url, 65536, 4);
    $stream = $res['ok'] ? playlist_first_url($res['body']) : null;
    $until  = time() + ($stream !== null ? 6 * 3600 : 600);
    $stream ??= $known ?? $url;
    q('INSERT INTO r1_playlist_cache (url_hash, stream, until_ts) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE stream = ?, until_ts = ?', [hash('sha256', $url), $stream, $until, $stream, $until]);
    return $stream;
}
