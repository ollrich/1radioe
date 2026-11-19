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
