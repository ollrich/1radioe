<?php
/**
 * MP3 track library and playlists.
 *
 * Tracks are stored once in media/tracks/ under random, unguessable file names
 * (Sonos speakers download them directly). Playlists are rows in r1_stations with
 * kind = 'playlist'; r1_playlist_tracks holds their tracks in order. A track can be
 * part of several playlists.
 *
 * Imports read title, artist, album and cover from ID3 tags (v1, v2.2, v2.3, v2.4)
 * and the duration from the MPEG frames (Xing/Info/VBRI header or constant bitrate).
 */
declare(strict_types=1);

const TRACK_DIR       = MEDIA_DIR . '/tracks';
const UPLOAD_DIR      = APP_DIR . '/tmp';
const INBOX_DIR       = APP_DIR . '/inbox';
const TRACK_MAX_BYTES = 300 * 1024 * 1024;

/* =====================================================================================
 * Reading MP3 files
 * ===================================================================================== */

/**
 * Parses an MPEG audio layer III frame header at $i.
 * Returns [length, bitrate kbps, sample rate, samples per frame, mpeg1, mono] or null.
 */
function mp3_frame(string $d, int $i): ?array
{
    if (!isset($d[$i + 3]) || $d[$i] !== "\xFF" || (ord($d[$i + 1]) & 0xE0) !== 0xE0) {
        return null;
    }
    $b1      = ord($d[$i + 1]);
    $b2      = ord($d[$i + 2]);
    $version = ($b1 >> 3) & 3;     // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
    $layer   = ($b1 >> 1) & 3;     // 1 = layer III
    $brIndex = $b2 >> 4;
    $srIndex = ($b2 >> 2) & 3;
    if ($version === 1 || $layer !== 1 || $brIndex === 0 || $brIndex === 15 || $srIndex === 3) {
        return null;
    }
    $mpeg1 = $version === 3;
    $rates = $mpeg1 ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320] : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
    $srate = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]][$version][$srIndex];
    $spf   = $mpeg1 ? 1152 : 576;
    $len   = intdiv(intdiv($spf, 8) * $rates[$brIndex] * 1000, $srate) + (($b2 >> 1) & 1);
    return [$len, $rates[$brIndex], $srate, $spf, $mpeg1, ((ord($d[$i + 3]) >> 6) & 3) === 3];
}

/** Size of a leading ID3v2 tag including header and footer, 0 if none. */
function id3v2_size(string $head): int
{
    if (strlen($head) < 10 || !str_starts_with($head, 'ID3')) {
        return 0;
    }
    $size = (ord($head[6]) << 21) | (ord($head[7]) << 14) | (ord($head[8]) << 7) | ord($head[9]);
    return 10 + $size + ((ord($head[5]) & 0x10) ? 10 : 0);
}

/**
 * Checks that a file is an MP3 and determines duration and bitrate.
 * Returns ['duration' => seconds, 'bitrate' => kbps] or null if it is not an MP3.
 */
function mp3_info(string $path): ?array
{
    $size = filesize($path);
    $fh   = fopen($path, 'rb');
    if (!$fh || !$size) {
        return null;
    }
    $start = id3v2_size((string) fread($fh, 10));
    fseek($fh, $start);
    $data = (string) fread($fh, 131072);
    fseek($fh, max(0, $size - 128));
    $hasV1 = fread($fh, 3) === 'TAG';
    fclose($fh);

    // first frame that is followed by a second valid frame
    for ($i = 0, $n = strlen($data) - 4; $i < $n; $i++) {
        $f = mp3_frame($data, $i);
        if ($f === null || $f[0] < 24 || mp3_frame($data, $i + $f[0]) === null) {
            continue;
        }
        [$len, $kbps, $srate, $spf, $mpeg1, $mono] = $f;
        $side   = $mpeg1 ? ($mono ? 17 : 32) : ($mono ? 9 : 17);
        $frames = 0;
        $xing   = substr($data, $i + 4 + $side, 12);
        if ((str_starts_with($xing, 'Xing') || str_starts_with($xing, 'Info')) && (unpack('N', substr($xing, 4, 4))[1] & 1)) {
            $frames = unpack('N', substr($xing, 8, 4))[1];
        } elseif (substr($data, $i + 36, 4) === 'VBRI') {
            $frames = unpack('N', substr($data, $i + 36 + 14, 4))[1];
        }
        if ($frames > 0) {
            $duration = $frames * $spf / $srate;
            $kbps     = (int) round(($size - $start - $i) * 8 / max(1, $duration) / 1000);
        } else {
            $duration = ($size - $start - $i - ($hasV1 ? 128 : 0)) * 8 / ($kbps * 1000);
        }
        return ['duration' => (int) round($duration), 'bitrate' => $kbps];
    }
    return null;
}

