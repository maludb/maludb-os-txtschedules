<?php
declare(strict_types=1);
/**
 * A PLACEHOLDER mark for txtSchedules until the owner's artwork exists (docs/build-specs/sso-shell.md, open question):
 * a rounded blue square with "tS", and the wordmark. Writes html/assets/images/{logo-full,logo-abbr,favicon,icon-192,
 * icon-512,apple-touch-icon}.png. Run: php bin/make_placeholder_icons.php   (needs php-gd and DejaVu Sans Bold)
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
if (!is_file($font) || !function_exists('imagecreatetruecolor')) { fwrite(STDERR, "php-gd and DejaVu Sans Bold are needed.\n"); exit(1); }
$out = dirname(__DIR__) . '/html/assets/images/';

function square(int $size, string $font): GdImage
{
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    $blue = imagecolorallocate($im, 0x34, 0x54, 0xd1);           // the theme's primary
    $r = (int) ($size * 0.22);
    imagefilledrectangle($im, $r, 0, $size - $r - 1, $size - 1, $blue);
    imagefilledrectangle($im, 0, $r, $size - 1, $size - $r - 1, $blue);
    foreach ([[$r, $r], [$size - $r - 1, $r], [$r, $size - $r - 1], [$size - $r - 1, $size - $r - 1]] as [$x, $y]) {
        imagefilledellipse($im, $x, $y, 2 * $r, 2 * $r, $blue);
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    $pt = $size * 0.40;
    $box = imagettfbbox($pt, 0, $font, 'tS');
    $w = $box[2] - $box[0];
    $h = $box[1] - $box[7];
    imagettftext($im, $pt, 0, (int) (($size - $w) / 2 - $box[0]), (int) (($size + $h) / 2 - $box[1] - $h * 0.06), $white, $font, 'tS');
    return $im;
}
foreach (['logo-abbr' => 128, 'favicon' => 64, 'icon-192' => 192, 'icon-512' => 512, 'apple-touch-icon' => 180] as $name => $size) {
    imagepng(square($size, $font), $out . $name . '.png');
}
$w = imagecreatetruecolor(420, 90);
imagesavealpha($w, true);
imagealphablending($w, false);
imagefill($w, 0, 0, imagecolorallocatealpha($w, 0, 0, 0, 127));
imagealphablending($w, true);
$mark = square(80, $font);
imagecopy($w, $mark, 4, 5, 0, 0, 80, 80);
imagettftext($w, 30, 0, 100, 60, imagecolorallocate($w, 0x28, 0x31, 0x4f), $font, 'txtSchedules');
imagepng($w, $out . 'logo-full.png');
echo "wrote the placeholder mark to html/assets/images/\n";
