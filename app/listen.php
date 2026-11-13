<?php
/**
 * Listening status: is Ollie playing a station right now? Modelled after Last.fm
 * "now playing": every source reports while it plays (heartbeat) and says "off" when
 * it stops. A station counts as playing while its last report is younger than
 * LISTEN_TTL and no "off" came after it, so a source that vanishes without saying so
 * (battery, closed tab, crash) times out by itself.
 *
 * Sources: Sonos via SMAPI, later the web player (listening cookie) and an own app.
 * Stored per station: time of the last report and whether "off" followed. Nothing
 * else, by design: no source, device, IP address or Sonos household.
 */
declare(strict_types=1);

const LISTEN_TTL      = 150;   // seconds a report keeps a station "playing"
const LISTEN_INTERVAL = 60;    // how often sources report while playing

/**
 * Records a report: $playing true means "läuft", false means "aus".
 * Only active streams count; playlists, categories and tracks are ignored.
 * Returns true if the report was recorded.
 */
function listen_report(array $station, bool $playing): bool
{
    if (($station['kind'] ?? '') !== 'stream' || empty($station['active'])) {
        return false;
    }
    $id = (int) $station['id'];
    if ($playing) {
        $now = time();
        q('INSERT INTO r1_listen_status (station_id, last_seen, stopped) VALUES (?, ?, 0)
           ON DUPLICATE KEY UPDATE last_seen = ?, stopped = 0', [$id, $now, $now]);
    } else {
        q('UPDATE r1_listen_status SET stopped = 1 WHERE station_id = ?', [$id]);
    }
    return true;
}

/** SMAPI ids of the stations playing right now, in station order. */
function listen_active(): array
{
    return q("SELECT s.smapi_id FROM r1_listen_status l JOIN r1_stations s ON s.id = l.station_id
              WHERE l.stopped = 0 AND l.last_seen > ? AND s.active = 1 AND s.kind = 'stream'
              ORDER BY s.sort, s.id", [time() - LISTEN_TTL])->fetchAll(PDO::FETCH_COLUMN);
}

/** Every station with a report, newest first, for the admin. */
function listen_overview(): array
{
    $rows = q('SELECT s.smapi_id, s.title, s.active, l.last_seen, l.stopped FROM r1_listen_status l
               JOIN r1_stations s ON s.id = l.station_id ORDER BY l.last_seen DESC')->fetchAll();
    $now  = time();
    return array_map(static fn(array $r): array => [
        'smapi_id' => $r['smapi_id'],
        'title'    => $r['title'],
        'state'    => (int) $r['stopped'] === 1 ? 'aus' : ($now - (int) $r['last_seen'] < LISTEN_TTL && $r['active'] ? 'läuft' : 'abgelaufen'),
        'age'      => max(0, $now - (int) $r['last_seen']),
        'at'       => date('d.m. H:i:s', (int) $r['last_seen']),
    ], $rows);
}
