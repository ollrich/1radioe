<?php
/**
 * 1radiœ: Sonos Music API (SMAPI) endpoint, anonymous authentication.
 * Endpoint in the Sonos developer portal: https://eichhof.co/1radioe/smapi.php
 *
 * Sonos calls this file over HTTPS: it lists the active stations (getMetadata),
 * returns their details (getMediaMetadata) and the stream address to play
 * (getMediaURI). Stations are managed in the admin (admin/).
 * Opening this file in a browser shows a short status line.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

const MAX_BODY = 65536;
const SONOS_NS = 'http://www.sonos.com/Services/1.1';
const SOAP_NS  = 'http://schemas.xmlsoap.org/soap/envelope/';

/* ---------- Responses ---------- */

function esc(string $s): string
{
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
    request_log(200, '');
    send("<{$method}Response xmlns=\"" . SONOS_NS . "\"><{$method}Result>{$inner}</{$method}Result></{$method}Response>");
}

function respond_empty(string $method): never
{
    request_log(200, '');
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

function request_log(int $status, string $note): void
{
    try {
        if (setting('log_enabled', '0') !== '1') {
            return;
        }
        q('INSERT INTO r1_request_log (at, ip, agent, method, item, status, note) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            time(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            mb_substr($GLOBALS['log_method'], 0, 64), mb_substr($GLOBALS['log_item'], 0, 120), $status, mb_substr($note, 0, 255),
        ]);
        if (random_int(1, 25) === 1) {   // keep the newest 500 entries
            q('DELETE FROM r1_request_log WHERE id < (SELECT id FROM (SELECT id FROM r1_request_log ORDER BY id DESC LIMIT 1 OFFSET 499) t)');
        }
    } catch (Throwable) {
        // logging must never break playback
    }
}

/* ---------- Stations ---------- */

/** Active station by SMAPI id, otherwise "item not found". */
function station(string $smapiId): array
{
    $s = station_by_smapi($smapiId);
    if ($s === null || !$s['active']) {
        fault('Client.ItemNotFound', 'Unknown id');
    }
    return $s;
}

/** Station metadata in the order required by the SMAPI schema. */
function media_inner(array $s): string
{
    $meta = '';
    if ($s['logo']) {
        $meta .= '<logo>' . esc((string) logo_url($s['logo'])) . '</logo>';
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

function media_xml(array $s): string
{
    return '<mediaMetadata>' . media_inner($s) . '</mediaMetadata>';
}

/* ---------- Status page (browser) ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $active = (int) q('SELECT COUNT(*) FROM r1_stations WHERE active = 1')->fetchColumn();
        printf("SMAPI ok\n%s %s, %d aktive Sender, Katalog %s\n", APP_NAME, APP_VERSION, $active, catalog_token());
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
    switch ($call->localName) {
        // The station list: one flat level ("root").
        case 'getMetadata':
            if ($param('id', 'root') !== 'root') {
                fault('Client.ItemNotFound', 'Unknown container');
            }
            $all   = stations_all(true);
            $index = max(0, (int) $param('index', '0'));
            $count = min(100, max(1, (int) $param('count', '100')));
            $slice = array_slice($all, $index, $count);
            respond('getMetadata', "<index>{$index}</index><count>" . count($slice) . '</count><total>' . count($all) . '</total>'
                . implode('', array_map('media_xml', $slice)));

        case 'getMediaMetadata':
            respond('getMediaMetadata', media_inner(station($param('id'))));

        case 'getExtendedMetadata':
            respond('getExtendedMetadata', media_xml(station($param('id'))));

        // Playback: the stream address (playlists are resolved, see station_playback_url()).
        case 'getMediaURI':
            respond('getMediaURI', esc(station_playback_url(station($param('id')))));

        // Sonos polls this; a new catalog value makes it reload the list.
        case 'getLastUpdate':
            respond('getLastUpdate', '<catalog>' . esc(catalog_token()) . '</catalog><favorites>0</favorites><pollInterval>3600</pollInterval>');

        // Playback reports: interval 0 means no further reports.
        case 'reportPlaySeconds':
            respond('reportPlaySeconds', '<interval>0</interval>');

        // Notifications without a result, e.g. "account added".
        case 'reportAccountAction':
        case 'reportPlayStatus':
        case 'setPlayedSeconds':
            respond_empty($call->localName);

        default:
            fault('Client.UnsupportedOperation', 'Unsupported: ' . $call->localName);
    }
} catch (PDOException) {
    fault('Server.ServiceUnavailable', 'Database unavailable');
}
