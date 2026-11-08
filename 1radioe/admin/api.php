<?php
/**
 * 1radiœ admin API (JSON). Used by assets/app.js.
 * Every call needs a logged in session; every POST also the CSRF token
 * in the "X-CSRF-Token" header.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/admin.php';

if (admin_boot() !== null || setup_needed()) {
    json_out(['error' => 'Nicht eingerichtet.'], 503);
}
if (!is_logged_in()) {
    json_out(['error' => 'Bitte neu anmelden.', 'login' => true], 401);
}
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && !csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    json_out(['error' => 'Sitzung abgelaufen, bitte Seite neu laden.'], 403);
}
session_write_close();   // no session writes below; allows parallel requests

$action = (string) ($_GET['a'] ?? '');
$in     = $isPost && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
    ? (json_decode((string) file_get_contents('php://input'), true) ?: [])
    : $_POST;
$id     = isset($in['id']) && $in['id'] !== '' && $in['id'] !== null ? (int) $in['id'] : null;

/** Station list for the admin. */
function state_stations(): array
{
    $stats = playlist_stats();
    return array_map(static fn($s) => station_public($s, $stats), stations_all());
}

function playlist_payload(int $id): array
{
    $p = playlist_find($id) ?? throw new InputError([], 'Playlist nicht gefunden.');
    return ['playlist' => station_public($p), 'tracks' => array_map('track_public', playlist_tracks($id))];
}

try {
    switch ($isPost ? 'POST ' . $action : 'GET ' . $action) {
        case 'GET state':
            json_out([
                'stations' => state_stations(),
                'mimes'    => MIME_TYPES,
            ]);

        case 'GET system':
            $configPath = (string) config_path();
            json_out([
                'portal' => [
                    'Service-Name'          => APP_NAME,
                    'SMAPI Endpoint'        => base_url(1) . '/smapi.php',
                    'SMAPI Endpoint Version' => '1.1',
                    'Authentication Method' => 'Anonymous',
                    'Integration ID'        => (string) cfg('integration_id', 'co.eichhof.radioe'),
                ],
                'status' => [
                    'Version'        => APP_NAME . ' ' . APP_VERSION,
                    'PHP'            => PHP_VERSION,
                    'Datenbank'      => db()->getAttribute(PDO::ATTR_SERVER_VERSION),
                    'Erweiterungen'  => implode(', ', array_map(fn($e) => $e . (extension_loaded($e) ? ' ✓' : ' fehlt'), ['curl', 'gd', 'pdo_mysql', 'dom'])),
                    'Konfiguration'  => $configPath . (config_in_webroot() ? '  ⚠ liegt im Web-Verzeichnis, bitte nach außerhalb verschieben' : ''),
                    'Sender'         => count(stations_all(true)) . ' aktiv von ' . count(stations_all()),
                    'Katalog-Stand'  => catalog_token(),
                    'Tracks'         => count(tracks_all()) . ', im FTP-Eingang: ' . count(inbox_files()),
                    'Upload'         => 'in 2-MB-Teilen, Server erlaubt ' . ini_get('upload_max_filesize') . ' je Teil',
                    'Medien'         => storage_state(media_dir()) . ', erreichbar unter ' . media_url(1),
                    'Track-Ordner'   => storage_state(track_dir()),
                    'FTP-Eingang'    => storage_state(inbox_dir()),
                    'Upload-Puffer'  => storage_state(upload_dir()),
                ],
                'log_enabled' => setting('log_enabled', '0') === '1',
            ]);

        case 'GET log':
            $rows = q('SELECT at, ip, agent, method, item, status, note FROM r1_request_log ORDER BY id DESC LIMIT 200')->fetchAll();
            foreach ($rows as &$r) {
                $r['at'] = date('d.m. H:i:s', (int) $r['at']);
            }
            json_out(['log' => $rows]);

        case 'POST station.save':
            $saved = station_save($in, $id);
            json_out(['station' => station_public($saved)]);

        /* ----- Track library ----- */

        case 'GET tracks':
            json_out(['tracks' => array_map('track_public', tracks_all()), 'inbox' => count(inbox_files())]);

        case 'POST track.save':
            json_out(['track' => track_public(track_save((int) $id, $in))]);

        case 'POST track.delete':
            track_delete((int) $id);
            json_out(['ok' => true]);

        case 'POST track.cover':
            $file = $_FILES['logo'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new InputError([], 'Upload fehlgeschlagen.');
            }
            track_set_cover((int) $id, logo_store((string) file_get_contents($file['tmp_name'])));
            json_out(['track' => track_public(track_find((int) $id))]);

        case 'POST track.cover.remove':
            track_set_cover((int) $id, null);
            json_out(['track' => track_public(track_find((int) $id))]);

        // Upload in chunks: start, chunk (repeated), finish
        case 'POST upload.start':
            json_out(['upload' => upload_start((int) ($in['size'] ?? 0))]);

        case 'POST upload.chunk':
            $chunk = $_FILES['chunk'] ?? null;
            if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
                throw new InputError([], 'Teil-Upload fehlgeschlagen (Fehler ' . ($chunk['error'] ?? '?') . ').');
            }
            json_out(['received' => upload_chunk((string) ($in['upload'] ?? ''), (int) ($in['offset'] ?? -1), $chunk['tmp_name'])]);

        case 'POST upload.finish':
            $track = upload_finish((string) ($in['upload'] ?? ''), (string) ($in['name'] ?? 'upload.mp3'));
            if (!empty($in['playlist'])) {
                playlist_add_tracks((int) $in['playlist'], [(int) $track['id']]);
            }
            json_out(['track' => track_public($track)]);

        case 'POST inbox.import':
            [$tracks, $errors] = inbox_import();
            if ($tracks && !empty($in['playlist'])) {
                playlist_add_tracks((int) $in['playlist'], array_column($tracks, 'id'));
            }
            json_out(['imported' => count($tracks), 'errors' => $errors]);

        /* ----- Playlists ----- */

        case 'GET playlist':
            json_out(playlist_payload((int) ($_GET['id'] ?? 0)));

        case 'POST playlist.tracks':
            playlist_set_tracks((int) $id, (array) ($in['tracks'] ?? []));
            json_out(playlist_payload((int) $id));

        case 'POST playlist.add':
            playlist_add_tracks((int) $id, (array) ($in['tracks'] ?? []));
            json_out(playlist_payload((int) $id));

        case 'POST station.active':
            station_set_active((int) $id, !empty($in['active']));
            json_out(['ok' => true]);

        case 'POST station.delete':
            station_delete((int) $id);
            json_out(['ok' => true]);

        case 'POST stations.reorder':
            stations_reorder(array_map('intval', (array) ($in['ids'] ?? [])));
            json_out(['ok' => true]);

        case 'POST probe':
            $url = trim((string) ($in['url'] ?? ''));
            if (!preg_match('~^https?://~i', $url)) {
                throw new InputError(['stream_url' => 'Bitte eine gültige http(s)-Adresse eingeben.']);
            }
            $result = probe_stream($url);
            if ($id !== null && station_find($id) !== null) {
                station_set_check($id, $result['ok'], $result['info']);
            }
            json_out(['probe' => $result]);

        case 'POST logo.upload':
            $file = $_FILES['logo'] ?? null;
            if ($id === null || station_find($id) === null) {
                throw new InputError([], 'Sender nicht gefunden.');
            }
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new InputError([], 'Upload fehlgeschlagen.');
            }
            station_set_logo($id, logo_store((string) file_get_contents($file['tmp_name'])));
            json_out(['station' => station_public(station_find($id))]);

        case 'POST logo.remove':
            station_set_logo((int) $id, null);
            json_out(['station' => station_public(station_find((int) $id))]);

        case 'POST logo.fetch':
            json_out(['dataUrl' => logo_fetch_remote(trim((string) ($in['url'] ?? '')))]);

        case 'POST log.enabled':
            setting_set('log_enabled', !empty($in['enabled']) ? '1' : '0');
            json_out(['ok' => true]);

        case 'POST log.clear':
            db()->exec('DELETE FROM r1_request_log');
            json_out(['ok' => true]);

        case 'POST import.lite':
            json_out(['imported' => import_lite()]);

        default:
            json_out(['error' => 'Unbekannte Aktion.'], 404);
    }
} catch (InputError $e) {
    json_out(['error' => $e->getMessage(), 'fields' => $e->fields], 422);
} catch (Throwable $e) {
    error_log('1radioe admin: ' . $e);
    json_out(['error' => 'Interner Fehler. Details stehen im PHP-Fehlerlog.'], 500);
}

