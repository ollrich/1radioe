<?php
/**
 * The public station list for the web app, the playlist files and stations.json.
 *
 * Only streams: MP3 playlists and tracks are for Sonos and, with a key, the app
 * (/api/playlists); publishing bought music is not an option. Everything active appears, in the order of the admin and of Sonos: the
 * top level as it is, a category's streams where the category stands. An inactive
 * category hides its streams, like in Sonos. The web app also asks for SoundCloud
 * tracks, Deezer links and YouTube playlists ($webapp), the playlist file and
 * stations.json do not.
 *
 * Profiles (app/profiles.php): everything public is Olli's; Cathy's list exists only
 * for the Android app (stations.json and /api/playlists with ?profile=cathy).
 */
declare(strict_types=1);

/**
 * Returns ['stations' => [...], 'categories' => [...]].
 * Station entries: ['row' => station row, 'category' => category row or null,
 *   'sort' => position within its level, 1-based].
 * Category entries: ['row' => category row, 'sort' => position on the top level,
 *   'youtube' => true for a YouTube playlist, whose videos are in r1_youtube_videos]
 *   (top-level streams and categories share one sequence of positions).
 * Playlist entries ($playlists, for the app): like stations, MP3 playlists that can play
 *   (active, with at least one track whose file exists). They take their places in the
 *   same sequence, so stations.json and /api/playlists number one list.
 * Stations come in listening order: what Sonos shows, categories opened in place.
 * $profile: whose list (app/profiles.php); a category appears only with something in it.
 */
function public_catalog(bool $webapp = false, string $profile = PROFILE_DEFAULT, bool $playlists = false): array
{
    $kinds    = $webapp ? ['stream', 'soundcloud', 'deezer'] : ['stream'];
    $playable = $playlists ? playlists_playable() : [];
    $byParent = [];
    foreach (stations_all(true) as $r) {
        if ($r['kind'] !== 'category' && !profile_visible($r, $profile)) {
            continue;
        }
        $byParent[$r['category_id'] !== null ? (int) $r['category_id'] : 0][] = $r;
    }
    $isPlaylist = static fn(array $r): bool => $playlists && $r['kind'] === 'playlist' && isset($playable[(int) $r['id']]);
    $stations   = [];
    $categories = [];
    $lists      = [];
    $pos        = 0;
    $videos     = null;
    foreach ($byParent[0] ?? [] as $r) {
        if (in_array($r['kind'], $kinds, true)) {
            $stations[] = ['row' => $r, 'category' => null, 'sort' => ++$pos];
        } elseif ($isPlaylist($r)) {
            $lists[] = ['row' => $r, 'category' => null, 'sort' => ++$pos];
        } elseif ($webapp && $r['kind'] === 'youtube') {
            $videos ??= youtube_counts();
            if (($videos[(int) $r['id']] ?? 0) > 0) {
                $categories[] = ['row' => $r, 'sort' => ++$pos, 'youtube' => true];
            }
        } elseif ($r['kind'] === 'category') {
            $inner = array_values(array_filter($byParent[(int) $r['id']] ?? [], static fn($x) => in_array($x['kind'], $kinds, true) || $isPlaylist($x)));
            if (!$inner) {
                continue;   // empty, or holds nothing for this list
            }
            $categories[] = ['row' => $r, 'sort' => ++$pos];
            foreach ($inner as $i => $x) {
                if ($x['kind'] === 'playlist') {
                    $lists[] = ['row' => $x, 'category' => $r, 'sort' => $i + 1];
                } else {
                    $stations[] = ['row' => $x, 'category' => $r, 'sort' => $i + 1];
                }
            }
        }
    }
    // 'playable': the tracks of the playlists, so /api/playlists need not look again
    return ['stations' => $stations, 'categories' => $categories, 'playlists' => $lists, 'playable' => $playable];
}

/**
 * The entries in each category of a catalog of public_catalog(), stations and playlists
 * in their order there: [category id => rows]. The category pictures of that list are
 * made of them (category_cover), so they show only what the list shows.
 */
function catalog_inside(array $catalog): array
{
    $entries = array_merge($catalog['stations'], $catalog['playlists'] ?? []);
    usort($entries, static fn($a, $b) => $a['sort'] <=> $b['sort']);   // stable: one category's order stays
    $out = [];
    foreach ($entries as $e) {
        if ($e['category'] !== null) {
            $out[(int) $e['category']['id']][] = $e['row'];
        }
    }
    return $out;
}

/** True if the address can be played from an HTTPS page. */
function is_https_url(string $url): bool
{
    return str_starts_with(strtolower($url), 'https://');
}

/** A station name fit for one line of a playlist file. */
function playlist_title(string $title): string
{
    return trim((string) preg_replace('~[\r\n]+~', ' ', $title));
}

/**
 * The stations as an XSPF playlist (XSPF 1.0), for Eter on the Mac and iPad and other
 * players that import XSPF. The same form as Eter's own export: per station only title,
 * stream address and logo. $stations: public_catalog()['stations'], so the same stations
 * in the same order as streams.m3u. $logo turns a logo file into an absolute address.
 */
function xspf_playlist(array $stations, callable $logo): string
{
    $xml = static function (string $s): string {
        // valid UTF-8, only characters XML 1.0 allows, then the five escapes
        $s = mb_scrub($s, 'UTF-8');
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<playlist version=\"1\" xmlns=\"http://xspf.org/ns/0/\">\n<trackList>\n";
    foreach ($stations as $e) {
        $out .= "<track>\n"
            . '    <title>' . $xml(playlist_title((string) $e['row']['title'])) . "</title>\n"
            . '    <location>' . $xml(station_playback_url($e['row'])) . "</location>\n";
        $image = $logo($e['row']['logo'] ?? null);
        if (is_string($image) && $image !== '') {
            $out .= '    <image>' . $xml($image) . "</image>\n";
        }
        $out .= "</track>\n";
    }
    return $out . "</trackList>\n</playlist>\n";
}

/** Sends a 304 and stops if the client already has this version. */
function etag_check(string $etag): void
{
    header('ETag: ' . $etag);
    $match = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    foreach (array_map('trim', explode(',', $match)) as $candidate) {
        if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
            http_response_code(304);
            exit;
        }
    }
}
