<?php
/**
 * Outgoing HTTP: playlist downloads, stream tests and logo downloads.
 *
 * Only public http(s) addresses are allowed (no localhost or private networks),
 * the checked IP is pinned for the connection and every redirect is checked again.
 * For local testing, set 'allow_private_urls' => true in config.php.
 */
declare(strict_types=1);

/** Returns the IP to connect to, or an error message string prefixed with "!". */
function net_resolve(string $host): string
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                $ips[] = $r['ipv6'];
            }
        }
    }
    if (!$ips) {
        return '!Host nicht gefunden: ' . $host;
    }
    if (!cfg('allow_private_urls', false)) {
        foreach ($ips as $ip) {
            // an IPv4 address inside IPv6 (::ffff:10.0.0.1) is checked as IPv4
            $plain = preg_match('~^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$~i', $ip, $m) ? $m[1] : $ip;
            if (!filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return '!Interne Adressen sind nicht erlaubt.';
            }
        }
    }
    return $ips[0];
}

/** Resolves a (possibly relative) redirect target against the current URL. */
function net_absolute(string $base, string $location): string
{
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }
    $p      = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($location, '//')) {
        return $p['scheme'] . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $dir = rtrim(str_replace('\\', '/', dirname($p['path'] ?? '/')), '/');
    return $origin . $dir . '/' . $location;
}

/**
 * GET request that reads at most $maxBytes (enough for headers and the start of a stream).
 * Returns ['ok', 'status', 'headers' (lowercase keys), 'body', 'url' (final), 'error'].
 */
function http_get(string $url, int $maxBytes, int $timeout, int $redirects = 3): array
{
    $fail = static fn(string $error): array => ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'url' => $url, 'error' => $error];
    if (!function_exists('curl_init')) {
        return $fail('PHP-Erweiterung cURL fehlt.');
    }
    for ($hop = 0; $hop <= $redirects; $hop++) {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return $fail('Ungültige Adresse.');
        }
        $ip = net_resolve($p['host']);
        if ($ip[0] === '!') {
            return $fail(substr($ip, 1));
        }
        $port    = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
        $status  = 0;
        $headers = [];
        $body    = '';
        $ch      = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => '1radioe/' . APP_VERSION,
            CURLOPT_HTTPHEADER     => ['Accept: */*', 'Icy-MetaData: 0'],
            // connect to the checked address only (no second DNS lookup that could differ)
            CURLOPT_RESOLVE        => filter_var(trim($p['host'], '[]'), FILTER_VALIDATE_IP) ? []
                : [$p['host'] . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status, &$headers): int {
                $len  = strlen($line);
                $line = trim($line);
                if (preg_match('~^(?:HTTP/\S+|ICY)\s+(\d{3})~i', $line, $m)) {
                    $status  = (int) $m[1];
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                return strlen($body) >= $maxBytes ? -1 : strlen($chunk);   // -1 stops after enough data
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        // 23 = we stopped reading on purpose; 28 = timeout, fine for endless streams that sent data
        $enough = $errno === 0 || $errno === 23 || ($errno === 28 && strlen($body) >= 1024);
        if (!$enough) {
            return $fail($errno === 28 ? 'Zeitüberschreitung, der Server antwortet nicht.' : 'Verbindung fehlgeschlagen: ' . $error);
        }
        if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
            $url = net_absolute($url, $headers['location']);
            continue;
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'headers' => $headers, 'body' => $body, 'url' => $url, 'error' => $ok ? '' : 'HTTP-Status ' . $status];
    }
    return $fail('Zu viele Weiterleitungen.');
}

