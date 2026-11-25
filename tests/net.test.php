<?php
/** Addresses in playlists and redirects (net_absolute, RFC 3986), HTTPS detection. */
declare(strict_types=1);

$cases = [
    // base, reference, result
    ['https://example.org/live.pls', '?token=neu', 'https://example.org/live.pls?token=neu'],
    ['https://example.org/live.pls?old=1', '?token=neu', 'https://example.org/live.pls?token=neu'],
    ['https://example.org/live/', 'a.mp3', 'https://example.org/live/a.mp3'],
    ['https://example.org/live/x.pls', 'a.mp3', 'https://example.org/live/a.mp3'],
    ['https://example.org/a/b/c.pls', '../d.mp3', 'https://example.org/a/d.mp3'],
    ['https://example.org/a/b/c.pls', './d.mp3', 'https://example.org/a/b/d.mp3'],
    ['https://example.org/a/b/c.pls', '../../../d.mp3', 'https://example.org/d.mp3'],
    ['https://example.org/a/b/c.pls', '..', 'https://example.org/a/'],
    ['https://example.org/a/b/c.pls', '/x/./y/../z?q=1', 'https://example.org/x/z?q=1'],
    ['https://example.org:8443/a/b', 'c', 'https://example.org:8443/a/c'],
    ['https://example.org', 'c', 'https://example.org/c'],
    ['https://example.org', '?q', 'https://example.org/?q'],
    ['https://example.org/a?x=1', '', 'https://example.org/a?x=1'],
    ['https://example.org/a?x=1', '#frag', 'https://example.org/a?x=1'],
    ['https://example.org/a', 'b#frag', 'https://example.org/b'],
    ['https://example.org/a', '//cdn.example.net/s.mp3', 'https://cdn.example.net/s.mp3'],
    ['http://example.org/a', 'HTTPS://other.example/s', 'HTTPS://other.example/s'],
    ['https://example.org/a', 'ftp://x/y', 'ftp://x/y'],   // http_get() refuses it afterwards
    ['https://[2001:db8::1]/a/b', 'c', 'https://[2001:db8::1]/a/c'],
];
foreach ($cases as [$base, $ref, $want]) {
    check("net_absolute($base, $ref)", net_absolute($base, $ref), $want);
}

// HTTPS only as the web server sees it, never from a header anyone can send
$keep = $_SERVER;
foreach ([[[], false], [['HTTPS' => 'on'], true], [['HTTPS' => 'off'], false], [['HTTP_X_FORWARDED_PROTO' => 'https'], false]] as [$server, $want]) {
    $_SERVER = $keep;
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    $_SERVER = $server + $_SERVER;
    check('is_https ' . json_encode($server), is_https(), $want);
}
$_SERVER = $keep;

// internal addresses are refused on the server (allow_private_urls is off in the tests)
foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.0.1', '169.254.169.254', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:192.168.0.1', '100.64.0.1', '100.127.255.254'] as $ip) {
    check("internal address refused: $ip", net_resolve($ip)[0] ?? '', '!');
}
foreach (['8.8.8.8', '100.63.255.255', '100.128.0.1', '2001:4860:4860::8888'] as $ip) {
    check("public address allowed: $ip", net_resolve($ip), $ip);
}
check('shared range start', net_shared_range('100.64.0.0'), true);
check('shared range end', net_shared_range('100.127.255.255'), true);
check('shared range outside', [net_shared_range('100.63.255.255'), net_shared_range('100.128.0.0'), net_shared_range('::1')], [false, false, false]);
