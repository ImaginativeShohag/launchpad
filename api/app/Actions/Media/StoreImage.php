<?php

namespace App\Actions\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Laravel\Facades\Image;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The one image pipeline (avatars, logos, screenshots): decode, crop or scale, re-encode as WebP (GD drops all
 * metadata, EXIF and GPS included) and store under a random name. The request has already checked the header
 * (type, size, pixels, animation) without decoding; this class never trusts the client's file name or type.
 *
 * It does not touch the database. Callers update their rows first and call {@see self::discard()} for the
 * file that is no longer referenced, so a failed update never loses the old file (L12).
 */
final class StoreImage
{
    /**
     * @param  string  $field  the request field named in the validation error
     * @param  int|null  $square  crop to a square of this size (logos, avatars)
     * @param  int|null  $fitWithin  scale down to fit inside this box, never up (screenshots)
     *
     * @throws ValidationException when the file cannot be decoded
     */
    public function store(UploadedFile $file, string $diskName, string $directory, string $field, ?int $square = null, ?int $fitWithin = null): StoredImage
    {
        if ($square === null && $fitWithin === null) {
            throw new InvalidArgumentException('Give a square size or a fit-within size.');
        }

        try {
            $image = Image::decode($file);
            $image = $square !== null
                ? $image->cover($square, $square)
                : $image->scaleDown(width: $fitWithin, height: $fitWithin);
            $bytes = $image->encodeUsingFileExtension('webp', quality: 85)->toString();
            $info = getimagesizefromstring($bytes);

            if ($info === false) {
                throw new RuntimeException('Encoded image is unreadable.');
            }
        } catch (Throwable) {
            // Corrupt or hostile file: say so without leaking decoder messages.
            throw ValidationException::withMessages([$field => ['The image could not be processed.']]);
        }

        $path = trim($directory, '/').'/'.Str::lower((string) Str::ulid()).'.webp';

        if (! Storage::disk($diskName)->put($path, $bytes)) {
            throw new RuntimeException('Could not write the image file.');
        }

        return new StoredImage($path, $info[0], $info[1]);
    }

    /** Deletes a file that is no longer referenced. A failure is reported, never thrown: the data change is done. */
    public function discard(string $diskName, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            Storage::disk($diskName)->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
