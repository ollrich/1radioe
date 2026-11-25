<?php
/**
 * Profiles: Olli (everything public: web app, Sonos, the playlist files, stations.json
 * without a profile) and Cathy (only in the Android app). Every stream and every MP3
 * playlist says for whom it is visible (r1_stations.show_olli, show_cathy, both
 * possible); categories follow their contents. The track library is shared, a track
 * is visible through its playlists. Listening status, posting and Sonos stay Olli's.
 */
declare(strict_types=1);

const PROFILES        = ['olli' => 'Olli', 'cathy' => 'Cathy'];
const PROFILE_DEFAULT = 'olli';

/**
 * The profile asked for with ?profile=: the default without one, null for an unknown
 * one (the endpoints answer 400 then, never quietly Olli's list).
 */
function profile_from_query(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return PROFILE_DEFAULT;
    }
    return is_string($value) && isset(PROFILES[$value]) ? $value : null;
}

/** True if a stream or playlist row is visible in a profile. */
function profile_visible(array $row, string $profile): bool
{
    return (int) ($row['show_' . $profile] ?? ($profile === PROFILE_DEFAULT ? 1 : 0)) === 1;
}

/**
 * The tracks of every playlist that can play: in order, only tracks whose file exists.
 * Returns [playlist id => tracks]; playlists without such a track are missing, as in Sonos.
 */
function playlists_playable(): array
{
    $out = [];
    foreach (q('SELECT p.playlist_id, t.* FROM r1_playlist_tracks p JOIN r1_tracks t ON t.id = p.track_id
                ORDER BY p.playlist_id, p.pos, t.id')->fetchAll() as $t) {
        if (track_file_exists($t)) {
            $out[(int) $t['playlist_id']][] = $t;
        }
    }
    return $out;
}

/**
 * May this key read the playlists of $profile? 200, 401 (no key, unknown or revoked one)
 * or 403 (a key of the other profile). Every profile needs its own key: the list names
 * the direct addresses of the MP3 files.
 */
function playlists_access(string $profile, string $key): int
{
    $device = $key !== '' ? listen_device_by_token($key, 'app') : null;
    if ($device === null) {
        return 401;
    }
    return (string) ($device['profile'] ?? PROFILE_DEFAULT) === $profile ? 200 : 403;
}

/** May this device report the listening status? Only Olli's (it says "Olli hört"). */
function listen_profile_allowed(array $device): bool
{
    return (string) ($device['profile'] ?? PROFILE_DEFAULT) === PROFILE_DEFAULT;
}

/** Logo or cover block for the app's JSON, or null. The file name changes with every new picture. */
function json_picture(?string $file): ?array
{
    $url = logo_url($file, 1);
    if ($url === null) {
        return null;
    }
    $path = media_dir() . '/' . basename((string) $file);
    return ['url' => $url, 'sha256' => hash_file('sha256', $path) ?: null, 'size' => LOGO_SIZE];
}

/**
 * The MP3 playlists of a profile for the app (GET /api/playlists): in the same counting
 * of positions as the stations of stations.json (top level and inside categories), with
 * their tracks. Field names in API.md.
 */
function playlists_api(string $profile): array
{
    $catalog = public_catalog(false, $profile, true);
    $tracks  = $catalog['playable'];
    $out     = [];
    foreach ($catalog['playlists'] as $e) {
        $p     = $e['row'];
        $out[] = [
            'id'          => $p['smapi_id'],
            'name'        => $p['title'],
            'description' => (string) $p['description'],
            'category'    => $e['category']['smapi_id'] ?? null,
            'sort'        => $e['sort'],
            'cover'       => json_picture($p['logo']),
            'tracks'      => array_map(static fn(array $t): array => [
                'id'       => 'track:' . (int) $t['id'],
                'title'    => $t['title'],
                'artist'   => $t['artist'] !== '' ? $t['artist'] : null,
                'album'    => $t['album'] !== '' ? $t['album'] : null,
                'duration' => (int) $t['duration'],
                'url'      => media_url(1) . '/tracks/' . rawurlencode($t['file']),
                'cover'    => json_picture(track_cover_file($t, $p)),
            ], $tracks[(int) $p['id']] ?? []),
        ];
    }
    return $out;
}
