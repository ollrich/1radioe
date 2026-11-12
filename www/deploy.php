<?php
/**
 * 1radiœ: GitHub webhook deploy.
 *
 * A push to main makes GitHub POST here. After the HMAC signature check the checkout
 * (the folder above www/) is set to origin/main:
 *
 *   git fetch origin && git reset --hard origin/main
 *
 * IMPORTANT: uploads live in www/media/, which is untracked (.gitignore). A hard
 * reset leaves untracked files alone, that is what keeps them safe. Never add
 * "git clean" here and never commit anything into www/media/.
 *
 * This file is deliberately self-contained and does not load the app: a commit that
 * breaks the app must not break the deploy that fixes it.
 *
 * Secret and log live in 1radioe-private/ next to the checkout:
 *   webhook-secret   the shared secret from the GitHub webhook (fail-closed: no secret, no deploy)
 *   deploy.log       last 100 entries
 */
declare(strict_types=1);

const DEPLOY_BRANCH  = 'main';
const DEPLOY_LOG_MAX = 100;
const PRIVATE_NAME   = '1radioe-private';

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Berlin');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$checkout = dirname(__DIR__);
$private  = deploy_private_dir($checkout);

/** The folder 1radioe-private, searched upwards from the checkout like the app does. */
function deploy_private_dir(string $from): ?string
{
    $dir = realpath($from) ?: $from;
    for ($i = 0; $i < 10; $i++) {
        if (@is_dir($dir . '/' . PRIVATE_NAME)) {
            return $dir . '/' . PRIVATE_NAME;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

function deploy_log(?string $private, string $message): void
{
    if ($private === null) {
        error_log('1radioe deploy: ' . $message);
        return;
    }
    $file  = $private . '/deploy.log';
    $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $lines[] = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message);
    @file_put_contents($file, implode("\n", array_slice($lines, -DEPLOY_LOG_MAX)) . "\n", LOCK_EX);
}

function deploy_stop(?string $private, int $status, string $answer, ?string $log = null): never
{
    if ($log !== null) {
        deploy_log($private, $log);
    }
    http_response_code($status);
    echo $answer . "\n";
    exit;
}

/** Runs git in the checkout. Returns [exit code, output]. */
function deploy_git(string $checkout, string $args): array
{
    $output = [];
    $code   = 0;
    exec('git -C ' . escapeshellarg($checkout) . ' ' . $args . ' 2>&1', $output, $code);
    return [$code, trim(implode("\n", $output))];
}

/* ---------- Checks ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    deploy_stop($private, 405, 'POST only');
}
$secret = $private !== null ? trim((string) @file_get_contents($private . '/webhook-secret')) : '';
if ($secret === '') {
    deploy_stop($private, 500, 'Server misconfigured', 'ERROR: webhook-secret missing or empty, refusing to deploy');
}
$payload   = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($signature === '' || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $signature)) {
    deploy_stop($private, 403, 'Invalid signature', 'ERROR: invalid or missing signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
}
$event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
if ($event === 'ping') {
    deploy_stop($private, 200, 'pong', 'INFO: ping from GitHub, webhook works');
}
if ($event !== 'push') {
    deploy_stop($private, 202, 'Ignored', "INFO: ignoring event '{$event}'");
}
$data = json_decode($payload, true);
if (!is_array($data)) {
    deploy_stop($private, 400, 'Invalid payload', 'ERROR: payload is not JSON (content type must be application/json)');
}
$branch = preg_replace('~^refs/heads/~', '', (string) ($data['ref'] ?? ''));
if ($branch !== DEPLOY_BRANCH) {
    deploy_stop($private, 202, 'Ignored', "INFO: push to '{$branch}' ignored, only " . DEPLOY_BRANCH . ' is deployed');
}
if (!function_exists('exec')) {
    deploy_stop($private, 500, 'exec unavailable', 'ERROR: PHP function exec() is disabled');
}

/* ---------- Deploy ---------- */

// git over SSH needs a home folder for keys and known_hosts; PHP often runs without one
if ((string) getenv('HOME') === '' && $private !== null) {
    putenv('HOME=' . dirname($private));
}
// one deploy at a time; a second push waits for the first one
$lock = $private !== null ? @fopen($private . '/deploy.lock', 'c') : false;
if ($lock !== false) {
    flock($lock, LOCK_EX);
}
@set_time_limit(120);

[$code, $out] = deploy_git($checkout, 'fetch --prune origin');
if ($code !== 0) {
    deploy_stop($private, 500, 'git fetch failed', 'ERROR: git fetch failed: ' . $out);
}
[$code, $out] = deploy_git($checkout, 'reset --hard ' . escapeshellarg('origin/' . DEPLOY_BRANCH));
if ($code !== 0) {
    deploy_stop($private, 500, 'git reset failed', 'ERROR: git reset failed: ' . $out);
}
if (function_exists('opcache_reset')) {
    @opcache_reset();   // new code takes effect right away
}
if ($lock !== false) {
    flock($lock, LOCK_UN);
    fclose($lock);
}

[, $head] = deploy_git($checkout, "log -1 --format='%h %s'");
$pusher   = (string) ($data['pusher']['name'] ?? '?');
deploy_stop($private, 200, 'Deployed ' . $head, 'SUCCESS: ' . substr($head, 0, 120) . ' (pushed by ' . $pusher . ')');
