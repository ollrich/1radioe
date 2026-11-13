<?php
/**
 * Local development only, never used on the server:
 *
 *   php -S 127.0.0.1:8080 -t www app/dev-router.php
 *
 * PHP's built-in server ignores .htaccess. This router mirrors the rewrites of
 * www/.htaccess so the public addresses work locally too. Keep both in sync.
 */
declare(strict_types=1);

const DEV_ROUTES = [
    '~^/api/status$~'                  => '/api/status.php',
    '~^/api/listen$~'                  => '/api/listen.php',
    '~^/api/stations\.json$~'          => '/api/stations.php',
    '~^/streams\.(m3u|pls)$~'          => '/playlist.php?format=$1',
    '~^/s/([A-Za-z0-9._-]+)\.m3u$~'    => '/playlist.php?format=m3u&id=$1',
];

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
foreach (DEV_ROUTES as $pattern => $target) {
    if (preg_match($pattern, $path, $m)) {
        $script = preg_replace_callback('~\$(\d)~', static fn($g) => $m[(int) $g[1]] ?? '', $target);
        [$file, $query] = array_pad(explode('?', $script, 2), 2, '');
        if ($query !== '') {
            parse_str($query, $extra);
            $_GET += $extra;
        }
        $_SERVER['SCRIPT_NAME']     = $file;
        $_SERVER['PHP_SELF']        = $file;
        $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/www' . $file;
        chdir(dirname($_SERVER['SCRIPT_FILENAME']));
        require $_SERVER['SCRIPT_FILENAME'];
        return true;
    }
}
return false;   // everything else: the built-in server serves the file as usual
