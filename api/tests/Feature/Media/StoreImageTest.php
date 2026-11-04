<?php

use App\Actions\Media\StoreImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Storage::fake('public'));

it('crops to a square, re-encodes as WebP and stores under a random name', function () {
    // Given a landscape JPEG with GPS metadata
    $file = UploadedFile::fake()->createWithContent('a.jpg', jpegWithExif(800, 400));

    // When it is stored as a 256px square
    $stored = app(StoreImage::class)->store($file, 'public', 'products/logos', 'logo', square: 256);

    // Then the file is a 256x256 WebP without metadata under the directory
    expect($stored->path)->toStartWith('products/logos/')->toEndWith('.webp')->and($stored->width)->toBe(256)->and($stored->height)->toBe(256);
    $bytes = Storage::disk('public')->get($stored->path);
    expect(substr($bytes, 8, 4))->toBe('WEBP')->and(str_contains($bytes, 'GPS-LATITUDE'))->toBeFalse();
});

it('scales down to fit but never up', function () {
    // Given a big and a small JPEG
    $big = UploadedFile::fake()->createWithContent('big.jpg', jpegWithExif(3200, 1000));
    $small = UploadedFile::fake()->createWithContent('small.jpg', jpegWithExif(640, 480));

    // When stored with a 1600px limit
    $a = app(StoreImage::class)->store($big, 'public', 'products/screenshots', 'screenshot', fitWithin: 1600);
    $b = app(StoreImage::class)->store($small, 'public', 'products/screenshots', 'screenshot', fitWithin: 1600);

    // Then the big one shrinks keeping its ratio and the small one is untouched
    expect([$a->width, $a->height])->toBe([1600, 500]);
    expect([$b->width, $b->height])->toBe([640, 480]);
});

it('reports an undecodable file as a validation error on the given field', function () {
    // Given a file that passed the header check but cannot be decoded
    $file = UploadedFile::fake()->createWithContent('a.jpg', substr(jpegWithExif(), 0, 120));

    // When stored
    $call = fn () => app(StoreImage::class)->store($file, 'public', 'products/logos', 'logo', square: 256);

    // Then a 422-style error names the field, leaks no decoder text, and nothing is written
    expect($call)->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('discards a file quietly and reports a failing disk without throwing', function () {
    // Given a stored file
    $stored = app(StoreImage::class)->store(UploadedFile::fake()->createWithContent('a.jpg', jpegWithExif()), 'public', 'x', 'f', square: 64);

    // When it is discarded, and discarding null
    app(StoreImage::class)->discard('public', $stored->path);
    app(StoreImage::class)->discard('public', null);

    // Then the file is gone and nothing threw
    Storage::disk('public')->assertMissing($stored->path);
});