/** First stream address of a PLS (FileN=...) or M3U playlist, or null. */
function playlist_first_url(string $text): ?string
{
    $text = (string) preg_replace('~^\xEF\xBB\xBF~', '', $text);
    foreach (preg_split('~\R~', $text) ?: [] as $line) {
        if (preg_match('~^\s*(?:File\d+\s*=\s*)?(https?://\S+)~i', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * Tests a stream address: reachable? which format? Follows playlists once.
 * Returns ['ok', 'mime', 'is_playlist', 'info', 'name', 'description', 'resolved'].
 */
function probe_stream(string $url, int $depth = 0): array
{
    $result = ['ok' => false, 'mime' => null, 'is_playlist' => false, 'info' => '', 'name' => '', 'description' => '', 'resolved' => null];
    $res    = http_get($url, 32768, 8);
    if (!$res['ok']) {
        $result['info'] = $res['error'];
        return $result;
    }
    $body  = $res['body'];
    $ctype = strtolower(trim(explode(';', $res['headers']['content-type'] ?? '')[0]));
    $head  = ltrim(substr($body, 0, 512), "\xEF\xBB\xBF \t\r\n");
    $result['name']        = trim($res['headers']['icy-name'] ?? '');
    $result['description'] = trim($res['headers']['icy-description'] ?? '');

    // HLS
    if (str_starts_with($head, '#EXTM3U') && str_contains($body, '#EXT-X-')) {
        return ['ok' => true, 'mime' => 'application/vnd.apple.mpegURL', 'info' => 'HLS-Stream'] + $result;
    }
    // PLS or M3U playlist: test the first stream inside
    $isPls = str_starts_with(strtolower($head), '[playlist]') || $ctype === 'audio/x-scpls';
    $isM3u = str_starts_with($head, '#EXTM3U') || in_array($ctype, ['audio/x-mpegurl', 'audio/mpegurl', 'application/x-mpegurl'], true)
        || (looks_like_playlist($url) && preg_match('~^https?://~mi', $body));
    if ($isPls || $isM3u) {
        $inner = playlist_first_url($body);
        if ($inner === null || $depth > 0) {
            $result['info'] = 'Playlist ohne gültige Stream-Adresse.';
            return $result;
        }
        $sub = probe_stream($inner, $depth + 1);
        $sub['is_playlist'] = true;
        $sub['resolved']    = $inner;
        $sub['info']        = 'Playlist, erster Stream: ' . $sub['info'];
        return $sub;
    }

    $bitrate = isset($res['headers']['icy-br']) ? ', ' . (int) $res['headers']['icy-br'] . ' kbit/s' : '';
    $name    = $result['name'] !== '' ? ', „' . $result['name'] . '“' : '';

    // Ogg: Vorbis is supported, Opus and FLAC in Ogg are not
    if (str_starts_with($body, 'OggS')) {
        if (str_contains($body, 'OpusHead')) {
            $result['info'] = 'Ogg Opus wird von Sonos nicht unterstützt. Der Sender bietet vielleicht MP3 oder AAC an.';
            return $result;
        }
        if (str_contains($body, "\x01vorbis")) {
            return ['ok' => true, 'mime' => 'application/ogg', 'info' => 'Ogg Vorbis' . $bitrate . $name] + $result;
        }
        $result['info'] = 'Unbekanntes Ogg-Format, Sonos unterstützt nur Ogg Vorbis.';
        return $result;
    }
    if (str_starts_with($body, 'fLaC') || $ctype === 'audio/flac') {
        return ['ok' => true, 'mime' => 'audio/flac', 'info' => 'FLAC' . $bitrate . $name] + $result;
    }

    // MP3 or AAC: check the frame header, use the Content-Type as a hint
    $frame = audio_frame_type($body);
    if ($frame === 'aac' || ($frame === null && in_array($ctype, ['audio/aac', 'audio/aacp', 'audio/x-aac', 'audio/mp4a-latm'], true))) {
        return ['ok' => true, 'mime' => 'audio/aac', 'info' => 'AAC' . $bitrate . $name] + $result;
    }
    if ($frame === 'mp3' || ($frame === null && in_array($ctype, ['audio/mpeg', 'audio/mp3', 'audio/mpeg3'], true))) {
        return ['ok' => true, 'mime' => 'audio/mpeg', 'info' => 'MP3' . $bitrate . $name] + $result;
    }
    $result['info'] = 'Format nicht erkannt' . ($ctype !== '' ? ' (Content-Type: ' . $ctype . ')' : '') . '.';
    return $result;
}

/** Looks for MPEG audio frame headers: 'aac' (ADTS), 'mp3', or null. */
function audio_frame_type(string $data): ?string
{
    if (str_starts_with($data, 'ID3') && strlen($data) > 10) {
        $size = (ord($data[6]) << 21) | (ord($data[7]) << 14) | (ord($data[8]) << 7) | ord($data[9]);
        $data = substr($data, 10 + $size);
    }
    $len = min(strlen($data) - 4, 16384);
    for ($i = 0; $i < $len; $i++) {
        if ($data[$i] !== "\xFF") {
            continue;
        }
        $b1 = ord($data[$i + 1]);
        if (($b1 & 0xF6) === 0xF0) {
            // ADTS: confirm with the frame length pointing at the next sync word
            $flen = ((ord($data[$i + 3]) & 0x03) << 11) | (ord($data[$i + 4]) << 3) | (ord($data[$i + 5] ?? "\0") >> 5);
            if ($flen > 7 && isset($data[$i + $flen + 1]) && $data[$i + $flen] === "\xFF" && (ord($data[$i + $flen + 1]) & 0xF6) === 0xF0) {
                return 'aac';
            }
        } elseif (($b1 & 0xE0) === 0xE0 && ($b1 & 0x06) !== 0) {
            // MPEG audio layer I to III: confirm with a second frame
            $b2      = ord($data[$i + 2]);
            $version = ($b1 >> 3) & 0x03;   // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
            $layer   = ($b1 >> 1) & 0x03;   // 1 = layer III
            $brIndex = $b2 >> 4;
            $srIndex = ($b2 >> 2) & 0x03;
            if ($layer !== 1 || $version === 1 || $brIndex === 0 || $brIndex === 15 || $srIndex === 3) {
                continue;
            }
            $rates   = $version === 3 ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320] : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
            $samples = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]][$version][$srIndex];
            $flen    = intdiv(($version === 3 ? 144000 : 72000) * $rates[$brIndex], $samples) + (($b2 >> 1) & 1);
            if (isset($data[$i + $flen + 1]) && $data[$i + $flen] === "\xFF" && (ord($data[$i + $flen + 1]) & 0xE0) === 0xE0) {
                return 'mp3';
            }
        }
    }
    return null;
}
