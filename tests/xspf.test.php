<?php
/**
 * /streams.xspf for Eter: valid XML in Eter's own form (title, location, image), the same
 * stations in the same order as streams.m3u, nothing but active streams (#82).
 */
declare(strict_types=1);

$logo = static fn(?string $f): ?string => $f ? 'https://radio.test/media/' . $f : null;

// an empty list is still a valid playlist
$empty = simplexml_load_string(xspf_playlist([], $logo));
check('empty: valid XML', $empty !== false, true);
check('empty: no tracks', $empty !== false ? count($empty->trackList->track) : -1, 0);

// a name with everything XML does not like, a logo, a SoundCloud entry beside it
$odd = station_save(['kind' => 'stream', 'title' => "Rock & Roll <Live> \"Ö\"\x01 ' 1", 'stream_url' => 'https://odd.example/live.mp3?a=1&b=2', 'mime' => 'audio/mpeg', 'active' => 1]);
q('UPDATE r1_stations SET logo = ? WHERE id = ?', ['odd.jpg', (int) $odd['id']]);
$sc = station_save(['kind' => 'soundcloud', 'title' => 'Ein Mix', 'stream_url' => 'https://soundcloud.com/someone/a-mix', 'active' => 1]);

$stations = public_catalog()['stations'];
$body     = xspf_playlist($stations, $logo);
$doc      = simplexml_load_string($body);
check('valid XML', $doc !== false, true);
check('XSPF namespace and version', [$doc->getNamespaces()[''] ?? null, (string) $doc['version']], ['http://xspf.org/ns/0/', '1']);

$titles = [];
$urls   = [];
foreach ($doc->trackList->track as $t) {
    $titles[] = (string) $t->title;
    $urls[]   = (string) $t->location;
}
check('same order as streams.m3u', $titles, array_map(static fn($e) => playlist_title($e['row']['title']), $stations));
check('stream addresses as played', $urls, array_map(static fn($e) => station_playback_url($e['row']), $stations));
check('special characters survive, control character gone', in_array("Rock & Roll <Live> \"Ö\" ' 1", $titles, true), true);
check('query of a stream address intact', in_array('https://odd.example/live.mp3?a=1&b=2', $urls, true), true);
check('no SoundCloud', in_array('Ein Mix', $titles, true), false);
check('no inactive station', in_array('Delta', $titles, true), false);
check('no YouTube', (bool) array_filter($titles, static fn($t) => str_contains($t, 'Solomun') || str_contains($t, 'Boiler Room')), false);

// the logo only where there is one, as an absolute address
$images = [];
foreach ($doc->trackList->track as $t) {
    $images[(string) $t->title] = isset($t->image) ? (string) $t->image : null;
}
check('logo as image', $images["Rock & Roll <Live> \"Ö\" ' 1"] ?? null, 'https://radio.test/media/odd.jpg');
check('no image without a logo', array_key_exists('Gamma', $images) ? $images['Gamma'] : 'missing', null);

// Eter's form: one element per line, the three fields indented
check('Eter form', str_contains($body, "<track>\n    <title>") && str_contains($body, "</location>\n    <image>"), true);

station_delete((int) $odd['id']);
station_delete((int) $sc['id']);
