<?php
/**
 * The song a station is playing right now, from the stream itself.
 *
 * Shoutcast and Icecast send the current title as ICY metadata inside the audio data
 * when asked to (header "Icy-MetaData: 1"): every icy-metaint bytes a length byte and
 * a block like StreamTitle='Artist - Title';. Browsers cannot read it from an <audio>
 * element, so the server reads the start of the stream, takes the first title and
 * keeps it for a short while. Parallel requests for the same station wait for one
 * read instead of opening many connections.
 *
 * HLS streams carry no ICY metadata and are skipped.
 */
declare(strict_types=1);

const NOWPLAYING_TTL       = 20;       // seconds a title is reused
const NOWPLAYING_EMPTY_TTL = 300;      // streams without titles are asked again after 5 minutes
const NOWPLAYING_MAX_BYTES = 262144;   // read at most this much of a stream
const NOWPLAYING_TIMEOUT   = 6;

/**
 * Current track of a station: ['artist' => '…', 'title' => '…'] (artist may be empty),
 * or null if the stream sends no title.
 */
function nowplaying(array $station): ?array
{
    if (($station['kind'] ?? '') !== 'stream' || empty($station['active']) || $station['mime'] === 'application/vnd.apple.mpegURL') {
        return null;
    }
    $id  = (int) $station['id'];
    $now = time();
    $row = q('SELECT text, fetched_at FROM r1_now_playing WHERE station_id = ?', [$id])->fetch() ?: null;
    if ($row !== null && $now - (int) $row['fetched_at'] < ($row['text'] === null ? NOWPLAYING_EMPTY_TTL : NOWPLAYING_TTL)) {
        return nowplaying_split($row['text'], $station);
    }
    // one read per station at a time; whoever does not get the lock waits briefly for it
    $lock = 'r1_np_' . $id . '_' . substr(md5((string) cfg('db.name', '')), 0, 12);
    if ((int) q('SELECT GET_LOCK(?, ?)', [$lock, NOWPLAYING_TIMEOUT + 2])->fetchColumn() !== 1) {
        return $row !== null ? nowplaying_split($row['text'], $station) : null;
    }
    try {
        $fresh = q('SELECT text, fetched_at FROM r1_now_playing WHERE station_id = ?', [$id])->fetch() ?: null;
        if ($fresh !== null && (int) $fresh['fetched_at'] > $now - NOWPLAYING_TTL) {
            return nowplaying_split($fresh['text'], $station);   // read by the request that held the lock
        }
        $text = icy_title(station_playback_url($station));
        q('INSERT INTO r1_now_playing (station_id, text, fetched_at) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE text = ?, fetched_at = ?', [$id, $text, time(), $text, time()]);
        return nowplaying_split($text, $station);
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** StreamTitle from the start of a stream, or null if it sends none. */
function icy_title(string $url): ?string
{
    $title = null;
    $res   = http_get($url, NOWPLAYING_MAX_BYTES, NOWPLAYING_TIMEOUT, 3, true,
        static function (string $body, array $headers) use (&$title): bool {
            $metaint = (int) ($headers['icy-metaint'] ?? 0);
            if ($metaint <= 0) {
                return true;   // the server does not send metadata: stop right away
            }
            [$complete, $title] = icy_scan($body, $metaint);
            return $complete;
        });
    return $res['ok'] ? $title : null;
}

/**
 * Looks for the first metadata block with a title. Returns [complete, title]:
 * complete is false while more data is needed.
 *
 * Some servers send empty blocks for a while, others first a block with an empty
 * title (e.g. ad data) and the song one block later. Three empty titles in a row
 * mean the stream has none; the read also stops at NOWPLAYING_MAX_BYTES.
 */
function icy_scan(string $body, int $metaint): array
{
    $pos   = $metaint;
    $blank = 0;
    while (true) {
        if (strlen($body) <= $pos) {
            return [false, null];
        }
        $len = ord($body[$pos]) * 16;
        if (strlen($body) < $pos + 1 + $len) {
            return [false, null];
        }
        if ($len > 0) {
            $title = icy_stream_title(substr($body, $pos + 1, $len));
            if ($title !== null) {
                return [true, $title];
            }
            if (++$blank >= 3) {
                return [true, null];
            }
        }
        $pos += 1 + $len + $metaint;   // the next block follows after more audio
    }
}

/** The value of StreamTitle='…'; in a metadata block, as clean UTF-8. */
function icy_stream_title(string $meta): ?string
{
    $meta = rtrim($meta, "\0");
    // lazy up to the quote that ends the field, so titles like "Guns N' Roses" stay whole
    if (!preg_match("~StreamTitle='(.*?)';?(?=[A-Za-z_]\\w*='|\\s*$)~s", $meta, $m)) {
        return null;
    }
    $title = $m[1];
    if (!mb_check_encoding($title, 'UTF-8')) {
        $title = mb_convert_encoding($title, 'UTF-8', 'Windows-1252');   // older servers send Latin-1
    }
    $title = trim(clean_text($title), " \t-–—|");
    return $title !== '' ? mb_substr($title, 0, 300) : null;
}

/**
 * "Artist - Title" as ['artist', 'title']. Without a separator the whole text is the
 * title. Nothing is shown when the stream only repeats the station name.
 */
function nowplaying_split(?string $text, array $station): ?array
{
    if ($text === null || mb_strlen($text) < 2 || mb_strtolower($text) === mb_strtolower((string) $station['title'])) {
        return null;
    }
    $parts = preg_split('~\s+[-–—]\s+~u', $text, 2);
    if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
        // "ByteFM - Nachrichten": the station as artist says nothing new
        $artist = mb_strtolower(trim($parts[0])) === mb_strtolower((string) $station['title']) ? '' : trim($parts[0]);
        return ['artist' => $artist, 'title' => trim($parts[1])];
    }
    return ['artist' => '', 'title' => $text];
}