/**
 * Takes over the three stations of RADIOE Lite with their SMAPI ids, so Sonos
 * favorites keep working, and downloads their logos.
 */
function import_lite(): int
{
    $lite = [
        ['radio1dance', 'BBC Radio 1 Dance', 'https://as-hls-ww-live.akamaized.net/pool_62063831/live/ww/bbc_radio_one_dance/bbc_radio_one_dance.isml/bbc_radio_one_dance-audio%3d96000.norewind.m3u8',
            'application/vnd.apple.mpegURL', 0, 'The biggest current, future and classic dance vibes', 'https://sounds.files.bbci.co.uk/3.12.0/services/bbc_radio_one_dance/blocks-colour-black_600x600.png'],
        ['somafm7soul', 'SomaFM Seven Inch Soul', 'https://somafm.com/7soul130.pls',
            'audio/aac', 1, 'Vintage soul tracks from the original 45 RPM vinyl.', 'https://somafm.com/logos/512/7soul512.jpg'],
        ['loungeradio', 'Lounge-Radio.com', 'https://player.streamhosting.ch/lounge128.ogg',
            'application/ogg', 0, 'Downtempo and nu jazz, made with love in Switzerland.', 'https://lounge-radio.com/assets/icons/pwa/icon-512.png'],
    ];
    $count = 0;
    foreach ($lite as [$smapiId, $title, $url, $mime, $playlist, $desc, $logo]) {
        if (station_by_smapi($smapiId) !== null) {
            continue;
        }
        $saved = station_save(['title' => $title, 'stream_url' => $url, 'mime' => $mime, 'is_playlist' => $playlist, 'description' => $desc, 'active' => 1]);
        q('UPDATE r1_stations SET smapi_id = ? WHERE id = ?', [$smapiId, $saved['id']]);
        try {
            $res = http_get($logo, LOGO_MAX_BYTES + 1, 10);
            if ($res['ok']) {
                station_set_logo((int) $saved['id'], logo_store($res['body']));
            }
        } catch (Throwable) {
            // station stays without logo; can be added in the editor
        }
        $count++;
    }
    return $count;
}
