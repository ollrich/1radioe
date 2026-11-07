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
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
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
    session_begin();
    return null;
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
