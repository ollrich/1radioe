<?php
/**
 * Stations: reading, validation, saving, ordering.
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

/** Public logo URL of a station, or null. */
function logo_url(?string $file, int $levels = 0): ?string
{
    return ($file !== null && $file !== '') ? base_url($levels) . '/media/' . rawurlencode($file) : null;
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
    $title       = trim((string) ($in['title'] ?? ''));
    $url         = trim((string) ($in['stream_url'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $mime        = (string) ($in['mime'] ?? '');
    $active      = !empty($in['active']) ? 1 : 0;

    $errors = [];
    if ($title === '' || mb_strlen($title) > 120) {
        $errors['title'] = 'Name fehlt oder ist länger als 120 Zeichen.';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || strlen($url) > 1000) {
        $errors['stream_url'] = 'Bitte eine gültige http(s)-Adresse eingeben.';
    }
    if (mb_strlen($description) > 500) {
        $errors['description'] = 'Höchstens 500 Zeichen.';
    }
    if (!isset(MIME_TYPES[$mime])) {
        $errors['mime'] = 'Bitte ein Format wählen oder den Stream testen.';
    }
    if ($errors) {
        throw new InputError($errors);
    }
    $isPlaylist = (!empty($in['is_playlist']) || looks_like_playlist($url)) && $mime !== 'application/vnd.apple.mpegURL' ? 1 : 0;

    if ($id === null) {
        $sort = (int) q('SELECT COALESCE(MAX(sort), 0) + 1 FROM r1_stations')->fetchColumn();
        q('INSERT INTO r1_stations (smapi_id, title, description, stream_url, mime, is_playlist, active, sort, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            station_make_smapi_id($title), $title, $description, $url, $mime, $isPlaylist, $active, $sort, now_sql(), now_sql(),
        ]);
        $id = (int) db()->lastInsertId();
    } else {
        $old = station_find($id) ?? throw new InputError([], 'Sender nicht gefunden.');
        if ($old['stream_url'] !== $url) {
            q('DELETE FROM r1_playlist_cache WHERE url_hash = ?', [hash('sha256', $old['stream_url'])]);
        }
        q('UPDATE r1_stations SET title = ?, description = ?, stream_url = ?, mime = ?, is_playlist = ?, active = ?, updated_at = ? WHERE id = ?',
            [$title, $description, $url, $mime, $isPlaylist, $active, now_sql(), $id]);
    }
    catalog_bump();
    return station_find($id);
}

function station_set_active(int $id, bool $active): void
{
    q('UPDATE r1_stations SET active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, now_sql(), $id]);
    catalog_bump();
}

/** Stores the result of a stream test. */
function station_set_check(int $id, bool $ok, string $info): void
{
    q('UPDATE r1_stations SET check_ok = ?, check_info = ?, checked_at = ? WHERE id = ?', [$ok ? 1 : 0, mb_substr($info, 0, 255), now_sql(), $id]);
}

function station_set_logo(int $id, ?string $file): void
{
    $old = station_find($id);
    if ($old !== null && $old['logo'] && $old['logo'] !== $file) {
        @unlink(MEDIA_DIR . '/' . basename($old['logo']));
    }
    q('UPDATE r1_stations SET logo = ?, updated_at = ? WHERE id = ?', [$file, now_sql(), $id]);
    catalog_bump();
}

/** Saves a new order. $ids: station ids in the desired order. */
function stations_reorder(array $ids): void
{
    $stmt = db()->prepare('UPDATE r1_stations SET sort = ? WHERE id = ?');
    db()->beginTransaction();
    foreach (array_values($ids) as $pos => $id) {
        $stmt->execute([$pos + 1, (int) $id]);
    }
    db()->commit();
    catalog_bump();
}

function station_delete(int $id): void
{
    station_set_logo($id, null);
    q('DELETE FROM r1_stations WHERE id = ?', [$id]);
    catalog_bump();
}

/** Station data for the admin (JSON). */
function station_public(array $s): array
{
    return [
        'id'          => (int) $s['id'],
        'smapi_id'    => $s['smapi_id'],
        'title'       => $s['title'],
        'description' => $s['description'],
        'stream_url'  => $s['stream_url'],
        'mime'        => $s['mime'],
        'is_playlist' => (bool) $s['is_playlist'],
        'logo_url'    => logo_url($s['logo'], 1),
        'active'      => (bool) $s['active'],
        'check_ok'    => $s['check_ok'] === null ? null : (bool) $s['check_ok'],
        'check_info'  => $s['check_info'],
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
