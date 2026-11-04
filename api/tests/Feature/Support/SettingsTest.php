<?php

use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

it('returns the default until a value is stored', function () {
    // Given no stored setting
    // When it is read
    // Then the default comes back
    expect(app(Settings::class)->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(2);
});

it('stores a change, refreshes the cache and writes an audit entry', function () {
    // Given an admin and a cached read of the old value
    $admin = User::factory()->create();
    $settings = app(Settings::class);
    expect($settings->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(2);

    // When the admin changes it
    $settings->set(SettingKey::LaunchMaxPerMakerPerDay, 3, $admin);

    // Then the new value is served and the change is audited with before and after
    expect($settings->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(3);
    $log = AuditLog::query()->where('action', 'setting.updated')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id);
    expect($log->meta)->toMatchArray(['key' => 'launch.max_per_maker_per_day', 'before' => 2, 'after' => 3]);
});

it('takes the audit before-value from the database, not from a stale cache', function () {
    // Given a stored value of 4 and a stale cached value of 9
    $admin = User::factory()->create();
    Setting::query()->create(['key' => 'launch.max_per_maker_per_day', 'value' => 4]);
    Cache::put('settings:launch.max_per_maker_per_day', 9);

    // When the admin changes it
    app(Settings::class)->set(SettingKey::LaunchMaxPerMakerPerDay, 5, $admin);

    // Then the audit records the database value as before
    $log = AuditLog::query()->where('action', 'setting.updated')->firstOrFail();
    expect($log->meta)->toMatchArray(['before' => 4, 'after' => 5]);
});

it('writes only one audit row when the same value is set twice', function () {
    // Given an admin
    $admin = User::factory()->create();
    $settings = app(Settings::class);

    // When the same new value is set twice
    $settings->set(SettingKey::LaunchMaxPerMakerPerDay, 3, $admin);
    $settings->set(SettingKey::LaunchMaxPerMakerPerDay, 3, $admin);

    // Then only the first change is audited
    expect(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(1);
});

it('does not audit or store a value equal to the current default', function () {
    // Given an admin and no stored row
    $admin = User::factory()->create();

    // When the default value is "set"
    app(Settings::class)->set(SettingKey::LaunchMaxPerMakerPerDay, 2, $admin);

    // Then nothing is written
    expect(AuditLog::query()->count())->toBe(0);
    expect(Setting::query()->count())->toBe(0);
});

it('rolls the setting back when the audit entry cannot be written', function () {
    // Given an admin and an HMAC key that makes Audit::record throw
    $admin = User::factory()->create();
    config(['launchpad.hmac_key' => 'short']);

    // When the change is attempted
    // Then it fails
    expect(fn () => app(Settings::class)->set(SettingKey::LaunchMaxPerMakerPerDay, 3, $admin))
        ->toThrow(RuntimeException::class);

    // And neither the setting nor an audit row was stored
    expect(Setting::query()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
});

it('falls back to the default when a stored value has the wrong type', function () {
    // Given an int key stored as a string and a string key stored as an int
    Setting::query()->create(['key' => 'launch.max_per_maker_per_day', 'value' => 'abc']);
    Setting::query()->create(['key' => 'digest.daily_send_at_utc', 'value' => 5]);

    // When they are read
    $settings = app(Settings::class);

    // Then the defaults are served and the bad values are not cached
    expect($settings->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(2);
    expect($settings->get(SettingKey::DigestDailySendAtUtc))->toBe('07:00');
    expect(Cache::has('settings:launch.max_per_maker_per_day'))->toBeFalse();
    expect(Cache::has('settings:digest.daily_send_at_utc'))->toBeFalse();
});

it('refuses a value of the wrong type before touching the database', function () {
    // Given an admin
    $admin = User::factory()->create();

    // When an int key gets a string, and a string key gets an int
    // Then both are refused and nothing is stored
    expect(fn () => app(Settings::class)->set(SettingKey::LaunchMaxPerMakerPerDay, '3', $admin))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(Settings::class)->set(SettingKey::DigestDailySendAtUtc, 700, $admin))
        ->toThrow(InvalidArgumentException::class);
    expect(Setting::query()->count())->toBe(0);
});

it('clears the cache only after the surrounding transaction commits', function () {
    // Given an admin, a warm cache, and the change made inside an outer transaction
    $admin = User::factory()->create();
    $settings = app(Settings::class);
    $settings->get(SettingKey::LaunchMaxPerMakerPerDay);

    // When the change is rolled back with the outer transaction
    try {
        DB::transaction(function () use ($settings, $admin) {
            $settings->set(SettingKey::LaunchMaxPerMakerPerDay, 3, $admin);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    // Then the cached old value is still served (it was never invalidated, and is still correct)
    expect(Cache::get('settings:launch.max_per_maker_per_day'))->toBe(2);
    expect($settings->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(2);
});

it('keeps a committed change when the cache cannot be cleared', function () {
    // Given an admin and a cache store that throws on forget
    $admin = User::factory()->create();
    Exceptions::fake();
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('redis down'));

    // When the setting changes
    app(Settings::class)->set(SettingKey::LaunchMaxPerMakerPerDay, 3, $admin);

    // Then the row is updated, the failure was reported, and nothing was thrown
    expect(Setting::query()->findOrFail('launch.max_per_maker_per_day')->value)->toBe(3);
    Exceptions::assertReported(RuntimeException::class);
});
