<?php
/**
 * Database access (MySQL / MariaDB via PDO) and schema migrations.
 * All tables use the prefix "r1_" so the app can share a database.
 */
declare(strict_types=1);

const SCHEMA_VERSION = 1;

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

/** Creates or upgrades the tables. Safe to call on every admin request. */
function db_migrate(): void
{
    $current = 0;
    try {
        $current = (int) (q("SELECT v FROM r1_settings WHERE k = 'schema'")->fetchColumn() ?: 0);
    } catch (PDOException) {
        // settings table does not exist yet
    }
    if ($current >= SCHEMA_VERSION) {
        return;
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
