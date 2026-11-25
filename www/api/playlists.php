<?php
/**
 * GET /api/playlists: the MP3 playlists with their tracks, for the Android app.
 *
 * Always with a key of the profile asked for, the header X-1radioe-Token (a key from the
 * admin, Hörgeräte, made for Olli or Cathy), because the list names the direct addresses
 * of the MP3 files: no profile (Olli) with Olli's key, ?profile=cathy with Cathy's.
 * 401 without a valid key, 403 with a key of the other profile, 400 for an unknown profile.
 *
 * Positions ("sort") are in the same counting as the stations of stations.json for the
 * same profile. Empty playlists and tracks without a file are missing, as in Sonos.
 * The ETag comes from the answer itself, so any change (also of a track) is a new one.
 * Field names are documented in API.md.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    json_out(['error' => 'GET only'], 405);
}

$profile = profile_from_query($_GET['profile'] ?? null);
if ($profile === null) {
    json_out(['error' => 'unknown profile', 'profiles' => array_keys(PROFILES)], 400);
}

try {
    db_migrate();
    $access = playlists_access($profile, trim((string) ($_SERVER['HTTP_X_1RADIOE_TOKEN'] ?? '')));
    if ($access === 401) {
        json_out(['error' => 'key required'], 401);
    }
    if ($access === 403) {
        json_out(['error' => 'key of another profile'], 403);
    }
    $body = [
        'version'   => catalog_token(),
        'profile'   => $profile,
        'generated' => null,   // set below, outside of the ETag
        'playlists' => playlists_api($profile),
    ];
    header('Cache-Control: no-cache');
    $hash = substr(sha1(json_encode($body['playlists'])), 0, 16);
    etag_check('"' . $profile . '-' . $hash . '"');
    $body['generated'] = date(DATE_ATOM);
    json_out($body, 200, false);
} catch (Throwable $e) {
    error_log('1radioe playlists: ' . $e->getMessage());
    json_out(['error' => 'unavailable'], 503);
}
