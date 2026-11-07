<?php
/**
 * Station logos. Every upload is decoded and re-encoded with GD (removes metadata and
 * anything that is not an image) and stored as a 600 x 600 square in media/.
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
    if ($info[0] * $info[1] > 40_000_000) {
        throw new InputError([], 'Bild hat zu viele Pixel.');
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
    if (!is_dir(MEDIA_DIR) && !@mkdir(MEDIA_DIR, 0755, true)) {
        throw new InputError([], 'Ordner media/ fehlt und kann nicht angelegt werden.');
    }
    if (image_has_alpha($dst)) {
        $file = $name . '.png';
        $ok   = imagepng($dst, MEDIA_DIR . '/' . $file, 9);
    } else {
        $file = $name . '.jpg';
        $ok   = imagejpeg($dst, MEDIA_DIR . '/' . $file, 90);
    }
    if (!$ok) {
        throw new InputError([], 'Bild konnte nicht gespeichert werden. Schreibrechte für media/ prüfen.');
    }
    return $file;
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
