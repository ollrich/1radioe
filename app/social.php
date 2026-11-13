<?php
/**
 * Posting to Bluesky and Mastodon when Ollie starts listening to a station.
 *
 * Trigger: the listening status (listen.php). A listening session starts when a station
 * goes from "not playing" to "playing", no matter from where (Sonos, web player, app).
 * If it still plays after a waiting time, the next report claims the session, once,
 * also with parallel reports. The post goes out after the answer was sent, so Sonos and
 * the web player never wait for Bluesky or Mastodon.
 *
 * Brakes: a global switch, a switch per station, a cooldown per station and a daily
 * limit. Pausing the listening status in the web app also pauses posting, because no
 * reports arrive. A post tells the public as much as the status icon, nothing more:
 * never a device or a source.
 *
 * App password and token are stored encrypted (libsodium, key 'secret_key' in
 * config.php), so a database dump alone gives nothing away. The admin only shows whether
 * they are set.
 */
declare(strict_types=1);

const SOCIAL_BSKY_MAX     = 300;   // graphemes
const SOCIAL_MASTO_MAX    = 500;   // default of most instances; the check reads the real limit
const SOCIAL_MASTO_URL    = 23;    // Mastodon counts every link as 23 characters
const SOCIAL_THUMB_MAX    = 976560;   // Bluesky blob limit for link card images
const SOCIAL_LOG_KEEP     = 500;
const SOCIAL_PLACEHOLDERS = ['{sender}', '{link}', '{website}', '{hashtags}'];

/** Thrown when a network refuses or cannot be reached; the message is shown in the admin. */
final class SocialError extends RuntimeException
{
}

/* ---------- Settings ---------- */

function social_defaults(): array
{
    return [
        'enabled'  => false,
        'hashtags' => '#Radio #NowPlaying',
        'site_url' => '',
        'delay'    => 3,      // minutes a station must keep playing
        'cooldown' => 6,      // hours before the same station is posted again
        'daily'    => 4,      // posts per day at most
        'card'     => true,   // link card with the station logo on Bluesky
        // text and language per network (1.10.3): Ollie posts in English on Bluesky and in German on Mastodon
        'bsky'     => ['enabled' => false, 'pds' => 'https://bsky.social', 'handle' => '', 'secret' => '', 'checked' => '',
                       'template' => "On my radio right now: {sender}\n{link}\n\n{hashtags}", 'lang' => 'en'],
        'masto'    => ['enabled' => false, 'instance' => '', 'secret' => '', 'visibility' => 'public',
                       'max' => SOCIAL_MASTO_MAX, 'url_chars' => SOCIAL_MASTO_URL, 'checked' => '',
                       'template' => "Läuft gerade bei mir: {sender}\n{link}\n\n{hashtags}", 'lang' => 'de'],
    ];
}

/** Stored settings on top of the defaults; unknown keys are dropped. */
function social_settings(): array
{
    $d      = social_defaults();
    $stored = json_decode((string) setting('social', ''), true);
    if (!is_array($stored)) {
        return $d;
    }
    $s = array_replace($d, array_intersect_key($stored, $d));
    foreach (['bsky', 'masto'] as $net) {
        $own = is_array($stored[$net] ?? null) ? array_intersect_key($stored[$net], $d[$net]) : [];
        // up to 1.10.2 both networks shared one text and one language
        foreach (['template', 'lang'] as $k) {
            if (!isset($own[$k]) && is_string($stored[$k] ?? null)) {
                $own[$k] = $stored[$k];
            }
        }
        $s[$net] = array_replace($d[$net], $own);
    }
    return $s;
}

