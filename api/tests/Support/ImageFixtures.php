<?php

use Illuminate\Http\UploadedFile;

/** A real JPEG with a fake EXIF/GPS block spliced in right after the start-of-image marker. */
function jpegWithExif(int $width = 800, int $height = 600): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40));
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    $exif = "Exif\0\0GPS-LATITUDE-12.3456";
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/** One PNG chunk. */
function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/** A valid 1-bit greyscale PNG of any size; a blank image compresses to a tiny file (a decompression bomb). */
function bombPng(int $width, int $height): string
{
    $row = str_repeat("\0", intdiv($width + 7, 8) + 1);
    $raw = str_repeat($row, $height);

    return "\x89PNG\r\n\x1a\n"
        .pngChunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0))
        .pngChunk('IDAT', (string) gzcompress($raw, 9))
        .pngChunk('IEND', '');
}

/** A small valid PNG, optionally with an animation-control (APNG) chunk before the image data. */
function smallPng(bool $animated = false, int $size = 100): string
{
    $image = imagecreatetruecolor($size, $size);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    if (! $animated) {
        return $png;
    }

    return substr($png, 0, 33).pngChunk('acTL', pack('NN', 2, 0)).substr($png, 33);
}

/** A fake upload holding a real JPEG of the given size. */
function uploadedJpeg(string $name = 'shot.jpg', int $width = 800, int $height = 600): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, jpegWithExif($width, $height));
}
