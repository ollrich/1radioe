<?php
/**
 * 1radiœ: shared bootstrap. Loaded by smapi.php and the admin.
 *
 * The configuration lives OUTSIDE the web root in a folder named "1radioe-private".
 * It is searched upwards from the app folder, so this layout works:
 *
 *   /www/htdocs/w0123456/1radioe-private/config.php    <- config, not reachable via web
 *   /www/htdocs/w0123456/eichhof.co/1radioe/            <- this app
 */
declare(strict_types=1);

const APP_NAME    = '1radiœ';
const APP_VERSION = '1.2.1';
const APP_DIR     = __DIR__ . '/..';
const CONFIG_DIR  = '1radioe-private';

// Warnings must never leak into HTML, JSON or SOAP output.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/db.php';
require __DIR__ . '/stations.php';
require __DIR__ . '/tracks.php';
require __DIR__ . '/net.php';

date_default_timezone_set((string) cfg('timezone', 'Europe/Berlin'));

/** Absolute path of config.php, or null if none was found. */
function config_path(): ?string
{
    static $path = false;
    if ($path !== false) {
        return $path;
    }
    $path = null;
    $dir  = dirname(realpath(APP_DIR) ?: APP_DIR);
    for ($i = 0; $i < 10; $i++) {
        $candidate = $dir . '/' . CONFIG_DIR . '/config.php';
        if (@is_file($candidate)) {   // @: paths outside open_basedir only warn
            $path = $candidate;
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return $path;
}

/** The configuration array (empty if config.php is missing). */
function config(): array
{
    static $config = null;
    if ($config === null) {
        $path = config_path();
        if ($path !== null && function_exists('opcache_invalidate')) {
            // pick up a freshly uploaded config.php immediately (only recompiles if the file changed)
            @opcache_invalidate($path, false);
        }
        $loaded = $path !== null ? require $path : [];
        $config = is_array($loaded) ? $loaded : [];
    }
    return $config;
}

/** Reads a config value by dotted key, e.g. cfg('db.host'). */
function cfg(string $key, mixed $default = null): mixed
{
    $value = config();
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/* ---------- Storage ----------
 *
 * Uploaded content never lives in the app folder, so an update may replace that
 * folder completely:
 *
 *   1radioe-private/inbox/   FTP inbox for MP3 files          (outside the web root)
 *   1radioe-private/tmp/     chunks of running uploads        (outside the web root)
 *   1radioe-media/           logos and covers, next to the app folder (public)
 *   1radioe-media/tracks/    MP3 files                        (public)
 *
 * media_dir and media_url can be set in config.php if another location is wanted.
 */

/** Folder for logos, covers and tracks. */
function media_dir(): string
{
    $dir = trim((string) cfg('media_dir', ''));
    return $dir !== '' ? rtrim($dir, '/') : dirname(realpath(APP_DIR) ?: APP_DIR) . '/1radioe-media';
}

function track_dir(): string
{
    return media_dir() . '/tracks';
}

/** The private folder that holds config.php. */
function private_dir(): string
{
    return dirname((string) config_path());
}

function upload_dir(): string
{
    return private_dir() . '/tmp';
}

function inbox_dir(): string
{
    return private_dir() . '/inbox';
}

/** Public URL of the media folder. $levels as for base_url(). */
function media_url(int $levels = 0): string
{
    $configured = trim((string) cfg('media_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    // sibling of the app folder: https://eichhof.co/1radioe -> https://eichhof.co/1radioe-media
    $p      = parse_url(base_url($levels));
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $parent = rtrim(str_replace('\\', '/', dirname($p['path'] ?? '/')), '/');
    return $origin . $parent . '/1radioe-media';
}

/** True if config.php sits inside the web root (works, but should be moved out). */
function config_in_webroot(): bool
{
    $path = config_path();
    $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    return $path !== null && $root !== false && $root !== '' && str_starts_with(realpath($path) ?: $path, $root . '/');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Public base URL of the app, e.g. https://eichhof.co/1radioe.
 * Taken from config 'base_url', otherwise derived from the current request.
 * $levels: how many folders the running script sits below the app folder.
 */
function base_url(int $levels = 0): string
{
    $configured = trim((string) cfg('base_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $path = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    for ($i = 0; $i < $levels; $i++) {
        $path = dirname($path);
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https' : 'http') . '://' . $host . rtrim($path, '/');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Makes text from outside (MP3 tags, file names, form input) safe to store and show:
 * valid UTF-8 only, no control characters, trimmed.
 */
function clean_text(string $s): string
{
    $s = mb_scrub($s, 'UTF-8');
    return trim((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{FEFF}]~u', '', $s));
}

/** An ini size value like "128M" in bytes; -1 or empty means unlimited (PHP_INT_MAX). */
function ini_bytes(string $key): int
{
    $value = trim((string) ini_get($key));
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $number = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g'     => $number * 1024 ** 3,
        'm'     => $number * 1024 ** 2,
        'k'     => $number * 1024,
        default => $number,
    };
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // invalid UTF-8 (e.g. from old MP3 tags) must never turn the answer into an empty page
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Thrown for invalid user input; carries messages per form field. */
final class InputError extends RuntimeException
{
    public function __construct(public readonly array $fields, string $message = 'Bitte Eingaben prüfen.')
    {
        parent::__construct($message);
    }
}