function social_store(array $s): void
{
    setting_set('social', (string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** The address posts link to: set in the admin, else the configured or current base URL. */
function social_site_url(array $s, int $levels = 0): string
{
    $url = trim((string) $s['site_url']);
    return rtrim($url !== '' ? $url : base_url($levels), '/');
}

/** True if a network is switched on and has what it needs. */
function social_ready(array $s, string $net): bool
{
    $n = $s[$net];
    if (!$n['enabled'] || $n['secret'] === '') {
        return false;
    }
    return $net === 'bsky' ? $n['pds'] !== '' && $n['handle'] !== '' : $n['instance'] !== '';
}

/* ---------- Secrets ---------- */

/** The 32 byte key from config.php ('secret_key', base64), or null. */
function social_key(): ?string
{
    if (!function_exists('sodium_crypto_secretbox')) {
        return null;
    }
    $raw = base64_decode(trim((string) cfg('secret_key', '')), true);
    return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $raw : null;
}

/** A fresh key for config.php, shown in the admin while none is set. */
function social_key_example(): string
{
    return base64_encode(random_bytes(32));
}

function social_seal(string $plain): string
{
    $key = social_key() ?? throw new InputError([], 'In config.php fehlt der Schlüssel secret_key.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

function social_unseal(string $sealed): ?string
{
    $key = social_key();
    if ($key === null || !str_starts_with($sealed, 'v1:')) {
        return null;
    }
    $raw = base64_decode(substr($sealed, 3), true);
    if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    return $plain === false ? null : $plain;
}

function social_secret(array $n, string $label): string
{
    return social_unseal((string) $n['secret'])
        ?? throw new SocialError($label . ' lässt sich nicht entschlüsseln. Wurde secret_key in config.php geändert? Dann bitte neu eingeben.');
}

/* ---------- Trigger ---------- */

/**
 * Called by listen_report() for every "playing" report. Cheap when nothing is to do.
 * $postedSince: the session start that was already considered for a post.
 */
function social_heard(array $station, int $since, int $now, int $postedSince): void
{
    try {
        if ($postedSince === $since || (int) ($station['social'] ?? 1) !== 1) {
            return;
        }
        $s = social_settings();
        if (!$s['enabled'] || (!social_ready($s, 'bsky') && !social_ready($s, 'masto'))) {
            return;
        }
        if ($now - $since < (int) $s['delay'] * 60) {
            return;   // not long enough yet, a later report will ask again
        }
        // claim the session; only one of several parallel reports gets the row
        $claimed = q('UPDATE r1_listen_status SET posted_since = ? WHERE station_id = ? AND since = ? AND posted_since <> ?',
            [$since, (int) $station['id'], $since, $since])->rowCount() === 1;
        if ($claimed) {
            social_defer((int) $station['id']);
        }
    } catch (Throwable $e) {
        error_log('1radioe posting: ' . $e->getMessage());   // posting must never break the status
    }
}

/** Runs the post after the response went out (PHP-FPM / LiteSpeed), else at the end of the request. */
function social_defer(int $stationId): void
{
    static $queue = null;
    if ($queue === null) {
        $queue = new ArrayObject();
        register_shutdown_function(static function () use ($queue): void {
            ignore_user_abort(true);
            @set_time_limit(90);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            foreach (array_keys($queue->getArrayCopy()) as $id) {
                try {
                    social_run((int) $id, 'auto');
                } catch (Throwable $e) {
                    error_log('1radioe posting: ' . $e->getMessage());
                }
            }
        });
    }
    $queue[$stationId] = true;
}

/** True if the server can answer first and post afterwards. */
function social_background_ok(): bool
{
    return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
}

/**
 * Posts for a station now. 'auto' respects switches and brakes and logs skips;
 * 'test' (button in the admin) ignores them. Returns the log entry.
 */
function social_run(int $stationId, string $kind = 'auto'): array
{
    $s       = social_settings();
    $station = station_find($stationId);
    if ($station === null || $station['kind'] !== 'stream') {
        throw new InputError([], 'Sender nicht gefunden.');
    }
    $nets = array_values(array_filter(['bsky', 'masto'], static fn($n) => social_ready($s, $n)));
    if ($kind === 'auto') {
        if (!$s['enabled'] || !$nets || (int) $station['social'] !== 1 || (int) $station['active'] !== 1) {
            return [];
        }
        $now  = time();
        $last = (int) q("SELECT MAX(created_at) FROM r1_social_posts WHERE station_id = ? AND kind = 'auto' AND outcome IN ('posted', 'partial')",
            [$stationId])->fetchColumn();
        if ($last > 0 && $now - $last < (int) $s['cooldown'] * 3600) {
            return social_log($station, $kind, 'skipped', 'Sperrfrist, zuletzt gepostet am ' . date('d.m. \u\m H:i', $last));
        }
        $today = (int) q("SELECT COUNT(*) FROM r1_social_posts WHERE kind = 'auto' AND outcome IN ('posted', 'partial') AND created_at >= ?",
            [strtotime('today')])->fetchColumn();
        if ($today >= (int) $s['daily']) {
            return social_log($station, $kind, 'skipped', 'Tageslimit von ' . (int) $s['daily'] . ' Beiträgen erreicht');
        }
    }
    if (!$nets) {
        throw new InputError([], 'Kein Netzwerk eingeschaltet und eingerichtet.');
    }
    $urls   = [];
    $errors = [];
    $text   = '';
    $key    = 'r1-' . $stationId . '-' . bin2hex(random_bytes(8));   // Mastodon: a retry never posts twice
    foreach ($nets as $net) {
        try {
            $post = social_compose($station, $s, $net);
            if ($post['too_long']) {
                throw new SocialError('Text zu lang: ' . $post['length'] . ' von ' . $post['max'] . ' Zeichen, auch ohne Hashtags.');
            }
            $urls[$net] = $net === 'bsky' ? bsky_post($s, $post, $station) : masto_post($s, $post, $key);
            if ($text === '' || $net === 'masto') {
                $text = $post['text'];   // the Mastodon variant keeps the links unshortened
            }
        } catch (SocialError $e) {
            $errors[$net] = $e->getMessage();
        }
    }
    $outcome = !$errors ? 'posted' : ($urls ? 'partial' : 'failed');
    $note    = $kind === 'test' ? 'Testpost' : '';
    return social_log($station, $kind, $outcome, $note, $text, $urls, $errors);
}

/* ---------- Log ---------- */

function social_log(array $station, string $kind, string $outcome, string $note, ?string $text = null, array $urls = [], array $errors = []): array
{
    $cut = static fn(?string $v): ?string => $v === null ? null : mb_substr($v, 0, 255);
    q('INSERT INTO r1_social_posts (station_id, title, kind, created_at, outcome, note, text, bsky_url, bsky_error, masto_url, masto_error)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        (int) $station['id'], mb_substr((string) $station['title'], 0, 120), $kind, time(), $outcome, $cut($note) ?? '', $text,
        $cut($urls['bsky'] ?? null), $cut($errors['bsky'] ?? null), $cut($urls['masto'] ?? null), $cut($errors['masto'] ?? null),
    ]);
    $id     = (int) db()->lastInsertId();
    $oldest = q('SELECT id FROM r1_social_posts ORDER BY id DESC LIMIT 1 OFFSET ' . SOCIAL_LOG_KEEP)->fetchColumn();
    if ($oldest !== false) {
        q('DELETE FROM r1_social_posts WHERE id <= ?', [(int) $oldest]);
    }
    return social_log_public(q('SELECT * FROM r1_social_posts WHERE id = ?', [$id])->fetch());
}

function social_log_rows(int $limit = 100): array
{
    return array_map('social_log_public', q('SELECT * FROM r1_social_posts ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)))->fetchAll());
}

function social_log_public(array $r): array
{
    return [
        'id'      => (int) $r['id'],
        'at'      => date('d.m.Y H:i', (int) $r['created_at']),
        'title'   => $r['title'],
        'kind'    => $r['kind'],
        'outcome' => $r['outcome'],
        'note'    => $r['note'],
        'text'    => $r['text'],
        'bsky'    => ['url' => $r['bsky_url'], 'error' => $r['bsky_error']],
        'masto'   => ['url' => $r['masto_url'], 'error' => $r['masto_error']],
    ];
}

/* ---------- Text ---------- */

/** Hashtags from free input ("#Radio, jazz #Köln") as a clean, unique list without "#". */
function social_tags(string $input): array
{
    $tags = [];
    foreach (preg_split('~[\s,;]+~u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $tag = (string) preg_replace('~[^\p{L}\p{N}_]~u', '', ltrim($word, '#'));
        if ($tag === '' || preg_match('~^\d+$~', $tag) || mb_strlen($tag) > 64) {
            continue;
        }
        $tags[mb_strtolower($tag)] ??= $tag;
    }
    return array_values($tags);
}

/** "#Radio #NowPlaying" for storing and showing. */
function social_tags_text(string $input): string
{
    return implode(' ', array_map(static fn($t) => '#' . $t, social_tags($input)));
}

/** Address of the station page that posts link to: 1radioe.net/s/<smapi_id>. */
function social_link(array $s, array $station, int $levels = 0): string
{
    return social_site_url($s, $levels) . '/s/' . rawurlencode((string) $station['smapi_id']);
}

/** Fills the template; empty placeholders leave no gaps or empty lines behind. */
function social_fill(string $template, array $station, string $link, array $tags): string
{
    $text = strtr($template, [
        '{sender}'   => (string) $station['title'],
        '{link}'     => $link,
        '{website}'  => (string) ($station['website'] ?? ''),
        '{hashtags}' => implode(' ', array_map(static fn($t) => '#' . $t, $tags)),
    ]);
    $text = (string) preg_replace('~[ \t]+~u', ' ', str_replace("\r", '', $text));
    $text = (string) preg_replace('~ *\n *~u', "\n", $text);
    $text = (string) preg_replace('~\n{3,}~u', "\n\n", $text);
    return trim($text);
}

function social_graphemes(string $text): int
{
    return function_exists('grapheme_strlen') ? (int) grapheme_strlen($text) : mb_strlen($text);
}

/** Length as Mastodon counts it: every link as a fixed number of characters. */
function masto_length(string $text, int $urlChars): int
{
    return mb_strlen((string) preg_replace('~https?://\S+~u', str_repeat('x', $urlChars), $text));
}

/**
 * The post for one network: ['text', 'facets' (Bluesky), 'link', 'length', 'max', 'tags',
 * 'dropped', 'too_long']. If it is too long, hashtags are left out from the end.
 */
function social_compose(array $station, array $s, string $net, int $levels = 0): array
{
    $tags = social_tags($s['hashtags'] . ' ' . ($station['hashtags'] ?? ''));
    $link = social_link($s, $station, $levels);
    $max  = $net === 'bsky' ? SOCIAL_BSKY_MAX : max(1, (int) $s['masto']['max']);
    for ($n = count($tags); $n >= 0; $n--) {
        $text = social_fill((string) $s[$net]['template'], $station, $link, array_slice($tags, 0, $n));
        if ($net === 'bsky') {
            $rich   = bsky_richtext($text);
            $length = social_graphemes($rich['text']);
            $post   = ['text' => $rich['text'], 'facets' => $rich['facets']];
        } else {
            $length = masto_length($text, max(1, (int) $s['masto']['url_chars']));
            $post   = ['text' => $text, 'facets' => []];
        }
        if ($length <= $max || $n === 0) {
            return $post + [
                'link'     => $link,
                'length'   => $length,
                'max'      => $max,
                'tags'     => $n,
                'dropped'  => count($tags) - $n,
                'too_long' => $length > $max,
            ];
        }
    }
    throw new LogicException('unreachable');
}

/** "https://www.example.com/a/" as "example.com/a", long ones cut with "…". */
function social_short_url(string $url): string
{
    $short = rtrim((string) preg_replace('~^https?://(www\.)?~i', '', $url), '/');
    return mb_strlen($short) > 48 ? mb_substr($short, 0, 47) . '…' : $short;
}

/**
 * Bluesky needs links and hashtags marked as facets with UTF-8 byte offsets.
 * Links are shown shortened, the facet keeps the full address.
 */
function bsky_richtext(string $text): array
{
    $out    = '';
    $facets = [];
    $pos    = 0;
    if (preg_match_all('~https?://[^\s<>"]+~u', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$url, $offset]) {
            $url  = (string) preg_replace('~[.,;:!?)\]]+$~u', '', $url);   // "… (https://a.b/c)." ends before ")."
            $out .= substr($text, $pos, $offset - $pos);
            $start = strlen($out);
            $out  .= social_short_url($url);
            $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => strlen($out)],
                         'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
            $pos = $offset + strlen($url);
        }
    }
    $out  .= substr($text, $pos);
    $links = $facets;
    if (preg_match_all('~(?<![\p{L}\p{N}_#&/])#([\p{L}\p{N}_]*[\p{L}_][\p{L}\p{N}_]*)~u', $out, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => [$tag, $offset]) {
            $end = $offset + strlen($tag);
            foreach ($links as $l) {
                if ($offset < $l['index']['byteEnd'] && $end > $l['index']['byteStart']) {
                    continue 2;   // part of a link
                }
            }
            $facets[] = ['index' => ['byteStart' => $offset, 'byteEnd' => $end],
                         'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $m[1][$i][0]]]];
        }
    }
    usort($facets, static fn($a, $b) => $a['index']['byteStart'] <=> $b['index']['byteStart']);
    return ['text' => $out, 'facets' => $facets];
}

/** Preview for the admin: both networks, also when they are not set up yet. */
function social_preview(array $s, array $station): array
{
    $out = [];
    foreach (['bsky', 'masto'] as $net) {
        $p = social_compose($station, $s, $net, 1);
        $out[$net] = ['text' => $p['text'], 'length' => $p['length'], 'max' => $p['max'], 'dropped' => $p['dropped'], 'too_long' => $p['too_long']];
    }
    return $out;
}

/* ---------- HTTP ---------- */

/**
 * One request to Bluesky or Mastodon. Only https (http only with allow_private_urls for
 * local tests), no internal addresses, the checked IP is pinned, no redirects.
 * Returns ['status', 'json'].
 */
function social_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
{
    $p      = parse_url($url);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    $local  = (bool) cfg('allow_private_urls', false);
    if (!isset($p['host']) || !($scheme === 'https' || ($scheme === 'http' && $local))) {
        throw new SocialError('Nur https-Adressen sind erlaubt.');
    }
    if (!function_exists('curl_init')) {
        throw new SocialError('Die PHP-Erweiterung curl fehlt.');
    }
    $ip = net_resolve($p['host']);
    if (str_starts_with($ip, '!')) {
        throw new SocialError(substr($ip, 1));
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => '1radioe/' . APP_VERSION,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_RESOLVE        => filter_var(trim($p['host'], '[]'), FILTER_VALIDATE_IP) ? []
            : [$p['host'] . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error    = curl_error($ch);
    if ($response === false) {
        throw new SocialError('Keine Verbindung zu ' . $p['host'] . ': ' . $error);
    }
    $json = json_decode((string) $response, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null];
}

function social_json(array $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/* ---------- Bluesky ---------- */

function bsky_error(array $r, string $what): string
{
    $msg = (string) ($r['json']['message'] ?? $r['json']['error'] ?? 'HTTP ' . $r['status']);
    return match (true) {
        $r['status'] === 401 => $what . ' abgelehnt: Handle oder App-Passwort stimmt nicht.',
        $r['status'] === 429 => $what . ': zu viele Anfragen, Bluesky bremst gerade.',
        default              => $what . ' fehlgeschlagen: ' . mb_substr($msg, 0, 160),
    };
}

function bsky_session(array $b): array
{
    $r = social_request('POST', $b['pds'] . '/xrpc/com.atproto.server.createSession', ['Content-Type: application/json'],
        social_json(['identifier' => $b['handle'], 'password' => social_secret($b, 'Das App-Passwort')]));
    if ($r['status'] !== 200 || empty($r['json']['accessJwt']) || empty($r['json']['did'])) {
        throw new SocialError(bsky_error($r, 'Anmeldung'));
    }
    return $r['json'];
}

/** Uploads the station logo for the link card; null if there is none or it fails. */
function bsky_thumb(string $pds, string $auth, array $station): ?array
{
    $file = (string) ($station['logo'] ?? '');
    $path = media_dir() . '/' . $file;
    if ($file === '' || !is_file($path) || filesize($path) > SOCIAL_THUMB_MAX) {
        return null;
    }
    $mime = str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg';
    try {
        $r = social_request('POST', $pds . '/xrpc/com.atproto.repo.uploadBlob', ['Content-Type: ' . $mime, $auth], (string) file_get_contents($path));
    } catch (SocialError) {
        return null;   // a post without picture is better than no post
    }
    return $r['status'] === 200 && is_array($r['json']['blob'] ?? null) ? $r['json']['blob'] : null;
}

/** Posts and returns the address of the post on bsky.app. */
function bsky_post(array $s, array $post, array $station): string
{
    $b    = $s['bsky'];
    $sess = bsky_session($b);
    $auth = 'Authorization: Bearer ' . $sess['accessJwt'];
    $record = [
        '$type'     => 'app.bsky.feed.post',
        'text'      => $post['text'],
        'createdAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        'langs'     => [$b['lang']],
    ];
    if ($post['facets']) {
        $record['facets'] = $post['facets'];
    }
    if ($s['card']) {
        $desc     = trim((string) $station['description']);
        $external = [
            'uri'         => $post['link'],
            'title'       => (string) $station['title'],
            'description' => $desc !== '' ? mb_substr($desc, 0, 300) : ($b['lang'] === 'de' ? 'Live hören auf ' : 'Listen live on ') . APP_NAME,
        ];
        $thumb = bsky_thumb($b['pds'], $auth, $station);
        if ($thumb !== null) {
            $external['thumb'] = $thumb;
        }
        $record['embed'] = ['$type' => 'app.bsky.embed.external', 'external' => $external];
    }
    $r = social_request('POST', $b['pds'] . '/xrpc/com.atproto.repo.createRecord', ['Content-Type: application/json', $auth],
        social_json(['repo' => $sess['did'], 'collection' => 'app.bsky.feed.post', 'record' => $record]));
    if ($r['status'] !== 200 || empty($r['json']['uri'])) {
        throw new SocialError(bsky_error($r, 'Posten'));
    }
    $rkey = basename((string) $r['json']['uri']);
    return 'https://bsky.app/profile/' . rawurlencode((string) ($sess['handle'] ?? $sess['did'])) . '/post/' . rawurlencode($rkey);
}

/* ---------- Mastodon ---------- */

function masto_error(array $r, string $what): string
{
    $msg = (string) ($r['json']['error'] ?? 'HTTP ' . $r['status']);
    return match (true) {
        $r['status'] === 401 => $what . ' abgelehnt: Token ungültig oder widerrufen.',
        $r['status'] === 403 => $what . ' abgelehnt: dem Token fehlt die Berechtigung write:statuses.',
        $r['status'] === 429 => $what . ': zu viele Anfragen, die Instanz bremst gerade.',
        default              => $what . ' fehlgeschlagen: ' . mb_substr($msg, 0, 160),
    };
}

/** Posts and returns the address of the post. */
function masto_post(array $s, array $post, string $idempotencyKey): string
{
    $m = $s['masto'];
    $r = social_request('POST', $m['instance'] . '/api/v1/statuses', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . social_secret($m, 'Das Token'),
        'Idempotency-Key: ' . $idempotencyKey,
    ], social_json(['status' => $post['text'], 'visibility' => $m['visibility'], 'language' => $m['lang']]));
    if ($r['status'] !== 200 || empty($r['json']['id'])) {
        throw new SocialError(masto_error($r, 'Posten'));
    }
    return (string) ($r['json']['url'] ?? $r['json']['uri'] ?? '');
}

/* ---------- Connection check (admin) ---------- */

/**
 * Logs in without posting and stores what was learned (name, Mastodon character limit).
 * Returns a sentence for the admin.
 */
function social_check(string $net): string
{
    $s = social_settings();
    if ($net === 'bsky') {
        $b = $s['bsky'];
        if ($b['handle'] === '' || $b['secret'] === '') {
            throw new InputError([], 'Bitte Handle und App-Passwort eintragen und speichern.');
        }
        $sess = bsky_session($b);
        $info = 'Angemeldet als @' . ($sess['handle'] ?? $b['handle']);
    } else {
        $m = $s['masto'];
        if ($m['instance'] === '' || $m['secret'] === '') {
            throw new InputError([], 'Bitte Instanz und Token eintragen und speichern.');
        }
        $auth = ['Authorization: Bearer ' . social_secret($m, 'Das Token')];
        $app  = social_request('GET', $m['instance'] . '/api/v1/apps/verify_credentials', $auth);
        if ($app['status'] !== 200) {
            throw new SocialError(masto_error($app, 'Anmeldung'));
        }
        $scopes = $app['json']['scopes'] ?? null;   // Mastodon 4.3 and later
        if (is_array($scopes) && !array_intersect(['write', 'write:statuses'], $scopes)) {
            throw new SocialError('Dem Token fehlt die Berechtigung write:statuses.');
        }
        // the name needs the scope "profile" or "read:accounts"; without it the check still passes
        $acct = social_request('GET', $m['instance'] . '/api/v1/accounts/verify_credentials', $auth);
        $host = (string) parse_url($m['instance'], PHP_URL_HOST);
        $info = $acct['status'] === 200 && !empty($acct['json']['acct'])
            ? 'Angemeldet als @' . $acct['json']['acct'] . '@' . $host
            : 'Token gültig (App „' . ($app['json']['name'] ?? '?') . '“)';
        $inst = social_request('GET', $m['instance'] . '/api/v2/instance');
        $conf = $inst['status'] === 200 ? ($inst['json']['configuration']['statuses'] ?? []) : [];
        if (!$conf) {
            $inst = social_request('GET', $m['instance'] . '/api/v1/instance');
            $conf = $inst['status'] === 200 ? ($inst['json']['configuration']['statuses'] ?? ['max_characters' => $inst['json']['max_toot_chars'] ?? null]) : [];
        }
        $s['masto']['max']       = max(100, (int) ($conf['max_characters'] ?? SOCIAL_MASTO_MAX));
        $s['masto']['url_chars'] = max(1, (int) ($conf['characters_reserved_per_url'] ?? SOCIAL_MASTO_URL));
        $info .= ', bis ' . $s['masto']['max'] . ' Zeichen je Beitrag';
    }
    $s[$net]['checked'] = $info . ' (geprüft am ' . date('d.m.Y H:i') . ')';
    social_store($s);
    return $s[$net]['checked'];
}

/* ---------- Admin: show and save ---------- */

/** Settings for the admin, without secrets. */
function social_public(array $s): array
{
    $out = $s;
    foreach (['bsky', 'masto'] as $net) {
        $out[$net]['secret_set'] = $s[$net]['secret'] !== '';
        unset($out[$net]['secret']);
        $out[$net]['ready'] = social_ready($s, $net);
    }
    $out['site_url_default'] = base_url(1);
    $out['key_ok']           = social_key() !== null;
    $out['key_example']      = $out['key_ok'] ? '' : social_key_example();
    $out['sodium']           = function_exists('sodium_crypto_secretbox');
    $out['background']       = social_background_ok();
    return $out;
}

/** "https://host" from input like "mastodon.social" or "https://host/". */
function social_origin(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }
    $p = parse_url($input);
    if (!isset($p['scheme'], $p['host']) || isset($p['query']) || isset($p['user']) || trim((string) ($p['path'] ?? ''), '/') !== '') {
        return '!';
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'https' && !cfg('allow_private_urls', false)) {
        return '!';
    }
    return $scheme . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
}

/** Validates and stores the admin form. New secrets replace the old, empty keeps them. */
function social_save(array $in): array
{
    $s      = social_settings();
    $errors = [];
    $int    = static fn(string $k, int $min, int $max) => is_numeric($in[$k] ?? null) && (int) $in[$k] >= $min && (int) $in[$k] <= $max ? (int) $in[$k] : null;

    $s['enabled'] = !empty($in['enabled']);
    $s['card']    = !empty($in['card']);

    // text and language per network
    foreach (['bsky', 'masto'] as $net) {
        $n        = is_array($in[$net] ?? null) ? $in[$net] : [];
        $template = trim(str_replace("\r", '', clean_text_multiline((string) ($n['template'] ?? ''))));
        if ($template === '' || mb_strlen($template) > 1000) {
            $errors[$net . '.template'] = 'Bitte einen Text mit höchstens 1000 Zeichen eingeben.';
        } elseif (!str_contains($template, '{sender}')) {
            $errors[$net . '.template'] = 'Der Text braucht den Platzhalter {sender}.';
        } else {
            $s[$net]['template'] = $template;
        }
        $lang = strtolower(trim((string) ($n['lang'] ?? '')));
        if (!preg_match('~^[a-z]{2}$~', $lang)) {
            $errors[$net . '.lang'] = 'Bitte ein Kürzel aus zwei Buchstaben, zum Beispiel de oder en.';
        } else {
            $s[$net]['lang'] = $lang;
        }
    }
    $tags = social_tags_text((string) ($in['hashtags'] ?? ''));
    if (mb_strlen($tags) > 255) {
        $errors['hashtags'] = 'Höchstens 255 Zeichen.';
    } else {
        $s['hashtags'] = $tags;
    }
    // stored explicitly: posts can be triggered from scripts at different folder levels
    $site = rtrim(trim((string) ($in['site_url'] ?? '')), '/');
    if ($site !== '' && (!filter_var($site, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $site))) {
        $errors['site_url'] = 'Bitte eine gültige http(s)-Adresse eingeben.';
    } else {
        $s['site_url'] = $site !== '' ? $site : base_url(1);
    }
    foreach (['delay' => [0, 60], 'cooldown' => [0, 168], 'daily' => [1, 50]] as $k => [$min, $max]) {
        $v = $int($k, $min, $max);
        if ($v === null) {
            $errors[$k] = "Bitte eine Zahl von $min bis $max.";
        } else {
            $s[$k] = $v;
        }
    }
    // Bluesky
    $b  = is_array($in['bsky'] ?? null) ? $in['bsky'] : [];
    $pds = social_origin((string) ($b['pds'] ?? '')) ?: 'https://bsky.social';
    if ($pds === '!') {
        $errors['bsky.pds'] = 'Bitte die https-Adresse des PDS eingeben, zum Beispiel https://bsky.social.';
    }
    $handle = strtolower(ltrim(trim((string) ($b['handle'] ?? '')), '@'));
    if ($handle !== '' && !preg_match('~^(did:[a-z]+:[a-zA-Z0-9._:%-]+|[a-z0-9-]+(\.[a-z0-9-]+)+)$~', $handle)) {
        $errors['bsky.handle'] = 'Bitte das Handle wie name.bsky.social eingeben.';
    }
    $password = trim((string) ($b['password'] ?? ''));
    if ($password !== '' && !preg_match('~^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$~i', $password)) {
        $errors['bsky.password'] = 'Bitte ein App-Passwort verwenden (Format xxxx-xxxx-xxxx-xxxx), nie das Hauptpasswort.';
    }
    if ($pds !== '!' && ($pds !== $s['bsky']['pds'] || $handle !== $s['bsky']['handle'])) {
        $s['bsky']['checked'] = '';
    }
    if ($pds !== '!') {
        $s['bsky']['pds'] = $pds;
    }
    $s['bsky']['handle'] = $handle;

    // Mastodon
    $m        = is_array($in['masto'] ?? null) ? $in['masto'] : [];
    $instance = social_origin((string) ($m['instance'] ?? ''));
    if ($instance === '!') {
        $errors['masto.instance'] = 'Bitte die Adresse der Instanz eingeben, zum Beispiel https://mastodon.social.';
    } else {
        if ($instance !== $s['masto']['instance']) {
            $s['masto']['checked'] = '';
            $s['masto']['max']       = SOCIAL_MASTO_MAX;
            $s['masto']['url_chars'] = SOCIAL_MASTO_URL;
        }
        $s['masto']['instance'] = $instance;
    }
    $token = trim((string) ($m['token'] ?? ''));
    if ($token !== '' && !preg_match('~^[A-Za-z0-9_-]{20,200}$~', $token)) {
        $errors['masto.token'] = 'Das sieht nicht wie ein Access-Token aus.';
    }
    $visibility        = (string) ($m['visibility'] ?? 'public');
    $s['masto']['visibility'] = in_array($visibility, ['public', 'unlisted', 'private'], true) ? $visibility : 'public';

    // secrets: replace, keep or remove
    if (($password !== '' || $token !== '') && social_key() === null) {
        $errors['secret'] = 'Zum Speichern von App-Passwort und Token fehlt der Schlüssel secret_key in config.php.';
    }
    if ($errors) {
        throw new InputError($errors);
    }
    if (!empty($b['clear'])) {
        $s['bsky']['secret']  = '';
        $s['bsky']['checked'] = '';
    }
    if ($password !== '') {
        $s['bsky']['secret']  = social_seal($password);
        $s['bsky']['checked'] = '';
    }
    if (!empty($m['clear'])) {
        $s['masto']['secret']  = '';
        $s['masto']['checked'] = '';
    }
    if ($token !== '') {
        $s['masto']['secret']  = social_seal($token);
        $s['masto']['checked'] = '';
    }
    $s['bsky']['enabled']  = !empty($b['enabled']);
    $s['masto']['enabled'] = !empty($m['enabled']);
    if ($s['bsky']['enabled'] && ($s['bsky']['handle'] === '' || $s['bsky']['secret'] === '')) {
        throw new InputError(['bsky.enabled' => 'Zum Einschalten fehlen Handle oder App-Passwort.']);
    }
    if ($s['masto']['enabled'] && ($s['masto']['instance'] === '' || $s['masto']['secret'] === '')) {
        throw new InputError(['masto.enabled' => 'Zum Einschalten fehlen Instanz oder Token.']);
    }
    social_store($s);
    return $s;
}

/** clean_text() for text with line breaks. */
function clean_text_multiline(string $s): string
{
    $s = mb_scrub($s, 'UTF-8');
    return (string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{FEFF}]~u', '', $s);
}
