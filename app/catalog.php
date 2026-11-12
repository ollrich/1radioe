<?php
/**
 * The public station list for the web app, the playlist files and stations.json.
 *
 * Only streams: MP3 playlists and tracks stay in Sonos, publishing bought music is not
 * an option. Everything active appears, in the order of the admin and of Sonos: the
 * top level as it is, a category's streams where the category stands. An inactive
 * category hides its streams, like in Sonos.
 */
declare(strict_types=1);

/**
 * Returns ['stations' => [...], 'categories' => [...]].
 * Station entries: ['row' => station row, 'category' => category row or null,
 *   'sort' => position within its level, 1-based].
 * Category entries: ['row' => category row, 'sort' => position on the top level]
 *   (top-level streams and categories share one sequence of positions).
 * Stations come in listening order: what Sonos shows, categories opened in place.
 */
function public_catalog(): array
{
    $byParent = [];
    foreach (stations_all(true) as $r) {
        $byParent[$r['category_id'] !== null ? (int) $r['category_id'] : 0][] = $r;
    }
    $stations   = [];
    $categories = [];
    $pos        = 0;
    foreach ($byParent[0] ?? [] as $r) {
        if ($r['kind'] === 'stream') {
            $stations[] = ['row' => $r, 'category' => null, 'sort' => ++$pos];
        } elseif ($r['kind'] === 'category') {
            $inner = array_values(array_filter($byParent[(int) $r['id']] ?? [], static fn($x) => $x['kind'] === 'stream'));
            if (!$inner) {
                continue;   // empty, or holds playlists only
            }
            $categories[] = ['row' => $r, 'sort' => ++$pos];
            foreach ($inner as $i => $x) {
                $stations[] = ['row' => $x, 'category' => $r, 'sort' => $i + 1];
            }
        }
    }
    return ['stations' => $stations, 'categories' => $categories];
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
