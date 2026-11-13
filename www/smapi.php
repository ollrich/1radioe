<?php
/**
 * 1radiœ: Sonos Music API (SMAPI) endpoint, anonymous authentication.
 * Endpoint in the Sonos developer portal: https://1radioe.net/smapi.php
 *
 * Sonos calls this file over HTTPS: it lists the active stations, playlists and
 * categories (getMetadata), the content of a category or the tracks of a playlist
 * (getMetadata with its id), their details
 * (getMediaMetadata) and the address to play (getMediaURI). Everything is managed
 * in the admin (admin/).
 * Opening this file in a browser shows a short status line.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const MAX_BODY = 65536;
const SONOS_NS = 'http://www.sonos.com/Services/1.1';
const SOAP_NS  = 'http://schemas.xmlsoap.org/soap/envelope/';

/* ---------- Responses ---------- */

function esc(string $s): string
{
    // characters XML 1.0 does not allow would make Sonos reject the whole answer
    $s = (string) preg_replace('~[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]~u', '', mb_scrub($s, 'UTF-8'));
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send(string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><s:Envelope xmlns:s="' . SOAP_NS . '"><s:Body>' . $body . '</s:Body></s:Envelope>';
    exit;
}

function respond(string $method, string $inner): never
{
    request_log(200, $GLOBALS['log_note']);
    send("<{$method}Response xmlns=\"" . SONOS_NS . "\"><{$method}Result>{$inner}</{$method}Result></{$method}Response>");
}

function respond_empty(string $method): never
{
    request_log(200, $GLOBALS['log_note']);
    send("<{$method}Response xmlns=\"" . SONOS_NS . '"/>');
}

/** SOAP fault: HTTP 500 and a code like "Client.ItemNotFound" (no namespace prefix). */
function fault(string $code, string $message): never
{
    request_log(500, $code . ': ' . $message);
    send('<s:Fault><faultcode>' . esc($code) . '</faultcode><faultstring>' . esc($message) . '</faultstring></s:Fault>', 500);
}

/* ---------- Diagnostic log (switched on in the admin under System) ---------- */

$GLOBALS['log_method'] = '-';
$GLOBALS['log_item']   = '';
$GLOBALS['log_note']   = '';

function request_log(int $status, string $note): void
{
    try {
        if (setting('log_enabled', '0') !== '1') {
            return;
        }
        q('INSERT INTO r1_request_log (at, ip, agent, method, item, status, note, endpoint) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            time(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            mb_substr($GLOBALS['log_method'], 0, 64), mb_substr($GLOBALS['log_item'], 0, 120), $status, mb_substr($note, 0, 255),
            called_endpoint(),
        ]);
        if (random_int(1, 25) === 1) {   // keep the newest 500 entries
            q('DELETE FROM r1_request_log WHERE id < (SELECT id FROM (SELECT id FROM r1_request_log ORDER BY id DESC LIMIT 1 OFFSET 499) t)');
        }
    } catch (Throwable) {
        // logging must never break playback
    }
}

/**
 * The address Sonos called: host without port, and path, e.g. "1radioe.net/smapi.php",
 * or "eichhof.co/1radioe/smapi.php" when an old address includes this file.
 */
