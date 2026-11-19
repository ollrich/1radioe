<?php
/**
 * Tests of 1radiœ: plain PHP, no framework, no Composer. From the checkout:
 *
 *   php tests/run.php
 *
 * They need GD, pdo_mysql and a MariaDB database of their own, which they empty first.
 * Its name must contain "test". Connection from the environment, defaults as in CI:
 * R1_TEST_DB_HOST (127.0.0.1), R1_TEST_DB_PORT (3306), R1_TEST_DB_NAME (r1_test),
 * R1_TEST_DB_USER (r1), R1_TEST_DB_PASS (r1). Config and media folder go into a
 * temporary folder, never into the checkout. No network: the YouTube API and its
 * pictures are stand-ins. GitHub Actions runs them for every pull request and every
 * push to main (.github/workflows/lint.yml).
 *
 * Each tests/*.test.php is one group and uses check(label, got, want).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return $value !== false && $value !== '' ? $value : $default;
};
$db = [
    'host' => $env('R1_TEST_DB_HOST', '127.0.0.1'),
    'port' => (int) $env('R1_TEST_DB_PORT', '3306'),
    'name' => $env('R1_TEST_DB_NAME', 'r1_test'),
    'user' => $env('R1_TEST_DB_USER', 'r1'),
    'pass' => $env('R1_TEST_DB_PASS', 'r1'),
];
if (!str_contains(strtolower($db['name']), 'test')) {
    fwrite(STDERR, "The name of the test database must contain \"test\": the tests empty it first.\n");
    exit(2);
}
foreach (['gd', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "PHP extension $ext is missing.\n");
        exit(2);
    }
}

// a config of its own in a temporary folder; bootstrap.php reads R1_CONFIG on the command line only
$tmp     = sys_get_temp_dir() . '/r1-tests-' . getmypid();
$private = $tmp . '/1radioe-private';
@mkdir($private, 0700, true);
@mkdir($tmp . '/media', 0755, true);
file_put_contents($private . '/config.php', '<?php return ' . var_export([
    'db'         => $db,
    'base_url'   => 'https://radio.test',
    'media_dir'  => $tmp . '/media',
    'media_url'  => 'https://radio.test/media',
    'timezone'   => 'Europe/Berlin',
    'secret_key' => base64_encode(random_bytes(32)),
], true) . ';');
putenv('R1_CONFIG=' . $private . '/config.php');

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/webapp.php';

if (config_path() !== $private . '/config.php') {
    fwrite(STDERR, "The app did not take the test config.\n");
    exit(2);
}
try {
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        db()->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
    }
    db_migrate();
} catch (Throwable $e) {
    fwrite(STDERR, 'Test database not reachable: ' . $e->getMessage() . "\n");
    exit(2);
}

$GLOBALS['r1_test'] = ['group' => '', 'ok' => 0, 'failed' => []];

/** One check: strict comparison, the label says what failed. */
function check(string $label, mixed $got, mixed $want): void
{
    $t = &$GLOBALS['r1_test'];
    if ($got === $want) {
        $t['ok']++;
        return;
    }
    $json = static fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $t['failed'][] = $t['group'] . ': ' . $label . "\n    got  " . $json($got) . "\n    want " . $json($want);
}

/** A JPEG of one colour, as a stand-in for a picture from YouTube. */
function test_jpeg(int $r, int $g, int $b, int $w = 480, int $h = 360): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
    ob_start();
    imagejpeg($img, null, 90);
    return (string) ob_get_clean();
}

$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);
foreach ($files as $file) {
    $GLOBALS['r1_test']['group'] = basename($file, '.test.php');
    $before = count($GLOBALS['r1_test']['failed']);
    $okBefore = $GLOBALS['r1_test']['ok'];
    try {
        (static function () use ($file): void {
            require $file;
        })();
    } catch (Throwable $e) {
        $GLOBALS['r1_test']['failed'][] = $GLOBALS['r1_test']['group'] . ': stopped by ' . get_class($e) . ': '
            . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
    printf("%-10s %3d ok, %d failed\n", $GLOBALS['r1_test']['group'], $GLOBALS['r1_test']['ok'] - $okBefore,
        count($GLOBALS['r1_test']['failed']) - $before);
}

// the temporary folder goes; the database stays for a look after a failure
$clean = static function (string $dir) use (&$clean): void {
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") && !is_link("$dir/$f") ? $clean("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
};
$clean($tmp);

$t = $GLOBALS['r1_test'];
foreach ($t['failed'] as $line) {
    echo "FAIL $line\n";
}
printf("%d ok, %d failed\n", $t['ok'], count($t['failed']));
exit($t['failed'] ? 1 : 0);
