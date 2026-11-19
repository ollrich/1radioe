<?php
/**
 * 1radiœ: shared bootstrap. Loaded by every script in www/.
 *
 * Layout on the server, the domain points at www/:
 *
 *   /www/htdocs/w0123456/1radioe.net/                 <- git checkout (APP_ROOT)
 *     1radioe-private/config.php                      <- config and secrets, gitignored
 *     app/                                            <- this code, outside the web root
 *     www/                                            <- document root (WEB_ROOT)
 *     www/media/                                      <- uploads, untracked
 *
 * The folder "1radioe-private" is looked for in the checkout first, then in the
 * folders above it. It is gitignored and outside the web root, and the deploy's
 * "git reset --hard" leaves it alone. Never run "git clean -x" on the server.
 */
declare(strict_types=1);

const APP_NAME    = '1radiœ';
const APP_VERSION = '1.29.0';
const CONFIG_DIR  = '1radioe-private';
define('APP_ROOT', dirname(__DIR__));
define('WEB_ROOT', APP_ROOT . '/www');

// Warnings must never leak into HTML, JSON or SOAP output.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/db.php';
require __DIR__ . '/stations.php';
require __DIR__ . '/tracks.php';
require __DIR__ . '/net.php';
require __DIR__ . '/image.php';
require __DIR__ . '/listen.php';
require __DIR__ . '/social.php';
require __DIR__ . '/nowplaying.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/youtube.php';
require __DIR__ . '/texts.php';
require __DIR__ . '/brand.php';

date_default_timezone_set((string) cfg('timezone', 'Europe/Berlin'));

/** Absolute path of config.php, or null if none was found. */
function config_path(): ?string
{
    static $path = false;
    if ($path !== false) {
        return $path;
    }
    $path = null;
    // the tests (tests/run.php) bring a config of their own with a database of their own;
    // only on the command line, a web request never reads it
    $test = PHP_SAPI === 'cli' ? getenv('R1_CONFIG') : false;
    if (is_string($test) && $test !== '') {
        return $path = is_file($test) ? $test : null;
    }
    $dir  = realpath(APP_ROOT) ?: APP_ROOT;
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
 * Uploaded content is never tracked by git. The deploy runs "git reset --hard", which
 * leaves untracked files alone, so uploads survive every deploy:
 *
 *   1radioe-private/inbox/   FTP inbox for MP3 files          (outside the web root)
 *   1radioe-private/tmp/     chunks of running uploads        (outside the web root)
 *   www/media/               logos and covers                 (public, gitignored)
 *   www/media/tracks/        MP3 files                        (public, gitignored)
 *
 * media_dir and media_url can be set in config.php if another location is wanted.
 */

/** Folder for logos, covers and tracks. */
function media_dir(): string
{
    $dir = trim((string) cfg('media_dir', ''));
    return $dir !== '' ? rtrim($dir, '/') : (realpath(WEB_ROOT) ?: WEB_ROOT) . '/media';
}

function track_dir(): string
{
    return media_dir() . '/tracks';
}

/** The private folder that holds config.php; without one, where it belongs in the checkout. */
function private_dir(): string
{
    $path = config_path();
    return $path !== null ? dirname($path) : APP_ROOT . '/' . CONFIG_DIR;
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
    return $configured !== '' ? rtrim($configured, '/') : base_url($levels) . '/media';
}

/**
 * Origin of the media folder for a Content Security Policy, with a leading space,
 * if it differs from the page's own origin (e.g. www. or a configured media_url).
 * $levels as for base_url().
 */
function media_origin(int $levels = 0): string
{
    $p = parse_url(media_url($levels));
    if (!isset($p['scheme'], $p['host'])) {
        return '';
    }
    $origin = strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
    $self   = strtolower((is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
    return $origin !== $self && preg_match('~^https?://[a-z0-9.-]+(:\d+)?$~', $origin) ? ' ' . $origin : '';
}

/** True if config.php sits inside the web root (works, but should be moved out). */
function config_in_webroot(): bool
{
    $path = config_path();
    $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    return $path !== null && $root !== false && $root !== '' && str_starts_with(realpath($path) ?: $path, $root . '/');
}

/**
 * HTTPS as the web server sees it. On All-Inkl Apache handles TLS itself, no proxy
 * in front, so a header like X-Forwarded-Proto is not trusted: anyone can send it.
 */
function is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/**
 * Public base URL of the site, e.g. https://1radioe.net (no trailing slash).
 * Taken from config 'base_url', otherwise derived from the current request.
 * $levels: how many folders the running script sits below www/ (admin/ = 1).
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

/**
 * The address Sonos called: host without port, and path, e.g. "1radioe.net/smapi.php",
 * or "example.org/1radioe/smapi.php" for an installation in a subfolder.
 * $path: default the running script; report.php passes the requested path.
 */
function called_endpoint(?string $path = null): string
{
    $host = strtolower(rtrim((string) preg_replace('~:\d+$~', '', (string) ($_SERVER['HTTP_HOST'] ?? '')), '.'));
    if (!preg_match('~^[a-z0-9.-]{1,100}$~', $host)) {
        $host = '?';
    }
    $path = (string) preg_replace('#[^A-Za-z0-9._/-]#', '', $path ?? (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return substr($host . $path, 0, 160);
}

/**
 * Diagnostic log of requests from Sonos (SMAPI and playback reports), switched on in
 * the admin under System. Keeps the newest 500 entries and never breaks playback.
 */
function sonos_log(string $method, string $item, int $status, string $note): void
{
    try {
        if (setting('log_enabled', '0') !== '1') {
            return;
        }
        $path = str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/report.php')
            ? (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) : null;
        q('INSERT INTO r1_request_log (at, ip, agent, method, item, status, note, endpoint) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            time(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            mb_substr($method, 0, 64), mb_substr($item, 0, 120), $status, mb_substr($note, 0, 255), called_endpoint($path),
        ]);
        if (random_int(1, 25) === 1) {   // keep the newest 500 entries
            q('DELETE FROM r1_request_log WHERE id < (SELECT id FROM (SELECT id FROM r1_request_log ORDER BY id DESC LIMIT 1 OFFSET 499) t)');
        }
    } catch (Throwable) {
        // logging must never break playback
    }
}

/** True if the server can send the response first and keep working afterwards. */
function can_answer_first(): bool
{
    return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
}

/**
 * Runs $job after the response went out (PHP-FPM, LiteSpeed), otherwise at the end of
 * the request. A job with the same $key runs once per request. Errors are logged, never
 * shown. Used for posting and for refreshing .pls/.m3u playlists.
 */
function after_response(string $key, callable $job): void
{
    static $jobs = null;
    if ($jobs === null) {
        $jobs = new ArrayObject();
        register_shutdown_function(static function () use ($jobs): void {
            ignore_user_abort(true);
            @set_time_limit(90);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            foreach ($jobs as $name => $run) {
                try {
                    $run();
                } catch (Throwable $e) {
                    error_log('1radioe ' . $name . ': ' . $e->getMessage());
                }
            }
        });
    }
    $jobs[$key] ??= $job;
}

/** Sends JSON and stops. $noStore false keeps a Cache-Control header set before. */
function json_out(array $data, int $status = 200, bool $noStore = true): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($noStore) {
        header('Cache-Control: no-store');
    }
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
