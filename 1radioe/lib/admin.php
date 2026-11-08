<?php
/**
 * Shared setup for admin pages and the admin API: security headers, HTTPS,
 * session, database migration and the HTML page frame.
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/image.php';

/** Prepares an admin request. Returns null if ready, otherwise the name of a blocking problem. */
function admin_boot(): ?string
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');

    $host  = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if (!is_https() && !$local) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    if (config_path() === null) {
        return 'config';
    }
    try {
        db_migrate();
    } catch (PDOException) {
        return 'db';
    }
    storage_prepare();
    session_begin();
    return null;
}

/** Rules for the public media folder: files only, never scripts, no listing. */
const MEDIA_HTACCESS = <<<'HT'
# 1radiœ media (logos, covers, MP3 tracks). Created by the app.
Options -Indexes -ExecCGI
<FilesMatch "\.(?i:php\d?|phtml|phar|pht|cgi|pl|py|sh|htm|html|svg)$">
    Require all denied
</FilesMatch>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>
<IfModule mod_mime.c>
    AddType audio/mpeg .mp3
</IfModule>

HT;

/**
 * Creates the storage folders outside the app folder and moves content left over
 * from 1.0/1.1.0, where it lived inside the app folder (media/, inbox/, tmp/).
 */
function storage_prepare(): void
{
    foreach ([media_dir(), track_dir(), inbox_dir(), upload_dir()] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    $rules = media_dir() . '/.htaccess';
    if (is_dir(media_dir()) && @file_get_contents($rules) !== MEDIA_HTACCESS) {
        @file_put_contents($rules, MEDIA_HTACCESS);
    }
    $legacy = [
        APP_DIR . '/media'        => media_dir(),
        APP_DIR . '/media/tracks' => track_dir(),
        APP_DIR . '/inbox'        => inbox_dir(),
    ];
    foreach ($legacy as $from => $to) {
        foreach (glob($from . '/*') ?: [] as $file) {
            $target = $to . '/' . basename($file);
            if (is_file($file) && is_dir($to) && !file_exists($target)) {
                if (!@rename($file, $target) && @copy($file, $target)) {
                    @unlink($file);
                }
            }
        }
    }
}

/** Status line for a storage folder on the system page. */
function storage_state(string $dir): string
{
    return $dir . (is_dir($dir) ? (is_writable($dir) ? ' ✓' : ' (nicht beschreibbar)') : ' (fehlt)');
}

/** Outputs a complete HTML page. $scripts are file names in assets/. */
function render_page(string $title, string $body, array $scripts = [], string $bodyClass = ''): never
{
    $csrf = session_status() === PHP_SESSION_ACTIVE ? csrf_token() : '';
    $js   = '';
    foreach ($scripts as $file) {
        $js .= '<script src="assets/' . h($file) . '?v=' . APP_VERSION . '" defer></script>';
    }
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="csrf-token" content="' . h($csrf) . '">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h($title) . ' · ' . h(APP_NAME) . '</title>'
        . '<link rel="icon" href="assets/favicon.png">'
        . '<link rel="stylesheet" href="assets/vendor/cropper.min.css?v=' . APP_VERSION . '">'
        . '<link rel="stylesheet" href="assets/app.css?v=' . APP_VERSION . '">'
        . $js . '</head><body class="' . h($bodyClass) . '">' . $body . '</body></html>';
    exit;
}

/** A centered card page (login, setup, errors). */
function render_card(string $title, string $content, array $scripts = []): never
{
    render_page($title, '<main class="card-page"><div class="card">'
        . '<img class="brand" src="assets/logo.png" alt="' . h(APP_NAME) . '" width="186" height="64">'
        . $content . '</div><p class="card-foot">' . h(APP_NAME) . ' ' . h(APP_VERSION) . '</p></main>', $scripts, 'is-card');
}
