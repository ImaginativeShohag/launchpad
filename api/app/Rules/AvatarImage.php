<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Looks at the image header only (never decodes) and refuses anything that could exhaust memory
 * when decoded later: too many pixels, animations, or not a JPEG/PNG/WebP at all.
 */
class AvatarImage implements ValidationRule
{
    public const MIN_SIDE = 64;

    public const MAX_SIDE = 4000;

    public const MAX_PIXELS = 8_000_000;

    public function __construct(
        private readonly int $minSide = self::MIN_SIDE,
        private readonly int $maxSide = self::MAX_SIDE,
        private readonly int $maxPixels = self::MAX_PIXELS,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = $value instanceof UploadedFile ? $value->getRealPath() : false;
        $info = $path === false ? false : @getimagesize($path);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $fail('The :attribute must be a JPEG, PNG or WebP image.');

            return;
        }

        [$width, $height] = $info;

        if ($width < $this->minSide || $height < $this->minSide) {
            $fail('The :attribute must be at least '.$this->minSide.' x '.$this->minSide.' pixels.');

            return;
        }

        if ($width > $this->maxSide || $height > $this->maxSide || $width * $height > $this->maxPixels) {
            $fail('The :attribute is too large (at most '.$this->maxSide.' pixels on a side and '.($this->maxPixels / 1_000_000).' megapixels).');

            return;
        }

        if ($this->isAnimated($path, $info[2])) {
            $fail('The :attribute must not be animated.');
        }
    }

    private function isAnimated(string $path, int $type): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return true;
        }

        try {
            return match ($type) {
                IMAGETYPE_WEBP => $this->webpIsAnimated($handle),
                IMAGETYPE_PNG => $this->pngIsAnimated($handle),
                default => false,
            };
        } finally {
            fclose($handle);
        }
    }

    /** @param  resource  $handle */
    private function webpIsAnimated($handle): bool
    {
        $head = (string) fread($handle, 32);

        // Extended format ("VP8X"): bit 1 of the flags byte means animation.
        return substr($head, 12, 4) === 'VP8X' && (ord($head[20] ?? "\0") & 0x02) !== 0;
    }

    /** @param  resource  $handle */
    private function pngIsAnimated($handle): bool
    {
        fseek($handle, 8);

        // Walk the chunks up to the first image data; an APNG has "acTL" before it.
        for ($i = 0; $i < 64; $i++) {
            $header = (string) fread($handle, 8);

            if (strlen($header) < 8) {
                return false;
            }

            $length = (int) (unpack('N', substr($header, 0, 4))[1] ?? 0);
            $type = substr($header, 4, 4);

            if ($type === 'acTL') {
                return true;
            }

            if ($type === 'IDAT' || $type === 'IEND') {
                return false;
            }

            fseek($handle, $length + 4, SEEK_CUR);
        }

        return false;
    }
}
