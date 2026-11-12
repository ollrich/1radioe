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
        // a listening session runs from the first report until "off" or a timeout;
        // its start ("since") lets posting wait until a station really keeps playing
        $now     = time();
        $row     = q('SELECT last_seen, stopped, since, posted_since FROM r1_listen_status WHERE station_id = ?', [$id])->fetch();
        $running = $row && (int) $row['stopped'] === 0 && (int) $row['last_seen'] > $now - LISTEN_TTL && (int) $row['since'] > 0;
        $since   = $running ? (int) $row['since'] : $now;
        q('INSERT INTO r1_listen_status (station_id, last_seen, stopped, since) VALUES (?, ?, 0, ?)
           ON DUPLICATE KEY UPDATE last_seen = ?, stopped = 0, since = ?', [$id, $now, $since, $now, $since]);
        social_heard($station, $since, $now, $row ? (int) $row['posted_since'] : 0);
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

/* ---------- Devices allowed to report: listening cookie (browser) and later app tokens ----------
 *
 * After logging in to the admin, Ollie can let a browser remember that it may report
 * the listening status. It gets a long-lived cookie with a random token, which allows
 * POST /api/listen and nothing else: it never opens the admin. Only the SHA-256 of the
 * token is stored, the token itself exists only in the cookie.
 */

const LISTEN_COOKIE      = 'r1listen';
const LISTEN_COOKIE_DAYS = 180;

/** Creates a device and returns ['device' => row, 'token' => plain token, shown or set once]. */
function listen_device_create(string $name, string $kind): array
{
    $name = clean_text($name);
    if ($name === '' || mb_strlen($name) > 80) {
        throw new InputError(['name' => 'Bitte einen Namen mit höchstens 80 Zeichen angeben.']);
    }
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO r1_listen_devices (name, kind, token_hash, created_at) VALUES (?, ?, ?, ?)',
        [$name, $kind === 'app' ? 'app' : 'browser', hash('sha256', $token), now_sql()]);
    return ['device' => listen_device_find((int) db()->lastInsertId()), 'token' => $token];
}

function listen_device_find(int $id): ?array
{
    return q('SELECT * FROM r1_listen_devices WHERE id = ?', [$id])->fetch() ?: null;
}

/** The device a token belongs to, if it exists and was not revoked. */
function listen_device_by_token(string $token, ?string $kind = null): ?array
{
    if (!preg_match('~^[a-f0-9]{64}$~', $token)) {
        return null;
    }
    $d = q('SELECT * FROM r1_listen_devices WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $token)])->fetch() ?: null;
    return $d !== null && ($kind === null || $d['kind'] === $kind) ? $d : null;
}

/** The browser device of the current request (listening cookie), or null. */
function listen_current_device(): ?array
{
    return listen_device_by_token((string) ($_COOKIE[LISTEN_COOKIE] ?? ''), 'browser');
}

function listen_devices(): array
{
    return q('SELECT * FROM r1_listen_devices ORDER BY revoked_at IS NOT NULL, created_at DESC')->fetchAll();
}

function listen_device_revoke(int $id): void
{
    q('UPDATE r1_listen_devices SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [now_sql(), $id]);
}

/**
 * Notes the use of a device for the admin overview, at most every ten minutes, and
 * keeps a browser's cookie alive for another LISTEN_COOKIE_DAYS, at most once a day.
 */
function listen_device_touch(array $device, ?string $token = null): void
{
    $last = $device['last_used_at'] !== null ? strtotime((string) $device['last_used_at']) : 0;
    if (time() - $last < 600) {
        return;
    }
    q('UPDATE r1_listen_devices SET last_used_at = ? WHERE id = ?', [now_sql(), (int) $device['id']]);
    if ($token !== null && time() - $last > 86400) {
        listen_cookie_set($token);
    }
}

/** HttpOnly, SameSite=Lax, for the whole site, secure on HTTPS. */
function listen_cookie_set(string $token): void
{
    setcookie(LISTEN_COOKIE, $token, [
        'expires'  => time() + LISTEN_COOKIE_DAYS * 86400,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function listen_cookie_clear(): void
{
    setcookie(LISTEN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** A device row for the admin, without the token hash. */
function listen_device_public(array $d, ?int $currentId = null): array
{
    return [
        'id'        => (int) $d['id'],
        'name'      => $d['name'],
        'kind'      => $d['kind'],
        'created'   => date('d.m.Y', (int) strtotime((string) $d['created_at'])),
        'last_used' => $d['last_used_at'] !== null ? date('d.m.Y H:i', (int) strtotime((string) $d['last_used_at'])) : null,
        'revoked'   => $d['revoked_at'] !== null ? date('d.m.Y', (int) strtotime((string) $d['revoked_at'])) : null,
        'this'      => $currentId !== null && (int) $d['id'] === $currentId,
    ];
}

/** Every station with a report, newest first, for the admin. */
function listen_overview(): array
{
    $rows = q('SELECT s.smapi_id, s.title, s.active, l.last_seen, l.stopped, l.since FROM r1_listen_status l
               JOIN r1_stations s ON s.id = l.station_id ORDER BY l.last_seen DESC')->fetchAll();
    $now  = time();
    return array_map(static fn(array $r): array => [
        'smapi_id' => $r['smapi_id'],
        'title'    => $r['title'],
        'state'    => (int) $r['stopped'] === 1 ? 'aus' : ($now - (int) $r['last_seen'] < LISTEN_TTL && $r['active'] ? 'läuft' : 'abgelaufen'),
        'age'      => max(0, $now - (int) $r['last_seen']),
        'at'       => date('d.m. H:i:s', (int) $r['last_seen']),
        'since'    => (int) $r['since'] > 0 ? date('H:i', (int) $r['since']) : '',
    ], $rows);
}
