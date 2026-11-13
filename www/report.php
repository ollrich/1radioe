<?php
/**
 * 1radiœ: Sonos playback reports, the "Reporting Endpoint" in the Sonos developer portal.
 * Endpoint in the portal: https://1radioe.net/report/v2.3, Sonos posts to …/timePlayed.
 *
 * While a station plays, Sonos players send POST /timePlayed: an "update" about every
 * minute and a "final" when playback ends. This replaces the SMAPI calls
 * reportPlaySeconds and reportPlayStatus, which Sonos deprecated and which new
 * integrations no longer get. Each update keeps the listening status alive, a final
 * ends it.
 *
 * Anonymous like the SMAPI endpoint: anybody can send a report, just as anybody can
 * call getMediaURI. Only the station and the time are stored, nothing about the
 * household or device. With the diagnostic log switched on, every report is logged
 * with the fields Sonos sent, because the documentation leaves open how live streams
 * are reported.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const REPORT_MAX_BODY  = 65536;
const REPORT_MAX_ITEMS = 50;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$path   = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$action = basename($path);   // "timePlayed"

function report_out(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data ?: new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Candidate SMAPI ids of a report item: id, objectId, contextId and the id in mediaUrl. */
function report_ids(array $item): array
{
    $ids = [];
    foreach (['id', 'objectId', 'contextId'] as $k) {
        if (is_string($item[$k] ?? null) && $item[$k] !== '') {
            $ids[] = $item[$k];
        }
    }
    // e.g. "x-sonosapi-stream:flux-b-funk?sid=…&flags=…"
    if (is_string($item['mediaUrl'] ?? null) && preg_match('~^x-[a-z-]+:([^?]+)~i', $item['mediaUrl'], $m)) {
        $ids[] = rawurldecode($m[1]);
    }
    return array_values(array_unique($ids));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    printf("Reporting ok\n%s %s\n", APP_NAME, APP_VERSION);
    exit;
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > REPORT_MAX_BODY) {
    sonos_log($action, '', 413, 'Report too large');
    report_out(413, []);
}
$raw  = (string) file_get_contents('php://input', false, null, 0, REPORT_MAX_BODY + 1);
$data = strlen($raw) <= REPORT_MAX_BODY ? json_decode($raw, true) : null;
$items = is_array($data) ? (array_is_list($data) ? $data : ($data['items'] ?? null)) : null;
if (!is_array($items) || !array_is_list($items)) {
    sonos_log($action, '', 400, 'Unreadable report: ' . mb_substr($raw, 0, 200));
    report_out(400, []);
}

try {
    db_migrate();
} catch (Throwable) {
    report_out(503, []);
}

foreach (array_slice($items, 0, REPORT_MAX_ITEMS) as $item) {
    if (!is_array($item)) {
        continue;
    }
    $type    = is_string($item['type'] ?? null) ? $item['type'] : '';
    $playing = $type !== 'final';
    $station = null;
    $used    = '';
    foreach (report_ids($item) as $id) {
        $s = station_by_smapi($id);
        if ($s !== null && $s['kind'] === 'stream') {
            $station = $s;
            $used    = $id;
            break;
        }
    }
    $ok = false;
    if ($station !== null) {
        try {
            $ok = listen_report($station, $playing);
        } catch (Throwable) {
            $ok = false;   // the status must never get in the way of playback
        }
    }
    $note = ($ok ? 'Status: ' . ($playing ? 'läuft' : 'aus') . ' · ' : '')
        . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    sonos_log($action . ($type !== '' ? ' ' . $type : ''), $used !== '' ? $used : (string) (report_ids($item)[0] ?? ''), 200, $note);
}

report_out(200, ['items' => $items]);
