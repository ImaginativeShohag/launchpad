<?php

use App\Actions\Account\RemoveAvatar;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('public'));

it('re-encodes an uploaded photo as a 512px WebP without metadata', function () {
    // Given a verified member and a JPEG carrying GPS metadata
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $upload = UploadedFile::fake()->createWithContent('me.jpg', jpegWithExif());

    // When they upload it as their avatar
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => $upload]);

    // Then a WebP of 512x512 is stored under a random name, with no EXIF or GPS bytes
    $response->assertOk();
    $path = $user->fresh()->avatar_path;
    expect($path)->toStartWith('avatars/')->toEndWith('.webp');
    expect(basename($path, '.webp'))->toMatch('/^[0-9a-z]{26}$/');
    $bytes = Storage::disk('public')->get($path);
    expect(substr($bytes, 0, 4))->toBe('RIFF')->and(substr($bytes, 8, 4))->toBe('WEBP');
    expect(getimagesizefromstring($bytes)[0])->toBe(512)->and(getimagesizefromstring($bytes)[1])->toBe(512);
    expect(str_contains($bytes, 'GPS-LATITUDE'))->toBeFalse();
    expect(str_contains($bytes, 'Exif'))->toBeFalse();
    expect($response->json('data.avatar_url'))->toContain($path);
});

it('accepts PNG and WebP uploads', function (string $name, string $bytes) {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they upload the file
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent($name, $bytes)]);

    // Then it is stored as WebP
    $response->assertOk();
    expect($user->fresh()->avatar_path)->toEndWith('.webp');
})->with(function () {
    $webp = imagecreatetruecolor(200, 200);
    ob_start();
    imagewebp($webp);

    return ['png' => ['a.png', smallPng()], 'webp' => ['a.webp', (string) ob_get_clean()]];
});

it('replaces the old file when a new avatar is uploaded and removes it on delete', function () {
    // Given a member with an avatar
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('a.jpg', jpegWithExif())])->assertOk();
    $first = $user->fresh()->avatar_path;

    // When they upload another
    $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('b.jpg', jpegWithExif())])->assertOk();
    $second = $user->fresh()->avatar_path;

    // Then the old file is gone
    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    // When they delete the avatar
    $this->deleteJson('/api/v1/me/avatar')->assertNoContent();

    // Then the file and the column are cleared
    Storage::disk('public')->assertMissing($second);
    expect($user->fresh()->avatar_path)->toBeNull();
});

it('deleting a missing avatar is harmless', function () {
    // Given a member without an avatar
    Sanctum::actingAs(User::factory()->create());

    // When they delete it anyway
    // Then it is a 204
    $this->deleteJson('/api/v1/me/avatar')->assertNoContent();
});

it('rejects files that are not small images', function () {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When they upload a text file renamed .jpg, and a file over 2 MB
    $fake = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image')]);
    $big = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('big.jpg', 600, 600)->size(3000)]);

    // Then both are refused
    $fake->assertUnprocessable()->assertJsonValidationErrors('avatar');
    $big->assertUnprocessable()->assertJsonValidationErrors('avatar');
});

it('rejects unsupported, animated, corrupt and polyglot files with 422', function (string $name, string $bytes) {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they upload the file
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent($name, $bytes)]);

    // Then it is a validation error that leaks no internals, and nothing is stored
    $response->assertUnprocessable()->assertJsonValidationErrors('avatar');
    expect($response->getContent())->not->toContain('Intervention')->not->toContain('/var/www');
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect($user->fresh()->avatar_path)->toBeNull();
})->with(function () {
    $jpeg = jpegWithExif();
    $webpAnim = 'RIFF'.pack('V', 22).'WEBPVP8X'.pack('V', 10).chr(0x02)."\0\0\0".pack('V', 0xFF).'ANIM'."\0\0\0\0";

    return [
        'svg' => ['a.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>'],
        'svg as png' => ['a.png', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>'],
        'gif' => ['a.gif', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"],
        'gif renamed jpg' => ['a.jpg', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff;"],
        'php polyglot' => ['a.jpg', "<?php system('id'); ?>"],
        'jpeg header then garbage' => ['a.jpg', substr($jpeg, 0, 300).random_bytes(500)],
        'png signature only' => ['a.png', "\x89PNG\r\n\x1a\n"],
        'empty file' => ['a.jpg', ''],
        'animated png' => ['a.png', smallPng(animated: true)],
        'animated webp' => ['a.webp', $webpAnim],
        'too small' => ['a.png', smallPng(size: 63)],
        'too wide' => ['a.jpg', jpegWithExif(4001, 64)],
        'too tall' => ['a.jpg', jpegWithExif(64, 4001)],
        'too many pixels' => ['a.jpg', jpegWithExif(3500, 2500)],
    ];
});

it('stores a truncated JPEG as a clean 512px WebP (GD decodes what is there)', function (float $fraction) {
    // Given a JPEG cut off part-way
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $jpeg = jpegWithExif();

    // When it is uploaded
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('a.jpg', substr($jpeg, 0, (int) (strlen($jpeg) * $fraction)))]);

    // Then it is stored as a valid 512x512 WebP
    $response->assertOk();
    $info = getimagesizefromstring(Storage::disk('public')->get($user->fresh()->avatar_path));
    expect($info[0])->toBe(512)->and($info[1])->toBe(512)->and($info[2])->toBe(IMAGETYPE_WEBP);
})->with(['30%' => 0.30, '70%' => 0.70, '95%' => 0.95]);

it('applies EXIF orientation: a left-red, right-blue photo rotated by 6 ends up red on top', function () {
    // Given a landscape JPEG, red on the left and blue on the right, with EXIF orientation 6 (rotate 90 clockwise)
    $image = imagecreatetruecolor(200, 100);
    imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 0, 0, 255));
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();
    $tiff = "MM\x00\x2a".pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', 6)."\x00\x00".pack('N', 0);
    $exif = "Exif\0\0".$tiff;
    $upload = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When it is uploaded
    $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('o.jpg', $upload)])->assertOk();

    // Then the stored image has red at the top and blue at the bottom
    $stored = imagecreatefromstring(Storage::disk('public')->get($user->fresh()->avatar_path));
    $top = imagecolorsforindex($stored, imagecolorat($stored, 256, 20));
    $bottom = imagecolorsforindex($stored, imagecolorat($stored, 256, 490));
    expect($top['red'])->toBeGreaterThan(200)->and($top['blue'])->toBeLessThan(80);
    expect($bottom['blue'])->toBeGreaterThan(200)->and($bottom['red'])->toBeLessThan(80);
});

