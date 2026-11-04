<?php

use App\Enums\SettingKey;
use App\Models\Setting;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Validator;

it('seeds every setting key with its default, and is safe to run twice', function () {
    // Given an empty settings table
    expect(Setting::query()->count())->toBe(0);

    // When the seeder runs twice
    $this->seed(SettingSeeder::class);
    $this->seed(SettingSeeder::class);

    // Then every key exists exactly once with its default value
    expect(Setting::query()->count())->toBe(count(SettingKey::cases()));
    expect(Setting::query()->find('launch.max_per_maker_per_day')->value)->toBe(2);
    expect(Setting::query()->find('digest.daily_send_at_utc')->value)->toBe('07:00');
});

it('seeds the retention windows from decision 23', function () {
    // Given a seeded settings table
    $this->seed(SettingSeeder::class);

    // When the retention keys are read
    // Then they hold the documented defaults
    expect(Setting::query()->find('retention.auth_events_days')->value)->toBe(180);
    expect(Setting::query()->find('retention.vote_hashes_days')->value)->toBe(90);
    expect(Setting::query()->find('retention.risk_signals_days')->value)->toBe(365);
    expect(Setting::query()->find('retention.rank_snapshots_days')->value)->toBe(180);
    expect(Setting::query()->find('retention.audit_network_days')->value)->toBe(365);
    expect(Setting::query()->find('retention.notifications_days')->value)->toBe(90);
    expect(Setting::query()->find('retention.email_messages_days')->value)->toBe(90);
    expect(Setting::query()->find('retention.exports_days')->value)->toBe(7);
});

it('seeds the anti-cheat defaults and the reviewed signal window', function () {
    // Given a seeded settings table
    $this->seed(SettingSeeder::class);

    // When a few of the keys are read
    // Then they hold the documented defaults, integers as integers and decimals as strings
    expect(Setting::query()->find('anticheat.weight.shared_device')->value)->toBe(40);
    expect(Setting::query()->find('anticheat.burst.ratio')->value)->toBe('4');
    expect(Setting::query()->find('anticheat.product_alert_ratio')->value)->toBe('0.7');
    expect(Setting::query()->find('anticheat.disposable_domains')->value)->toContain('mailinator.com');
    expect(Setting::query()->find('retention.risk_signals_reviewed_days')->value)->toBe(90);
});

it('seeds the community comment edit window as the integer 15', function () {
    // Given a seeded settings table
    $this->seed(SettingSeeder::class);

    // When the key is read
    // Then it holds the documented default, as an integer
    expect(Setting::query()->find('community.comment_edit_minutes')->value)->toBe(15);
});

it('seeds the community report open cap as the integer 20', function () {
    // Given a seeded settings table
    $this->seed(SettingSeeder::class);

    // When the key is read
    // Then it holds the documented default, as an integer
    expect(Setting::query()->find('community.report_open_cap')->value)->toBe(20);
});

it('keeps an admin-edited value when the seeder runs again', function () {
    // Given a seeded table where an admin raised a limit
    $this->seed(SettingSeeder::class);
    Setting::query()->whereKey('launch.max_per_maker_per_day')->update(['value' => 5]);

    // When the seeder runs again (for example on deploy)
    $this->seed(SettingSeeder::class);

    // Then the edited value survives
    expect(Setting::query()->find('launch.max_per_maker_per_day')->value)->toBe(5);
});

it('declares a default that satisfies its own validation rules', function (SettingKey $key) {
    // Given a setting key
    // When its default is validated against its rules
    $validator = Validator::make(['value' => $key->default()], ['value' => $key->rules()]);

    // Then it passes
    expect($validator->passes())->toBeTrue();
})->with(fn () => SettingKey::cases());
