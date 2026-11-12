<?php
/**
 * Database access (MySQL / MariaDB via PDO) and schema migrations.
 * All tables use the prefix "r1_" so the app can share a database.
 */
declare(strict_types=1);

const SCHEMA_VERSION = 7;   // 1: stations (1.0)  2: playlists and tracks (1.1)  3: duplicate check (1.2)  4: categories (1.3)  5: listening status (1.5)  6: posting (1.9)  7: now playing (1.10)

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) cfg('db.host', 'localhost'),
            (int) cfg('db.port', 3306),
            (string) cfg('db.name', '')
        );
        $pdo = new PDO($dsn, (string) cfg('db.user', ''), (string) cfg('db.pass', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Runs a prepared statement and returns it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Schema version stored in the database, 0 if there are no tables yet. */
function db_schema(): int
{
    try {
        return (int) (q("SELECT v FROM r1_settings WHERE k = 'schema'")->fetchColumn() ?: 0);
    } catch (PDOException) {
        return 0;   // settings table does not exist yet
    }
}

/**
 * Creates or upgrades the tables. Cheap when nothing is to do (one query), so it runs
 * on every admin and SMAPI request; a lock keeps parallel requests from migrating twice.
 */
function db_migrate(): void
{
    if (db_schema() >= SCHEMA_VERSION) {
        return;
    }
    $lock = 'r1_migrate_' . substr(md5((string) cfg('db.name', '')), 0, 16);   // lock names: max. 64 characters
    q('SELECT GET_LOCK(?, 20)', [$lock]);
    try {
        db_migrate_locked();
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

function db_migrate_locked(): void
{
    $current = db_schema();
    if ($current >= SCHEMA_VERSION) {
        return;   // another request was faster
    }
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    db()->exec("CREATE TABLE IF NOT EXISTS r1_settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    ) $opts");
    db()->exec("CREATE TABLE IF NOT EXISTS r1_stations (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        smapi_id    VARCHAR(64)  NOT NULL,
        title       VARCHAR(120) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        stream_url  VARCHAR(1000) NOT NULL,
        mime        VARCHAR(64)  NOT NULL DEFAULT 'audio/mpeg',
        is_playlist TINYINT(1)   NOT NULL DEFAULT 0,
        logo        VARCHAR(64)  NULL,
        active      TINYINT(1)   NOT NULL DEFAULT 1,
        sort        INT          NOT NULL DEFAULT 0,
        check_ok    TINYINT(1)   NULL,
        check_info  VARCHAR(255) NULL,
        checked_at  DATETIME     NULL,
        created_at  DATETIME     NOT NULL,
        updated_at  DATETIME     NOT NULL,
        UNIQUE KEY uq_smapi (smapi_id),
        KEY k_sort (active, sort)
    ) $opts");
    db()->exec("CREATE TABLE IF NOT EXISTS r1_playlist_cache (
        url_hash CHAR(64)      NOT NULL PRIMARY KEY,
        stream   VARCHAR(1000) NOT NULL,
        until_ts INT UNSIGNED  NOT NULL
    ) $opts");
    db()->exec("CREATE TABLE IF NOT EXISTS r1_login_attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45)  NOT NULL,
        at INT UNSIGNED NOT NULL,
        KEY k_ip (ip, at),
        KEY k_at (at)
    ) $opts");
    db()->exec("CREATE TABLE IF NOT EXISTS r1_request_log (
        id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        at     INT UNSIGNED NOT NULL,
        ip     VARCHAR(45)  NOT NULL,
        agent  VARCHAR(120) NOT NULL,
        method VARCHAR(64)  NOT NULL,
        item   VARCHAR(120) NOT NULL,
        status SMALLINT     NOT NULL,
        note   VARCHAR(255) NOT NULL
    ) $opts");
    if ($current < 2) {
        // 1.1: a row in r1_stations is either a stream or a playlist of uploaded MP3 tracks
        $cols = q("SHOW COLUMNS FROM r1_stations LIKE 'kind'")->fetch();
        if (!$cols) {
            db()->exec("ALTER TABLE r1_stations ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'stream' AFTER smapi_id");
        }
        db()->exec("CREATE TABLE IF NOT EXISTS r1_tracks (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            file       VARCHAR(64)  NOT NULL,
            orig_name  VARCHAR(255) NOT NULL DEFAULT '',
            title      VARCHAR(200) NOT NULL,
            artist     VARCHAR(200) NOT NULL DEFAULT '',
            album      VARCHAR(200) NOT NULL DEFAULT '',
            duration   INT UNSIGNED NOT NULL DEFAULT 0,
            bitrate    INT UNSIGNED NOT NULL DEFAULT 0,
            size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cover      VARCHAR(64)  NULL,
            created_at DATETIME     NOT NULL,
            updated_at DATETIME     NOT NULL,
            UNIQUE KEY uq_file (file)
        ) $opts");
        db()->exec("CREATE TABLE IF NOT EXISTS r1_playlist_tracks (
            playlist_id INT UNSIGNED NOT NULL,
            track_id    INT UNSIGNED NOT NULL,
            pos         INT NOT NULL DEFAULT 0,
            PRIMARY KEY (playlist_id, track_id),
            KEY k_pos (playlist_id, pos),
            KEY k_track (track_id)
        ) $opts");
    }
    if ($current < 3) {
        // 1.2: content hash to recognise a file that is already in the library.
        // Filled when a track is imported; older tracks get it when a file of the same size arrives.
        if (!q("SHOW COLUMNS FROM r1_tracks LIKE 'sha1'")->fetch()) {
            db()->exec('ALTER TABLE r1_tracks ADD COLUMN sha1 CHAR(40) NULL AFTER size, ADD KEY k_size (size)');
        }
    }
    if ($current < 4) {
        // 1.3: rows with kind = 'category' are folders; streams and playlists may belong to one
        if (!q("SHOW COLUMNS FROM r1_stations LIKE 'category_id'")->fetch()) {
            db()->exec('ALTER TABLE r1_stations ADD COLUMN category_id INT UNSIGNED NULL AFTER kind, ADD KEY k_category (category_id, sort)');
        }
    }
    if ($current < 5) {
        // 1.5: website of a station, HTTPS check of its stream, listening status and the
        // devices allowed to report it. All additive: an older installation sharing this
        // database keeps working.
        if (!q("SHOW COLUMNS FROM r1_stations LIKE 'website'")->fetch()) {
            db()->exec("ALTER TABLE r1_stations ADD COLUMN website VARCHAR(500) NOT NULL DEFAULT '' AFTER description");
        }
        if (!q("SHOW COLUMNS FROM r1_stations LIKE 'https_ok'")->fetch()) {
            db()->exec('ALTER TABLE r1_stations ADD COLUMN https_ok TINYINT(1) NULL AFTER check_info');
        }
        // one row per station, deliberately without source, device or IP
        db()->exec("CREATE TABLE IF NOT EXISTS r1_listen_status (
            station_id INT UNSIGNED NOT NULL PRIMARY KEY,
            last_seen  INT UNSIGNED NOT NULL,
            stopped    TINYINT(1)   NOT NULL DEFAULT 0
        ) $opts");
        db()->exec("CREATE TABLE IF NOT EXISTS r1_listen_devices (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name         VARCHAR(80)  NOT NULL,
            kind         VARCHAR(16)  NOT NULL,
            token_hash   CHAR(64)     NOT NULL,
            created_at   DATETIME     NOT NULL,
            last_used_at DATETIME     NULL,
            revoked_at   DATETIME     NULL,
            UNIQUE KEY uq_token (token_hash)
        ) $opts");
    }
    if ($current < 6) {
        // 1.9: posting to Bluesky and Mastodon. Per station: may it be posted, own hashtags.
        // Per listening session: when it began and whether it was already considered for
        // a post. Additive as well.
        if (!q("SHOW COLUMNS FROM r1_stations LIKE 'social'")->fetch()) {
            db()->exec("ALTER TABLE r1_stations ADD COLUMN social TINYINT(1) NOT NULL DEFAULT 1 AFTER website, ADD COLUMN hashtags VARCHAR(255) NOT NULL DEFAULT '' AFTER social");
        }
        if (!q("SHOW COLUMNS FROM r1_listen_status LIKE 'since'")->fetch()) {
            db()->exec('ALTER TABLE r1_listen_status ADD COLUMN since INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN posted_since INT UNSIGNED NOT NULL DEFAULT 0');
        }
        db()->exec("CREATE TABLE IF NOT EXISTS r1_social_posts (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            station_id  INT UNSIGNED NULL,
            title       VARCHAR(120) NOT NULL DEFAULT '',
            kind        VARCHAR(8)   NOT NULL,
            created_at  INT UNSIGNED NOT NULL,
            outcome     VARCHAR(16)  NOT NULL,
            note        VARCHAR(255) NOT NULL DEFAULT '',
            text        TEXT         NULL,
            bsky_url    VARCHAR(255) NULL,
            bsky_error  VARCHAR(255) NULL,
            masto_url   VARCHAR(255) NULL,
            masto_error VARCHAR(255) NULL,
            KEY k_station (station_id, created_at),
            KEY k_at (created_at)
        ) $opts");
    }
    if ($current < 7) {
        // 1.10: the song title last read from a stream, kept for a few seconds
        db()->exec("CREATE TABLE IF NOT EXISTS r1_now_playing (
            station_id INT UNSIGNED NOT NULL PRIMARY KEY,
            text       VARCHAR(300) NULL,
            fetched_at INT UNSIGNED NOT NULL
        ) $opts");
    }
    setting_set('schema', (string) SCHEMA_VERSION);
    if (setting('catalog') === null) {
        catalog_bump();
    }
}

function setting(string $key, ?string $default = null): ?string
{
    $value = q('SELECT v FROM r1_settings WHERE k = ?', [$key])->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setting_set(string $key, string $value): void
{
    q('INSERT INTO r1_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = ?', [$key, $value, $value]);
}

/**
 * The catalog marker tells Sonos that the station list changed (getLastUpdate).
 * Bumped on every change to stations.
 */
function catalog_bump(): void
{
    setting_set('catalog', bin2hex(random_bytes(4)));
}

function catalog_token(): string
{
    return setting('catalog', '0');
}

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}
