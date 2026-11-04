<?php

use App\Enums\SettingKey;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

it('serves the default time of day when nothing is stored', function (SettingKey $key) {
    // Given a fresh database (no setting rows)
    // When the time of day is read
    $time = app(Settings::class)->timeOfDay($key);

    // Then it is the documented default
    expect($time)->toBe('07:00')->and($key->default())->toBe('07:00');
})->with([[SettingKey::DigestDailySendAtUtc], [SettingKey::DigestWeeklySendAtUtc]]);

it('serves a valid stored time of day', function (string $stored) {
    // Given an admin-written valid time
    retentionStoreRaw(SettingKey::DigestDailySendAtUtc, $stored);

    // When it is read
    // Then it is served as written
    expect(app(Settings::class)->timeOfDay(SettingKey::DigestDailySendAtUtc))->toBe($stored);
})->with(['00:00', '06:30', '09:05', '19:59', '21:59']);

it('falls back to the default with a warning naming the key only for an invalid stored time', function (mixed $stored) {
    // Given a value that bypassed the write rules (a direct database edit)
    Log::spy();
    retentionStoreRaw(SettingKey::DigestWeeklySendAtUtc, $stored);

    // When it is read
    $time = app(Settings::class)->timeOfDay(SettingKey::DigestWeeklySendAtUtc);

    // Then the default is served and the warning carries the key, never the stored value
    expect($time)->toBe('07:00');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context === ['key' => 'digest.weekly_send_at_utc'] && ! str_contains($message, 'zzz'))->atLeast()->once();
})->with([
    'no leading zero' => ['7:00'],
    'hour 22 (the give-up margin)' => ['22:00'],
    'late evening' => ['23:59'],
    'hour 24' => ['24:00'],
    'hour 25' => ['25:00'],
    'minute 60' => ['07:60'],
    'seconds' => ['07:00:00'],
    'trailing newline' => ["07:00\n"],
    'junk' => ['zzz'],
    'int' => [700],
    'array' => [['07:00']],
    'bool' => [true],
    'empty' => [''],
]);

it('refuses a key that is not a digest time', function () {
    // Given / When / Then
    expect(fn () => app(Settings::class)->timeOfDay(SettingKey::RetentionAuthEventsDays))->toThrow(LogicException::class);
});

it('rejects a send time of 22:00 or later in the setting rules and accepts 21:59', function (string $time, bool $valid) {
    // Given the key's own validation rules
    // When a time is checked
    // Then 22:00 and later are refused so the 23:00 give-up hour always leaves room
    foreach ([SettingKey::DigestDailySendAtUtc, SettingKey::DigestWeeklySendAtUtc] as $key) {
        expect(validator(['value' => $time], ['value' => $key->rules()])->passes())->toBe($valid, $key->value.' '.$time);
    }
})->with([['00:00', true], ['07:00', true], ['21:59', true], ['22:00', false], ['22:30', false], ['23:59', false], ['7:00', false], ['24:00', false]]);
