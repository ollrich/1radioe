<?php
/**
 * Stations, playlists (kind = 'playlist') and categories (kind = 'category'): reading,
 * validation, saving, ordering. A category is a folder in Sonos; streams and playlists
 * belong to at most one category (category_id), everything else stays at the top level.
 * One sort column orders both the top level and the content of each category.
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
    $kind        = $existing['kind'] ?? (in_array($in['kind'] ?? '', ['playlist', 'category'], true) ? $in['kind'] : 'stream');
    // website: kept as it is when the field is not sent (e.g. by an older admin)
    $website     = array_key_exists('website', $in) ? trim((string) $in['website']) : (string) ($existing['website'] ?? '');
    // posting (1.9): kept as they are when not sent
    $social      = array_key_exists('social', $in) ? (!empty($in['social']) ? 1 : 0) : (int) ($existing['social'] ?? 1);
    $hashtags    = array_key_exists('hashtags', $in) ? social_tags_text((string) $in['hashtags']) : (string) ($existing['hashtags'] ?? '');
    if ($kind !== 'stream') {
        // playlists and categories have no stream and no website of their own
        $url      = '';
        $mime     = 'audio/mpeg';
        $website  = '';
        $hashtags = '';
    }

    $errors = [];
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
    if (!isset(MIME_TYPES[$mime])) {
        $errors['mime'] = 'Bitte ein Format wählen oder den Stream testen.';
    }
    // category: kept as it is when the field is not sent, categories themselves never nest
    $categoryId = $existing !== null && $existing['category_id'] !== null ? (int) $existing['category_id'] : null;
    if ($kind === 'category') {
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
    $isPlaylist = (!empty($in['is_playlist']) || looks_like_playlist($url)) && $mime !== 'application/vnd.apple.mpegURL' ? 1 : 0;
    // result of a stream test the editor ran for exactly this address (optional)
    $check = $kind === 'stream' && is_array($in['check'] ?? null) && isset($in['check']['ok'])
        ? [(bool) $in['check']['ok'], clean_text((string) ($in['check']['info'] ?? '')),
           isset($in['check']['https']) && is_bool($in['check']['https']) ? $in['check']['https'] : null]
        : null;

    if ($id === null) {
        q('INSERT INTO r1_stations (smapi_id, kind, category_id, title, description, website, social, hashtags, stream_url, mime, is_playlist, active, sort, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            station_make_smapi_id($title), $kind, $categoryId, $title, $description, $website, $social, $hashtags, $url, $mime, $isPlaylist, $active, station_next_sort(), now_sql(), now_sql(),
        ]);
        $id = (int) db()->lastInsertId();
    } else {
        $old = $existing ?? throw new InputError([], 'Sender nicht gefunden.');
        q('UPDATE r1_stations SET category_id = ?, title = ?, description = ?, website = ?, social = ?, hashtags = ?, stream_url = ?, mime = ?, is_playlist = ?, active = ?, updated_at = ? WHERE id = ?',
            [$categoryId, $title, $description, $website, $social, $hashtags, $url, $mime, $isPlaylist, $active, now_sql(), $id]);
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

/** Moves streams and playlists into a category ($categoryId) or to the top level (null), at the end. */
function stations_assign(?int $categoryId, array $ids): void
{
    if ($categoryId !== null && (station_find($categoryId)['kind'] ?? '') !== 'category') {
        throw new InputError([], 'Kategorie nicht gefunden.');
    }
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        q("UPDATE r1_stations SET category_id = ?, sort = ? WHERE id = ? AND kind <> 'category'", [$categoryId, station_next_sort(), $id]);
    }
    catalog_bump();
}

/**
 * The entries Sonos shows at the top level ($categoryId null) or inside a category:
 * active, playlists only with tracks, categories only with something to show.
 * $rows: stations_all() in display order, $stats: playlist_stats().
 */
function sonos_visible(array $rows, array $stats, ?int $categoryId): array
{
    $out = [];
    foreach ($rows as $s) {
        $in = $s['category_id'] !== null ? (int) $s['category_id'] : null;
        if (!$s['active'] || $in !== $categoryId) {
            continue;
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

function station_set_logo(int $id, ?string $file): void
{
    $old = station_find($id);
    if ($old !== null && $old['logo'] && $old['logo'] !== $file) {
        @unlink(media_dir() . '/' . basename($old['logo']));
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
        foreach (array_values($ids) as $pos => $id) {
            $stmt->execute([$pos + 1, (int) $id]);
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
        foreach (glob(media_dir() . '/cat' . $id . '-*.jpg') ?: [] as $collage) {
            @unlink($collage);
        }
    }
    station_set_logo($id, null);
    q('DELETE FROM r1_playlist_tracks WHERE playlist_id = ?', [$id]);
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
    return [
        'id'          => (int) $s['id'],
        'smapi_id'    => $s['smapi_id'],
        'kind'        => $s['kind'],
        'category_id' => $s['category_id'] !== null ? (int) $s['category_id'] : null,
        'has_logo'    => logo_url($s['logo'], 1) !== null,
        'tracks'      => $stats[(int) $s['id']]['tracks'] ?? 0,
        'duration'    => $stats[(int) $s['id']]['duration'] ?? 0,
        'title'       => $s['title'],
        'description' => $s['description'],
        'website'     => (string) ($s['website'] ?? ''),
        'social'      => (int) ($s['social'] ?? 1) === 1,
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
 * The address Sonos should play. Direct streams and HLS are returned as stored.
 * Playlists (.pls, .m3u) are resolved to their first stream and cached for 6 hours.
 * On failure, the last known address is used (retry after 10 minutes), or as a
 * last resort the playlist address itself.
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
    $res    = http_get($url, 65536, 4);
    $stream = $res['ok'] ? playlist_first_url($res['body']) : null;
    $until  = time() + ($stream !== null ? 6 * 3600 : 600);
    $stream ??= $hit['stream'] ?? $url;
    q('INSERT INTO r1_playlist_cache (url_hash, stream, until_ts) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE stream = ?, until_ts = ?', [$key, $stream, $until, $stream, $until]);
    return $stream;
}