it('handles a CMYK JPEG without a server error', function () {
    // Given a CMYK JPEG (fixture made with Pillow)
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $bytes = (string) file_get_contents(__DIR__.'/../../Fixtures/cmyk.jpg');

    // When it is uploaded
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('c.jpg', $bytes)]);

    // Then it is stored as a valid 512x512 WebP
    $response->assertOk();
    $info = getimagesizefromstring(Storage::disk('public')->get($user->fresh()->avatar_path));
    expect($info[0])->toBe(512)->and($info[1])->toBe(512)->and($info[2])->toBe(IMAGETYPE_WEBP);
});

it('removes the avatar the database names, even when the loaded model is stale', function () {
    // Given a model loaded without an avatar, and a concurrent upload that committed afterwards
    $user = User::factory()->create();
    $stale = User::query()->findOrFail($user->id);
    Storage::disk('public')->put('avatars/new.webp', 'x');
    DB::table('users')->where('id', $user->id)->update(['avatar_path' => 'avatars/new.webp']);

    // When the avatar is removed through the stale model
    app(RemoveAvatar::class)->handle($stale);

    // Then the database ends null and the file the database named is deleted
    expect(DB::table('users')->where('id', $user->id)->value('avatar_path'))->toBeNull();
    Storage::disk('public')->assertMissing('avatars/new.webp');
    expect($stale->avatar_path)->toBeNull();
});

it('reports, rather than masks, a failure while cleaning up the new file', function () {
    // Given a database that rejects the update and a disk that fails to delete
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Storage::shouldReceive('disk')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('put')->andReturn(true);
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk gone'));
    User::saving(fn () => throw new RuntimeException('db down'));

    // When they upload
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('a.jpg', jpegWithExif())]);

    // Then the original error is the one that surfaces
    $response->assertStatus(500);
    User::flushEventListeners();
});

it('rejects a decompression bomb from its header without decoding it', function () {
    // Given a 20000x20000 PNG that is only a few dozen KB on disk
    $bomb = bombPng(20000, 20000);
    expect(strlen($bomb))->toBeLessThan(2 * 1024 * 1024);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When it is uploaded
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('bomb.png', $bomb)]);

    // Then it is refused as too large, and nothing is stored
    $response->assertUnprocessable()->assertJsonValidationErrors('avatar');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects array and missing avatar fields', function (array $payload) {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When the avatar field is missing or not a single file
    $response = $this->postJson('/api/v1/me/avatar', $payload);

    // Then it is a validation error
    $response->assertUnprocessable()->assertJsonValidationErrors('avatar');
})->with([
    'missing' => [[]],
    'null' => [['avatar' => null]],
    'string' => [['avatar' => 'nope']],
    'array' => [['avatar' => ['a']]],
    'object' => [['avatar' => ['a' => 'b']]],
]);

it('keeps the old avatar and leaves no new file when the database update fails', function () {
    // Given a member with an avatar and a database that rejects the update
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('a.jpg', jpegWithExif())])->assertOk();
    $old = $user->fresh()->avatar_path;
    User::saving(fn () => throw new RuntimeException('db down'));

    // When they upload another
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('b.jpg', jpegWithExif())]);

    // Then the request fails, the old file stays, and no orphan was left behind
    $response->assertStatus(500);
    expect(Storage::disk('public')->allFiles())->toBe([$old]);
    User::flushEventListeners();
    expect($user->fresh()->avatar_path)->toBe($old);
});

it('blocks unconfirmed members from uploading', function () {
    // Given an unconfirmed member
    Sanctum::actingAs(User::factory()->unverified()->create());

    // When they upload
    $response = $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('a.jpg', jpegWithExif())]);

    // Then they are refused
    $response->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('requires sign-in and an active account for avatar changes', function () {
    // Given no one is signed in
    $upload = UploadedFile::fake()->createWithContent('a.jpg', jpegWithExif());

    // When they try to upload or delete
    // Then they get 401
    $this->postJson('/api/v1/me/avatar', ['avatar' => $upload])->assertUnauthorized();
    $this->deleteJson('/api/v1/me/avatar')->assertUnauthorized();

    // And a suspended member is blocked
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->postJson('/api/v1/me/avatar', ['avatar' => $upload])->assertForbidden();
    $this->deleteJson('/api/v1/me/avatar')->assertForbidden();
});
