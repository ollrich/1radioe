<?php
/**
 * Admin authentication: session, login with password and TOTP, CSRF protection,
 * brute force limits. Credentials come from config.php (password as hash only).
 */
declare(strict_types=1);

const SESSION_IDLE     = 2 * 3600;    // logout after 2 hours without activity
const SESSION_MAX      = 12 * 3600;   // and at the latest after 12 hours
const LOGIN_WINDOW     = 15 * 60;
const LOGIN_MAX_IP     = 5;           // failed logins per IP within the window
const LOGIN_MAX_GLOBAL = 30;          // failed logins overall within the window

function session_begin(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    session_name('r1admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    if (!empty($_SESSION['user'])) {
        $now = time();
        if ($now - ($_SESSION['seen_at'] ?? 0) > SESSION_IDLE || $now - ($_SESSION['login_at'] ?? 0) > SESSION_MAX) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['seen_at'] = $now;
        }
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

/** True while password hash or TOTP secret are missing in config.php. */
function setup_needed(): bool
{
    return trim((string) cfg('admin.password_hash', '')) === '' || trim((string) cfg('admin.totp_secret', '')) === '';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Seconds until the next login attempt is allowed (0 = allowed now). */
function login_wait(): int
{
    $since  = time() - LOGIN_WINDOW;
    q('DELETE FROM r1_login_attempts WHERE at < ?', [$since]);
    $byIp   = q('SELECT COUNT(*), MIN(at) FROM r1_login_attempts WHERE ip = ? AND at >= ?', [client_ip(), $since])->fetch(PDO::FETCH_NUM);
    $global = (int) q('SELECT COUNT(*) FROM r1_login_attempts WHERE at >= ?', [$since])->fetchColumn();
    if ((int) $byIp[0] >= LOGIN_MAX_IP) {
        return max(1, (int) $byIp[1] + LOGIN_WINDOW - time());
    }
    return $global >= LOGIN_MAX_GLOBAL ? 60 : 0;
}

/**
 * Checks user, password and TOTP code. On success the session is renewed.
 * Returns null on success or an error message.
 */
function login_attempt(string $user, string $password, string $code): ?string
{
    $wait = login_wait();
    if ($wait > 0) {
        return 'Zu viele Fehlversuche. Bitte in ' . ceil($wait / 60) . ' Minuten erneut versuchen.';
    }
    $userOk = hash_equals((string) cfg('admin.user', ''), $user);
    $passOk = password_verify($password, (string) cfg('admin.password_hash', ''));
    $step   = ($userOk && $passOk) ? totp_verify((string) cfg('admin.totp_secret', ''), $code) : null;
    $last   = (int) setting('totp_last_step', '0');
    if ($step === null || $step <= $last) {   // codes can be used only once
        q('INSERT INTO r1_login_attempts (ip, at) VALUES (?, ?)', [client_ip(), time()]);
        return 'Anmeldung fehlgeschlagen. Bitte Benutzer, Passwort und Code prüfen.';
    }
    setting_set('totp_last_step', (string) $step);
    q('DELETE FROM r1_login_attempts WHERE ip = ?', [client_ip()]);
    session_regenerate_id(true);
    $_SESSION = ['user' => $user, 'login_at' => time(), 'seen_at' => time(), 'csrf' => bin2hex(random_bytes(32))];
    return null;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}

/* ---------- TOTP (RFC 6238: 30 seconds, 6 digits, SHA1) ---------- */

function base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits     = '';
    foreach (str_split($data) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text     = strtoupper((string) preg_replace('~[^A-Za-z2-7]~', '', $text));
    $bits     = '';
    foreach (str_split($text) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, int $step): string
{
    $hash   = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $value  = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
}

/** Returns the matching time step (allowing 30 seconds of clock drift) or null. */
function totp_verify(string $secret, string $code): ?int
{
    $code = preg_replace('~\s+~', '', $code);
    if ($secret === '' || !preg_match('~^\d{6}$~', (string) $code)) {
        return null;
    }
    $now = intdiv(time(), 30);
    for ($step = $now - 1; $step <= $now + 1; $step++) {
        if (hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secret, string $user): string
{
    $label = rawurlencode(APP_NAME) . ':' . rawurlencode($user);
    return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode(APP_NAME) . '&algorithm=SHA1&digits=6&period=30';
}