/** Decodes an ID3 text payload with its encoding byte. */
function id3_text(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $enc  = ord($raw[0]);
    $text = substr($raw, 1);
    $text = match ($enc) {
        0       => mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1'),
        1       => mb_convert_encoding($text, 'UTF-8', str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE'),
        2       => mb_convert_encoding($text, 'UTF-8', 'UTF-16BE'),
        default => $text,
    };
    $text = (string) preg_replace('~^\x{FEFF}~u', '', $text);
    $parts = array_filter(array_map('trim', explode("\0", $text)), static fn($p) => $p !== '');
    return trim(implode(', ', $parts));
}

/** Splits "description\0data" for the given ID3 text encoding. Returns the data part. */
function id3_after_terminated(string $s, int $enc): string
{
    if ($enc === 1 || $enc === 2) {
        for ($i = 0, $n = strlen($s) - 1; $i < $n; $i += 2) {
            if ($s[$i] === "\0" && $s[$i + 1] === "\0") {
                return substr($s, $i + 2);
            }
        }
        return '';
    }
    $p = strpos($s, "\0");
    return $p === false ? '' : substr($s, $p + 1);
}

/**
 * Reads title, artist, album and the cover image (binary) from ID3 tags.
 * Missing values are empty strings / null.
 */
function id3_read(string $path): array
{
    $out = ['title' => '', 'artist' => '', 'album' => '', 'cover' => null];
    $fh  = fopen($path, 'rb');
    if (!$fh) {
        return $out;
    }
    $head = (string) fread($fh, 10);
    if (id3v2_size($head) > 0) {
        $ver   = ord($head[3]);
        $flags = ord($head[5]);
        $want  = min(id3v2_size($head) - 10, 16 * 1024 * 1024);
        $tag   = $want > 0 ? (string) fread($fh, $want) : '';
        if ($ver < 4 && ($flags & 0x80)) {
            $tag = str_replace("\xFF\x00", "\xFF", $tag);   // whole-tag unsynchronisation
        }
        $pos = 0;
        if ($ver >= 3 && ($flags & 0x40) && strlen($tag) >= 4) {   // extended header
            $pos = $ver === 3 ? 4 + unpack('N', substr($tag, 0, 4))[1]
                : ((ord($tag[0]) << 21) | (ord($tag[1]) << 14) | (ord($tag[2]) << 7) | ord($tag[3]));
        }
        $idLen  = $ver === 2 ? 3 : 4;
        $hdrLen = $ver === 2 ? 6 : 10;
        $covers = [];
        while ($pos + $hdrLen <= strlen($tag)) {
            $id = substr($tag, $pos, $idLen);
            if (!preg_match('~^[A-Z0-9]+$~', $id)) {
                break;   // padding reached
            }
            if ($ver === 2) {
                $size = (ord($tag[$pos + 3]) << 16) | (ord($tag[$pos + 4]) << 8) | ord($tag[$pos + 5]);
                $fmt  = 0;
            } elseif ($ver === 3) {
                $size = unpack('N', substr($tag, $pos + 4, 4))[1];
                $fmt  = ord($tag[$pos + 9]);
            } else {
                $size = (ord($tag[$pos + 4]) << 21) | (ord($tag[$pos + 5]) << 14) | (ord($tag[$pos + 6]) << 7) | ord($tag[$pos + 7]);
                $fmt  = ord($tag[$pos + 9]);
            }
            $data = substr($tag, $pos + $hdrLen, $size);
            $pos += $hdrLen + $size;
            if ($size <= 0) {
                continue;
            }
            // skip compressed or encrypted frames
            if (($ver === 3 && ($fmt & 0xC0)) || ($ver === 4 && ($fmt & 0x0C))) {
                continue;
            }
            if ($ver === 3 && ($fmt & 0x20)) {
                $data = substr($data, 1);   // grouping identity
            }
            if ($ver === 4) {
                if ($fmt & 0x40) {
                    $data = substr($data, 1);   // grouping identity
                }
                if ($fmt & 0x01) {
                    $data = substr($data, 4);   // data length indicator
                }
                if ($fmt & 0x02) {
                    $data = str_replace("\xFF\x00", "\xFF", $data);
                }
            }
            switch ($id) {
                case 'TIT2': case 'TT2':
                    $out['title'] = $out['title'] ?: id3_text($data);
                    break;
                case 'TPE1': case 'TP1':
                    $out['artist'] = $out['artist'] ?: id3_text($data);
                    break;
                case 'TALB': case 'TAL':
                    $out['album'] = $out['album'] ?: id3_text($data);
                    break;
                case 'APIC': case 'PIC':
                    $enc = ord($data[0] ?? "\0");
                    if ($id === 'PIC') {
                        $type = ord($data[4] ?? "\0");
                        $img  = id3_after_terminated(substr($data, 5), $enc);
                    } else {
                        $mimeEnd = strpos($data, "\0", 1);
                        if ($mimeEnd === false) {
                            break;
                        }
                        $type = ord($data[$mimeEnd + 1] ?? "\0");
                        $img  = id3_after_terminated(substr($data, $mimeEnd + 2), $enc);
                    }
                    if ($img !== '') {
                        $covers[$type] ??= $img;
                    }
                    break;
            }
        }
        $out['cover'] = $covers[3] ?? (reset($covers) ?: null);   // 3 = front cover
    }
    // ID3v1 as fallback for missing texts
    $size = filesize($path);
    if ($size > 128) {
        fseek($fh, $size - 128);
        $v1 = (string) fread($fh, 128);
        if (str_starts_with($v1, 'TAG')) {
            $field = static fn(int $o) => trim(mb_convert_encoding(rtrim(substr($v1, $o, 30), "\0 "), 'UTF-8', 'ISO-8859-1'));
            $out['title']  = $out['title'] ?: $field(3);
            $out['artist'] = $out['artist'] ?: $field(33);
            $out['album']  = $out['album'] ?: $field(63);
        }
    }
    fclose($fh);
    return $out;
}

/* =====================================================================================
 * Library
 * ===================================================================================== */

function track_find(int $id): ?array
{
    return q('SELECT * FROM r1_tracks WHERE id = ?', [$id])->fetch() ?: null;
}

/** All tracks, newest first, with the number of playlists they are in. */
function tracks_all(): array
{
    return q('SELECT t.*, (SELECT COUNT(*) FROM r1_playlist_tracks p WHERE p.track_id = t.id) AS playlists
              FROM r1_tracks t ORDER BY t.created_at DESC, t.id DESC')->fetchAll();
}

function track_url(array $t): string
{
    return base_url() . '/media/tracks/' . rawurlencode($t['file']);
}

/** Cover of a track, falling back to the cover of a playlist it belongs to. */
function track_cover_file(array $t, ?array $playlist = null): ?string
{
    if ($t['cover']) {
        return $t['cover'];
    }
    if ($playlist !== null) {
        return $playlist['logo'] ?: null;
    }
    $logo = q("SELECT s.logo FROM r1_playlist_tracks p JOIN r1_stations s ON s.id = p.playlist_id
               WHERE p.track_id = ? AND s.logo IS NOT NULL ORDER BY s.sort LIMIT 1", [$t['id']])->fetchColumn();
    return $logo ?: null;
}

/** Track data for the admin (JSON). */
function track_public(array $t): array
{
    return [
        'id'        => (int) $t['id'],
        'title'     => $t['title'],
        'artist'    => $t['artist'],
        'album'     => $t['album'],
        'duration'  => (int) $t['duration'],
        'bitrate'   => (int) $t['bitrate'],
        'size'      => (int) $t['size'],
        'orig_name' => $t['orig_name'],
        'cover_url' => logo_url($t['cover'], 1),
        'audio_url' => base_url(1) . '/media/tracks/' . rawurlencode($t['file']),
        'playlists' => (int) ($t['playlists'] ?? 0),
    ];
}

/**
 * Imports an MP3 file into the library: checks it, reads tags and duration, moves it
 * to media/tracks/ and stores the cover. Returns the new track row.
 */
function track_import(string $path, string $origName): array
{
    $info = mp3_info($path);
    if ($info === null) {
        throw new InputError([], '„' . $origName . '“ ist keine MP3-Datei.');
    }
    $tags = id3_read($path);
    if (!is_dir(TRACK_DIR) && !@mkdir(TRACK_DIR, 0755, true)) {
        throw new InputError([], 'Ordner media/tracks fehlt und kann nicht angelegt werden.');
    }
    $file = bin2hex(random_bytes(16)) . '.mp3';
    $size = filesize($path);
    if (!@rename($path, TRACK_DIR . '/' . $file) && !(@copy($path, TRACK_DIR . '/' . $file) && @unlink($path))) {
        throw new InputError([], 'Datei konnte nicht gespeichert werden. Schreibrechte für media/tracks prüfen.');
    }
    @chmod(TRACK_DIR . '/' . $file, 0644);
    $cover = null;
    if ($tags['cover'] !== null) {
        try {
            $cover = logo_store($tags['cover']);
        } catch (Throwable) {
            // unreadable embedded image: import the track without cover
        }
    }
    $fallback = trim((string) preg_replace('~[_\s]+~', ' ', pathinfo($origName, PATHINFO_FILENAME)));
    q('INSERT INTO r1_tracks (file, orig_name, title, artist, album, duration, bitrate, size, cover, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $file, mb_substr($origName, 0, 255), mb_substr($tags['title'] ?: ($fallback ?: 'Unbenannt'), 0, 200),
        mb_substr($tags['artist'], 0, 200), mb_substr($tags['album'], 0, 200),
        $info['duration'], $info['bitrate'], $size, $cover, now_sql(), now_sql(),
    ]);
    return track_find((int) db()->lastInsertId());
}

/** Updates title, artist and album of a track. */
function track_save(int $id, array $in): array
{
    $title  = trim((string) ($in['title'] ?? ''));
    $artist = trim((string) ($in['artist'] ?? ''));
    $album  = trim((string) ($in['album'] ?? ''));
    $errors = [];
    if ($title === '' || mb_strlen($title) > 200) {
        $errors['title'] = 'Titel fehlt oder ist länger als 200 Zeichen.';
    }
    if (mb_strlen($artist) > 200) {
        $errors['artist'] = 'Höchstens 200 Zeichen.';
    }
    if (mb_strlen($album) > 200) {
        $errors['album'] = 'Höchstens 200 Zeichen.';
    }
    if ($errors) {
        throw new InputError($errors);
    }
    track_find($id) ?? throw new InputError([], 'Track nicht gefunden.');
    q('UPDATE r1_tracks SET title = ?, artist = ?, album = ?, updated_at = ? WHERE id = ?', [$title, $artist, $album, now_sql(), $id]);
    catalog_bump();
    return track_find($id);
}

function track_set_cover(int $id, ?string $file): void
{
    $old = track_find($id) ?? throw new InputError([], 'Track nicht gefunden.');
    if ($old['cover'] && $old['cover'] !== $file) {
        @unlink(MEDIA_DIR . '/' . basename($old['cover']));
    }
    q('UPDATE r1_tracks SET cover = ?, updated_at = ? WHERE id = ?', [$file, now_sql(), $id]);
    catalog_bump();
}

/** Deletes a track everywhere: playlists, cover and audio file. */
function track_delete(int $id): void
{
    $t = track_find($id);
    if ($t === null) {
        return;
    }
    q('DELETE FROM r1_playlist_tracks WHERE track_id = ?', [$id]);
    q('DELETE FROM r1_tracks WHERE id = ?', [$id]);
    @unlink(TRACK_DIR . '/' . basename($t['file']));
    if ($t['cover']) {
        @unlink(MEDIA_DIR . '/' . basename($t['cover']));
    }
    catalog_bump();
}

/* =====================================================================================
 * Playlists
 * ===================================================================================== */

/** A playlist row (kind = 'playlist') or null. */
function playlist_find(int $id): ?array
{
    $s = station_find($id);
    return ($s !== null && $s['kind'] === 'playlist') ? $s : null;
}

/** Tracks of a playlist in order. */
function playlist_tracks(int $playlistId): array
{
    return q('SELECT t.*, p.pos FROM r1_playlist_tracks p JOIN r1_tracks t ON t.id = p.track_id
              WHERE p.playlist_id = ? ORDER BY p.pos, t.id', [$playlistId])->fetchAll();
}

/** Replaces the tracks of a playlist (membership and order) with $trackIds. */
function playlist_set_tracks(int $playlistId, array $trackIds): void
{
    playlist_find($playlistId) ?? throw new InputError([], 'Playlist nicht gefunden.');
    $ids = array_values(array_unique(array_map('intval', $trackIds)));
    db()->beginTransaction();
    q('DELETE FROM r1_playlist_tracks WHERE playlist_id = ?', [$playlistId]);
    $stmt = db()->prepare('INSERT INTO r1_playlist_tracks (playlist_id, track_id, pos) SELECT ?, id, ? FROM r1_tracks WHERE id = ?');
    foreach ($ids as $pos => $trackId) {
        $stmt->execute([$playlistId, $pos + 1, $trackId]);
    }
    db()->commit();
    catalog_bump();
}

/** Appends tracks to the end of a playlist (tracks already in it are skipped). */
function playlist_add_tracks(int $playlistId, array $trackIds): void
{
    $current = array_map(static fn($t) => (int) $t['id'], playlist_tracks($playlistId));
    playlist_set_tracks($playlistId, array_merge($current, array_map('intval', $trackIds)));
}

/** Number of tracks and total duration per playlist id. */
function playlist_stats(): array
{
    $out = [];
    foreach (q('SELECT p.playlist_id, COUNT(*) AS n, COALESCE(SUM(t.duration), 0) AS d
                FROM r1_playlist_tracks p JOIN r1_tracks t ON t.id = p.track_id GROUP BY p.playlist_id')->fetchAll() as $r) {
        $out[(int) $r['playlist_id']] = ['tracks' => (int) $r['n'], 'duration' => (int) $r['d']];
    }
    return $out;
}

/* =====================================================================================
 * Uploads in chunks and FTP inbox
 * ===================================================================================== */

/** Starts a chunked upload. Returns its id. Also removes uploads older than a day. */
function upload_start(int $size): string
{
    if ($size <= 0 || $size > TRACK_MAX_BYTES) {
        throw new InputError([], 'Dateien dürfen höchstens 300 MB groß sein.');
    }
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) {
        throw new InputError([], 'Ordner tmp/ fehlt und kann nicht angelegt werden.');
    }
    foreach (glob(UPLOAD_DIR . '/*.part') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    $id = bin2hex(random_bytes(16));
    file_put_contents(UPLOAD_DIR . '/' . $id . '.part', '');
    return $id;
}

function upload_path(string $id): string
{
    if (!preg_match('~^[a-f0-9]{32}$~', $id) || !is_file(UPLOAD_DIR . '/' . $id . '.part')) {
        throw new InputError([], 'Upload nicht gefunden, bitte erneut versuchen.');
    }
    return UPLOAD_DIR . '/' . $id . '.part';
}

/** Appends a chunk. $offset must match the bytes received so far. */
function upload_chunk(string $id, int $offset, string $tmpFile): int
{
    $path = upload_path($id);
    clearstatcache(true, $path);
    if (filesize($path) !== $offset) {
        throw new InputError([], 'Upload unterbrochen, bitte erneut versuchen.');
    }
    if ($offset + filesize($tmpFile) > TRACK_MAX_BYTES) {
        throw new InputError([], 'Dateien dürfen höchstens 300 MB groß sein.');
    }
    $in  = fopen($tmpFile, 'rb');
    $out = fopen($path, 'ab');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    clearstatcache(true, $path);
    return filesize($path);
}

/** Completes an upload and imports it as a track. */
function upload_finish(string $id, string $name): array
{
    $path = upload_path($id);
    try {
        return track_import($path, $name);
    } finally {
        @unlink($path);
    }
}

/** MP3 files waiting in the FTP inbox. */
function inbox_files(): array
{
    return is_dir(INBOX_DIR) ? array_values(array_filter(glob(INBOX_DIR . '/*') ?: [], static fn($f) => is_file($f) && preg_match('~\.mp3$~i', $f))) : [];
}

/** Imports all MP3 files from the inbox. Returns [tracks, errors]. */
function inbox_import(): array
{
    $tracks = [];
    $errors = [];
    foreach (inbox_files() as $file) {
        try {
            $tracks[] = track_import($file, basename($file));
        } catch (InputError $e) {
            $errors[] = $e->getMessage();
        }
    }
    return [$tracks, $errors];
}
