<?php

use App\Models\User;
use App\Rules\Handle;
use App\Support\HandleGenerator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

it('builds a handle from the name', function (string $name, string $expected) {
    // Given a display name
    // When a handle is generated
    $handle = app(HandleGenerator::class)->for($name, 'someone@example.com');

    // Then it is lowercase, ASCII and underscore-separated
    expect($handle)->toBe($expected);
})->with([
    'plain' => ['Amina Rahman', 'amina_rahman'],
    'accents' => ['Zoë Müller', 'zoe_muller'],
    'punctuation' => ['  Dr. Who?! ', 'dr_who'],
    'truncated' => [str_repeat('a', 40), str_repeat('a', 24)],
]);

it('falls back to the email name when the display name is unusable', function () {
    // Given a name with no usable characters
    // When a handle is generated
    $handle = app(HandleGenerator::class)->for('!!', 'rafi.khan@example.com');

    // Then the email's local part is used
    expect($handle)->toBe('rafi_khan');
});

it('adds a numeric suffix when the handle is taken or reserved', function () {
    // Given a taken handle
    User::factory()->create(['handle' => 'amina_rahman']);

    // When handles are generated for the same name and for a reserved word
    $taken = app(HandleGenerator::class)->for('Amina Rahman', 'a@example.com');
    $reserved = app(HandleGenerator::class)->for('Admin', 'b@example.com');

    // Then each gets a suffix
    expect($taken)->toMatch('/^amina_rahman_\d{4}$/');
    expect($reserved)->toMatch('/^admin_\d{4}$/');
});

it('always produces a handle that passes the Handle rule, whatever the input', function () {
    // Given a few hundred hostile and random names and emails, with some handles already taken
    User::factory()->create(['handle' => 'member']);
    $fixed = ['', ' ', '!!', '😀😀😀', 'Админ', '李小龍', 'a', 'ab', '___', '0', 'ADMIN', 'Admin', 'api', "\0\0", "x\u{202E}y",
        str_repeat('é', 100), str_repeat('😀', 50), "invalid \xC3\x28 utf8", 'Ünïcödé Nàmé'];
    $names = $fixed;
    for ($i = 0; $i < 190; $i++) {
        $names[] = match ($i % 4) {
            0 => bin2hex(random_bytes(random_int(1, 40))),
            1 => implode('', array_map(fn () => mb_chr(random_int(0x20, 0x2FFF), 'UTF-8'), range(0, random_int(0, 25)))),
            2 => implode('', array_map(fn () => mb_chr(random_int(0x1F300, 0x1F64F), 'UTF-8'), range(0, random_int(0, 8)))),
            default => Str::random(random_int(0, 60)),
        };
    }

    foreach ($names as $name) {
        foreach (['', 'x@example.com', 'admin@example.com', 'ünï@example.com', '😀@example.com'] as $email) {
            // When a handle is generated
            $handle = app(HandleGenerator::class)->for($name, $email);

            // Then it satisfies the same rule the API applies to user-chosen handles
            $validator = Validator::make(['handle' => $handle], ['handle' => [new Handle]]);
            expect($validator->passes())->toBeTrue("'$handle' from ".json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE));
            expect($handle)->toMatch('/^[a-z0-9_]{3,30}$/');
        }
    }
});