function called_endpoint(): string
{
    $host = strtolower(rtrim((string) preg_replace('~:\d+$~', '', (string) ($_SERVER['HTTP_HOST'] ?? '')), '.'));
    if (!preg_match('~^[a-z0-9.-]{1,100}$~', $host)) {
        $host = '?';
    }
    $path = (string) preg_replace('#[^A-Za-z0-9._/-]#', '', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return substr($host . $path, 0, 160);
}

/**
 * Counts a request per called address, also with the diagnostic log off and without IP
 * addresses. The admin shows when each address was last used (System, Sonos-Adressen).
 */
function endpoint_seen(): void
{
    try {
        $now = time();
        q('INSERT INTO r1_endpoints (endpoint, first_seen, last_seen, hits) VALUES (?, ?, ?, 1)
           ON DUPLICATE KEY UPDATE last_seen = ?, hits = hits + 1', [called_endpoint(), $now, $now, $now]);
    } catch (Throwable) {
        // counting must never break playback
    }
}

/* ---------- Stations, playlists, tracks ---------- */

/** Active station or playlist by SMAPI id, otherwise "item not found". */
function station(string $smapiId, ?string $kind = null): array
{
    $s = station_by_smapi($smapiId);
    if ($s === null || !$s['active'] || ($kind !== null && $s['kind'] !== $kind)) {
        fault('Client.ItemNotFound', 'Unknown id');
    }
    return $s;
}

/** Track by SMAPI id "track:<number>", otherwise "item not found". */
function track(string $smapiId): array
{
    $t = preg_match('~^track:(\d+)$~', $smapiId, $m) ? track_find((int) $m[1]) : null;
    if ($t === null) {
        fault('Client.ItemNotFound', 'Unknown id');
    }
    return $t;
}

function is_track_id(string $id): bool
{
    return str_starts_with($id, 'track:');
}

/**
 * Listening status from Sonos: "läuft" or "aus" for a stream id. Never reads or
 * stores anything about the household or device in the SOAP header, and never lets
 * a status problem get in the way of playback. Adds the outcome to the diagnostic log.
 */
function smapi_listen(string $id, bool $playing): bool
{
    try {
        $s  = is_track_id($id) ? null : station_by_smapi($id);
        $ok = $s !== null && listen_report($s, $playing);
    } catch (Throwable) {
        $ok = false;
    }
    if ($ok) {
        $GLOBALS['log_note'] = trim($GLOBALS['log_note'] . ' Status: ' . ($playing ? 'läuft' : 'aus'));
    }
    return $ok;
}

/** Stream metadata in the order required by the SMAPI schema. */
function media_inner(array $s): string
{
    $meta = '';
    $logo = logo_url($s['logo']);   // null if there is none or the file is missing
    if ($logo !== null) {
        $meta .= '<logo>' . esc($logo) . '</logo>';
    }
    if ($s['description'] !== '') {
        $meta .= '<description>' . esc($s['description']) . '</description>';
    }
    return '<id>' . esc($s['smapi_id']) . '</id>'
        . '<itemType>stream</itemType>'
        . '<title>' . esc($s['title']) . '</title>'
        . '<mimeType>' . esc($s['mime']) . '</mimeType>'
        . '<streamMetadata>' . $meta . '</streamMetadata>';
}

/**
 * A folder. A playlist is playable: Sonos lists its tracks on click and queues all of
 * them on "play" (shuffle and repeat are handled by the Sonos queue). A category is a
 * plain container with streams and playlists inside.
 */
function collection_xml(array $p, int $total, array $rows = []): string
{
    $isCategory = $p['kind'] === 'category';
    $art        = logo_url($isCategory ? category_cover($p, $rows) : $p['logo']);
    return '<mediaCollection>'
        . '<id>' . esc($p['smapi_id']) . '</id>'
        . '<itemType>' . ($isCategory ? 'container' : 'playlist') . '</itemType>'
        . '<title>' . esc($p['title']) . '</title>'
        . '<canPlay>' . ($isCategory ? 'false' : 'true') . '</canPlay>'
        . '<canEnumerate>true</canEnumerate>'
        . ($art !== null ? '<albumArtURI>' . esc($art) . '</albumArtURI>' : '')
        . '<total>' . $total . '</total>'
        . '</mediaCollection>';
}

/** Track metadata in the order required by the SMAPI schema. */
function track_inner(array $t, ?array $playlist = null): string
{
    $cover = logo_url(track_cover_file($t, $playlist));
    return '<id>track:' . (int) $t['id'] . '</id>'
        . '<itemType>track</itemType>'
        . '<title>' . esc($t['title']) . '</title>'
        . '<mimeType>audio/mpeg</mimeType>'
        . '<trackMetadata>'
        . ($t['artist'] !== '' ? '<artist>' . esc($t['artist']) . '</artist>' : '')
        . ($t['album'] !== '' ? '<album>' . esc($t['album']) . '</album>' : '')
        . '<duration>' . (int) $t['duration'] . '</duration>'
        . ($cover !== null ? '<albumArtURI>' . esc($cover) . '</albumArtURI>' : '')
        . '<canPlay>true</canPlay>'
        . '<canSkip>true</canSkip>'
        . '</trackMetadata>';
}

/** One entry of a list: stream, playlist, category or track. $rows: stations_all(true). */
function item_xml(array $item, array $stats = [], ?array $playlist = null, array $rows = []): string
{
    if (isset($item['file'])) {
        return '<mediaMetadata>' . track_inner($item, $playlist) . '</mediaMetadata>';
    }
    if ($item['kind'] === 'playlist') {
        return collection_xml($item, $stats[(int) $item['id']]['tracks'] ?? 0);
    }
    if ($item['kind'] === 'category') {
        return collection_xml($item, count(sonos_visible($rows, $stats, (int) $item['id'])), $rows);
    }
    return '<mediaMetadata>' . media_inner($item) . '</mediaMetadata>';
}

/** A page of a list as getMetadata result. */
function list_result(array $items, int $index, int $count, callable $render): string
{
    $slice = array_slice($items, $index, $count);
    return "<index>{$index}</index><count>" . count($slice) . '</count><total>' . count($items) . '</total>'
        . implode('', array_map($render, $slice));
}

/* ---------- Status page (browser) ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        db_migrate();
        $active = (int) q('SELECT COUNT(*) FROM r1_stations WHERE active = 1')->fetchColumn();
        printf("SMAPI ok\n%s %s, %d aktive Einträge, Katalog %s\n", APP_NAME, APP_VERSION, $active, catalog_token());
    } catch (Throwable) {
        http_response_code(503);
        echo "SMAPI Fehler: Datenbank nicht erreichbar oder nicht eingerichtet.\n";
    }
    exit;
}

/* ---------- Read and check the SOAP request ---------- */

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY) {
    fault('Client', 'Request too large');
}
$raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
// SOAP never contains a DTD; rejecting it blocks XML entity attacks.
if ($raw === '' || strlen($raw) > MAX_BODY || stripos($raw, '<!DOCTYPE') !== false) {
    fault('Client', 'Invalid request');
}
$doc = new DOMDocument();
if (!@$doc->loadXML($raw, LIBXML_NONET) || $doc->doctype !== null) {
    fault('Client', 'Invalid request');
}
$body = $doc->getElementsByTagNameNS(SOAP_NS, 'Body')->item(0);
$call = null;
foreach ($body?->childNodes ?? [] as $node) {
    if ($node instanceof DOMElement) {
        $call = $node;
        break;
    }
}
if ($call === null) {
    fault('Client', 'No operation');
}
$param = static function (string $name, string $default = '') use ($call): string {
    $el = $call->getElementsByTagNameNS('*', $name)->item(0);
    return $el !== null ? trim($el->textContent) : $default;
};
$GLOBALS['log_method'] = $call->localName;
$GLOBALS['log_item']   = $param('id');

/* ---------- Answer ---------- */

try {
    db_migrate();   // after an update the database may need new columns before the admin was opened
    endpoint_seen();
    switch ($call->localName) {
        // Lists: "root" is the top level, a category id lists its streams and playlists,
        // a playlist id its tracks. Empty playlists and categories and tracks whose MP3
        // file is missing are left out.
        case 'getMetadata':
            $id    = $param('id', 'root');
            $index = max(0, (int) $param('index', '0'));
            $count = min(100, max(1, (int) $param('count', '100')));
            $item  = $id === 'root' ? null : station($id);
            if ($item === null || $item['kind'] === 'category') {
                $stats = playlist_stats();
                $rows  = stations_all(true);
                $items = sonos_visible($rows, $stats, $item !== null ? (int) $item['id'] : null);
                respond('getMetadata', list_result($items, $index, $count, static fn($i) => item_xml($i, $stats, null, $rows)));
            }
            $playlist = station($id, 'playlist');
            $tracks   = array_filter(playlist_tracks((int) $playlist['id']), 'track_file_exists');
            respond('getMetadata', list_result(array_values($tracks), $index, $count, static fn($t) => item_xml($t, [], $playlist)));

        case 'getMediaMetadata':
            $id = $param('id');
            respond('getMediaMetadata', is_track_id($id) ? track_inner(track($id)) : media_inner(station($id, 'stream')));

        case 'getExtendedMetadata':
            $id = $param('id');
            if (is_track_id($id)) {
                respond('getExtendedMetadata', item_xml(track($id)));
            }
            $item = station($id);
            respond('getExtendedMetadata', $item['kind'] === 'stream' ? item_xml($item) : item_xml($item, playlist_stats(), null, stations_all(true)));

        // Playback: the stream address (.pls/.m3u are resolved) or the MP3 file of a track.
        // For a stream this is also the start of the listening status.
        case 'getMediaURI':
            $id = $param('id');
            if (is_track_id($id)) {
                respond('getMediaURI', esc(track_url(track($id))));
            }
            $url = station_playback_url(station($id, 'stream'));
            smapi_listen($id, true);
            respond('getMediaURI', esc($url));

        // Sonos polls this; a new catalog value makes it reload the list.
        case 'getLastUpdate':
            respond('getLastUpdate', '<catalog>' . esc(catalog_token()) . '</catalog><favorites>0</favorites><pollInterval>3600</pollInterval>');

        // Heartbeat while a stream plays: the answer asks for the next report in
        // LISTEN_INTERVAL seconds. Tracks get 0, which means no further reports.
        case 'reportPlaySeconds':
            $id = $param('id');
            $GLOBALS['log_note'] = 'seconds=' . $param('seconds');
            $stream = smapi_listen($id, true);
            respond('reportPlaySeconds', '<interval>' . ($stream ? LISTEN_INTERVAL : 0) . '</interval>');

        // End of playback for the listening status. Whether and when Sonos sends this
        // for live streams is checked with the diagnostic log (see CHANGELOG 1.5.0).
        case 'reportPlayStatus':
            $GLOBALS['log_note'] = 'status=' . $param('status');
            smapi_listen($param('id'), false);
            respond_empty('reportPlayStatus');

        // Notifications without a result. Logged with their values for diagnosis.
        case 'setPlayedSeconds':
            $GLOBALS['log_note'] = 'seconds=' . $param('seconds');
            respond_empty('setPlayedSeconds');

        case 'reportAccountAction':
            respond_empty($call->localName);

        default:
            fault('Client.UnsupportedOperation', 'Unsupported: ' . $call->localName);
    }
} catch (PDOException) {
    fault('Server.ServiceUnavailable', 'Database unavailable');
}
