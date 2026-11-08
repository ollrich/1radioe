<?php
/**
 * Station logos. Every upload is decoded and re-encoded with GD (removes metadata and
 * anything that is not an image) and stored as a 600 x 600 square in 1radioe-media/.
 * Logos with transparency are saved as PNG, all others as JPEG.
 */
declare(strict_types=1);

const LOGO_SIZE      = 600;
const LOGO_MAX_BYTES = 8 * 1024 * 1024;

/** Stores an image (binary string) as station logo file. Returns the file name. */
function logo_store(string $data): string
{
    if (!function_exists('imagecreatefromstring')) {
        throw new InputError([], 'PHP-Erweiterung GD fehlt auf dem Server.');
    }
    if (strlen($data) > LOGO_MAX_BYTES) {
        throw new InputError([], 'Bild ist größer als 8 MB.');
    }
    $info = @getimagesizefromstring($data);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        throw new InputError([], 'Bitte PNG, JPG, WebP oder GIF verwenden.');
    }
    if ($info[0] * $info[1] > 40_000_000 || !image_memory_ok($info[0], $info[1])) {
        throw new InputError([], 'Bild hat zu viele Pixel für den Server, bitte verkleinern.');
    }
    $src = @imagecreatefromstring($data);
    if ($src === false) {
        throw new InputError([], 'Bild konnte nicht gelesen werden.');
    }

    // Center crop to a square (the editor usually delivers a square already), then scale.
    $w    = imagesx($src);
    $h    = imagesy($src);
    $side = min($w, $h);
    $dst  = imagecreatetruecolor(LOGO_SIZE, LOGO_SIZE);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), LOGO_SIZE, LOGO_SIZE, $side, $side);

    $name = bin2hex(random_bytes(8));
    if (!is_dir(media_dir()) && !@mkdir(media_dir(), 0755, true)) {
        throw new InputError([], 'Ordner 1radioe-media fehlt und kann nicht angelegt werden.');
    }
    if (image_has_alpha($dst)) {
        $file = $name . '.png';
        $ok   = imagepng($dst, media_dir() . '/' . $file, 9);
    } else {
        $file = $name . '.jpg';
        $ok   = imagejpeg($dst, media_dir() . '/' . $file, 90);
    }
    if (!$ok) {
        throw new InputError([], 'Bild konnte nicht gespeichert werden. Schreibrechte für 1radioe-media prüfen.');
    }
    return $file;
}

/**
 * GD needs about 5 bytes per pixel. A fatal "memory exhausted" cannot be caught, so check
 * first and raise the limit if the host allows it.
 */
function image_memory_ok(int $w, int $h): bool
{
    $need = $w * $h * 5 + LOGO_SIZE * LOGO_SIZE * 5 + 8 * 1024 * 1024 + memory_get_usage();
    if ($need <= ini_bytes('memory_limit')) {
        return true;
    }
    @ini_set('memory_limit', (string) (int) ceil($need / 1048576 + 16) . 'M');
    return $need <= ini_bytes('memory_limit');
}

/** True if any sampled pixel is (partly) transparent. */
function image_has_alpha(GdImage $img): bool
{
    for ($y = 0; $y < LOGO_SIZE; $y += 3) {
        for ($x = 0; $x < LOGO_SIZE; $x += 3) {
            if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Cover for a category without its own picture: up to four logos in a 2 x 2 grid,
 * stored once as cat<id>-<hash>.jpg (a new set of logos gives a new file).
 * One logo is used as it is. Returns the file name.
 */
function category_collage(int $id, array $logos): string
{
    if (count($logos) === 1 || !function_exists('imagecreatetruecolor')) {
        return $logos[0];
    }
    $name = 'cat' . $id . '-' . substr(sha1(implode(',', $logos)), 0, 12) . '.jpg';
    if (is_file(media_dir() . '/' . $name)) {
        return $name;
    }
    $cells = match (count($logos)) {
        2       => [0, 1, 1, 0],
        3       => [0, 1, 2, 0],
        default => [0, 1, 2, 3],
    };
    $half = intdiv(LOGO_SIZE, 2);
    $dst  = imagecreatetruecolor(LOGO_SIZE, LOGO_SIZE);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 11, 19, 40));   // navy behind transparent logos
    $images = [];
    foreach ($cells as $n => $i) {
        $images[$i] ??= @imagecreatefromstring((string) @file_get_contents(media_dir() . '/' . $logos[$i]));
        if ($images[$i] !== false) {
            imagecopyresampled($dst, $images[$i], ($n % 2) * $half, intdiv($n, 2) * $half, 0, 0, $half, $half, imagesx($images[$i]), imagesy($images[$i]));
        }
    }
    foreach (glob(media_dir() . '/cat' . $id . '-*.jpg') ?: [] as $old) {
        @unlink($old);   // grid of an earlier set of logos
    }
    return imagejpeg($dst, media_dir() . '/' . $name, 88) ? $name : $logos[0];
}

/** Downloads an image from the web for the editor. Returns a data: URL. */
function logo_fetch_remote(string $url): string
{
    $res = http_get($url, LOGO_MAX_BYTES + 1, 10);
    if (!$res['ok']) {
        throw new InputError([], 'Bild nicht abrufbar: ' . $res['error']);
    }
    if (strlen($res['body']) > LOGO_MAX_BYTES) {
        throw new InputError([], 'Bild ist größer als 8 MB.');
    }
    $info = @getimagesizefromstring($res['body']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        throw new InputError([], 'Unter der Adresse liegt kein PNG, JPG, WebP oder GIF.');
    }
    return 'data:' . $info['mime'] . ';base64,' . base64_encode($res['body']);
}
