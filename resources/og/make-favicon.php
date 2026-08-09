<?php

/**
 * Assembles public/favicon.ico from public/apple-touch-icon.png.
 *
 * Called by resources/og/render.sh; not part of the application. The icon is a
 * PNG-in-ICO container, which every browser still in support understands, so
 * each entry is simply a downscaled copy of the 180px master.
 */
$sizes = [16, 32, 48];
$root = dirname(__DIR__, 2);
$source = imagecreatefrompng("{$root}/public/apple-touch-icon.png");

if ($source === false) {
    fwrite(STDERR, 'Could not read public/apple-touch-icon.png'.PHP_EOL);

    exit(1);
}

$images = [];

foreach ($sizes as $size) {
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagecopyresampled(
        $canvas, $source,
        0, 0, 0, 0,
        $size, $size,
        imagesx($source), imagesy($source),
    );

    ob_start();
    imagepng($canvas, null, 9);
    $images[$size] = (string) ob_get_clean();

    imagedestroy($canvas);
}

imagedestroy($source);

$offset = 6 + (16 * count($sizes));
$directory = '';

foreach ($images as $size => $data) {
    $directory .= pack(
        'CCCCvvVV',
        $size, $size, 0, 0, 1, 32, strlen($data), $offset,
    );

    $offset += strlen($data);
}

file_put_contents(
    "{$root}/public/favicon.ico",
    pack('vvv', 0, 1, count($sizes)).$directory.implode('', $images),
);
