<?php

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

/** @return list<SettingKey> the integer anti-cheat keys that the generic range rules cover (the older flag score included) */
function antiCheatIntKeys(): array
{
    return array_values(array_filter(SettingKey::cases(), fn (SettingKey $key) => str_starts_with($key->value, 'anticheat.') && is_int($key->default())));
}

/** @return list<SettingKey> the decimal anti-cheat keys except the two integrity ratios, which depend on each other */
function antiCheatDecimalKeys(): array
{
    return array_values(array_filter(SettingKey::cases(), fn (SettingKey $key) => str_starts_with($key->value, 'anticheat.') && is_string($key->default()) && preg_match(SettingKey::DECIMAL_PATTERN, (string) $key->default()) === 1 && ! in_array($key, [SettingKey::AnticheatProductWatchRatio, SettingKey::AnticheatProductAlertRatio], true)));
}

function antiCheatPatch(string $key, mixed $value): TestResponse
{
    return test()->patchJson('/api/v1/admin/settings/'.$key, ['value' => $value]);
}

it('adds exactly the 32 documented keys with their documented defaults', function () {
    // Given the documented table of the design
    $expected = [
        'anticheat.weight.shared_device' => 40, 'anticheat.weight.ip_cluster' => 15, 'anticheat.weight.vote_burst' => 25, 'anticheat.weight.fresh_account' => 15,
        'anticheat.weight.disposable_email' => 25, 'anticheat.weight.single_maker_voter' => 20, 'anticheat.weight.login_churn' => 20, 'anticheat.weight.coordinated_votes' => 35,
        'anticheat.group_window_hours' => 24, 'anticheat.shared_device.min_accounts' => 3, 'anticheat.ip_cluster.min_accounts' => 5, 'anticheat.ip_cluster.established_days' => 30,
        'anticheat.ip_cluster.discount' => '0.5', 'anticheat.burst.window_minutes' => 10, 'anticheat.burst.min_votes' => 30, 'anticheat.burst.ratio' => '4',
        'anticheat.user_burst.votes' => 8, 'anticheat.user_burst.minutes' => 5, 'anticheat.fresh_account.minutes' => 30,
        'anticheat.single_maker.min_votes' => 5, 'anticheat.single_maker.days' => 30, 'anticheat.single_maker.share' => '0.8', 'anticheat.login_churn.min_accounts' => 4,
        'anticheat.coordinated.min_accounts' => 3, 'anticheat.coordinated.min_products' => 4, 'anticheat.coordinated.overlap' => '0.8', 'anticheat.coordinated.max_popularity_pct' => 40, 'anticheat.decay_days' => 14,
        'anticheat.product_watch_ratio' => '0.9', 'anticheat.product_alert_ratio' => '0.7', 'retention.risk_signals_reviewed_days' => 90,
    ];

    // When the seeder runs
    $this->seed(SettingSeeder::class);

    // Then each key is stored with its default (and the domain list too: 32 keys in all)
    foreach ($expected as $key => $default) {
        expect(Setting::query()->findOrFail($key)->value)->toBe($default, $key);
    }
    expect(count($expected) + 1)->toBe(32)->and(Setting::query()->findOrFail('anticheat.disposable_domains')->value)->toBe(SettingKey::DEFAULT_DISPOSABLE_DOMAINS);
    expect(Setting::query()->findOrFail('anticheat.flag_risk_score')->value)->toBe(70);
});

it('accepts both bounds of an integer key and rejects one outside, with 422', function (SettingKey $key) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    [$min, $max] = $key->intRange();

    // When the bounds are written
    // Then both are stored as integers, and one below / above is a validation error that changes nothing
    antiCheatPatch($key->value, $min)->assertOk()->assertJsonPath('data.value', $min);
    antiCheatPatch($key->value, $max)->assertOk()->assertJsonPath('data.value', $max);
    antiCheatPatch($key->value, $min - 1)->assertUnprocessable()->assertJsonValidationErrors('value');
    antiCheatPatch($key->value, $max + 1)->assertUnprocessable()->assertJsonValidationErrors('value');
    expect(app(Settings::class)->integer($key))->toBe($max, $key->value);
})->with(fn () => array_map(fn (SettingKey $key) => [$key], antiCheatIntKeys()));

it('rejects wrongly typed integer values with 422 and stores nothing', function (SettingKey $key) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When a boolean, a float, an array, an object, a null, junk, an empty string and no value arrive
    // Then each is refused
    foreach ([true, 1.5, [10], ['a' => 10], null, 'abc', '10x', ''] as $bad) {
        antiCheatPatch($key->value, $bad)->assertUnprocessable()->assertJsonValidationErrors('value');
    }
    test()->patchJson('/api/v1/admin/settings/'.$key->value, [])->assertUnprocessable()->assertJsonValidationErrors('value');
    expect(Setting::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
})->with(fn () => array_map(fn (SettingKey $key) => [$key], antiCheatIntKeys()));

it('accepts a digit string for an integer key like every other integer setting', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the value arrives as "15"
    antiCheatPatch('anticheat.decay_days', '15')->assertOk();

    // Then it is stored as the integer 15
    expect(Setting::query()->findOrFail('anticheat.decay_days')->value)->toBe(15);
});

it('accepts both bounds of a decimal key and rejects values outside, malformed values and wrong types', function (SettingKey $key) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    [$min, $max] = $key->decimalRange();
    $low = (string) $min;
    $high = (string) $max;
    // One hundredth outside whatever the range is
    $below = number_format($min - 0.01, 2, '.', '');
    $above = number_format($max + 0.01, 2, '.', '');

    // When the bounds are written
    // Then they are stored as strings and read back through decimal()
    antiCheatPatch($key->value, $low)->assertOk()->assertJsonPath('data.value', $low);
    antiCheatPatch($key->value, $high)->assertOk()->assertJsonPath('data.value', $high);
    expect(app(Settings::class)->decimal($key))->toBe((float) $max, $key->value);

    // And values outside the range, malformed strings and wrong types are validation errors
    foreach ([$below, $above, 'abc', '1e2', '0.1234', '-1', '.5', [1], ['a' => 1], null, true, 1] as $bad) {
        antiCheatPatch($key->value, $bad)->assertUnprocessable()->assertJsonValidationErrors('value');
    }
})->with(fn () => array_map(fn (SettingKey $key) => [$key], antiCheatDecimalKeys()));

it('refuses a decimal with a trailing newline or a leading space in its own rules', function (string $value) {
    // Given a decimal key's rules (the HTTP layer trims surrounding whitespace before they run)
    // When they judge a padded value directly
    $passes = validator(['value' => $value], ['value' => SettingKey::AnticheatBurstRatio->rules()])->passes();

    // Then the anchored pattern refuses it
    expect($passes)->toBeFalse();
})->with(["4\n", ' 4', "4\n\n"]);

it('refuses a non-admin before looking at the value', function (string $key, mixed $value, Role $role) {
    // Given a reviewer, a maker or a member
    Sanctum::actingAs(User::factory()->role($role)->create());

    // When they send an invalid value for an anti-cheat key
    // Then it is 403 before validation, and nothing is stored
    antiCheatPatch($key, $value)->assertForbidden();
    expect(Setting::query()->count())->toBe(0);
})->with(function () {
    $cases = [];
    foreach ([Role::Reviewer, Role::Member] as $role) {
        foreach (['anticheat.weight.shared_device' => 'abc', 'anticheat.burst.ratio' => '1e2', 'anticheat.disposable_domains' => 'EVIL', 'anticheat.product_alert_ratio' => '5', 'retention.risk_signals_reviewed_days' => 1] as $key => $value) {
            $cases[$role->value.' '.$key] = [$key, $value, $role];
        }
    }

    return $cases;
});

it('lists every new key with value and default, and writes no audit row for an unchanged value', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When they list the settings and re-send the default of one key
    $keys = collect($this->getJson('/api/v1/admin/settings')->assertOk()->json('data'))->pluck('key');
    antiCheatPatch('anticheat.decay_days', 14)->assertOk();

    // Then every case is listed and the unchanged value left no audit row
    expect($keys->all())->toContain('anticheat.weight.coordinated_votes', 'anticheat.disposable_domains', 'retention.risk_signals_reviewed_days');
    expect($keys->count())->toBe(count(SettingKey::cases()));
    expect(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(0);
});

it('serves the default for a stored integer outside the range or of the wrong type', function (SettingKey $key, mixed $stored) {
    // Given a value that bypassed the write rules
    retentionStoreRaw($key, $stored);

    // When it is read as an integer
    // Then the default is served
    expect(app(Settings::class)->integer($key))->toBe($key->default());
})->with([
    'weight above' => [SettingKey::AnticheatWeightSharedDevice, 101],
    'weight below' => [SettingKey::AnticheatWeightSharedDevice, -1],
    'window zero' => [SettingKey::AnticheatGroupWindowHours, 0],
    'window above' => [SettingKey::AnticheatGroupWindowHours, 49],
    'min accounts one' => [SettingKey::AnticheatSharedDeviceMinAccounts, 1],
    'decay junk' => [SettingKey::AnticheatDecayDays, 'abc'],
    'decay float' => [SettingKey::AnticheatDecayDays, 14.5],
    'decay array' => [SettingKey::AnticheatDecayDays, [14]],
]);

it('serves the default for a stored decimal that is malformed or outside the range', function (SettingKey $key, mixed $stored) {
    // Given a value that bypassed the write rules
    retentionStoreRaw($key, $stored);

    // When it is read as a decimal
    // Then the default is served
    expect(app(Settings::class)->decimal($key))->toBe((float) $key->default());
})->with([
    'discount junk' => [SettingKey::AnticheatIpClusterDiscount, 'abc'],
    'discount above one' => [SettingKey::AnticheatIpClusterDiscount, '2'],
    'discount exponent' => [SettingKey::AnticheatIpClusterDiscount, '1e0'],
    'overlap below half' => [SettingKey::AnticheatCoordinatedOverlap, '0.4'],
    'ratio below one' => [SettingKey::AnticheatBurstRatio, '0.5'],
    'ratio int' => [SettingKey::AnticheatBurstRatio, 4],
]);

it('reads the default domain list and parses a padded valid list on write', function () {
    // Given the default list and an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the default is read and a padded list is written
    $default = app(Settings::class)->domains();
    antiCheatPatch('anticheat.disposable_domains', ' mailinator.com , yopmail.com ')->assertOk();

    // Then the default has twenty valid entries and the stored list is served trimmed
    expect($default)->toHaveCount(20)->and($default)->toContain('mailinator.com', 'yopmail.com');
    expect(app(Settings::class)->domains())->toBe(['mailinator.com', 'yopmail.com']);
});

it('rejects an invalid domain list on write with 422', function (mixed $value) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When an invalid list is written
    // Then it is refused and the stored list is unchanged
    antiCheatPatch('anticheat.disposable_domains', $value)->assertUnprocessable()->assertJsonValidationErrors('value');
    expect(Setting::query()->count())->toBe(0);
})->with([
    'uppercase' => ['Mailinator.com'],
    'wildcard' => ['*.evil.com'],
    'no dot' => ['evil'],
    'double dot' => ['a..b.com'],
    'trailing dot' => ['evil.com.'],
    'numeric tld' => ['evil.123'],
    'underscore' => ['ev_il.com'],
    'empty entry' => ['a.com,,b.com'],
    'trailing comma' => ['a.com,'],
    'newline inside' => ["a.com\nb.com"],
    'too many entries' => [fn () => implode(',', array_map(fn (int $i) => "d{$i}.com", range(1, 301)))],
    'too many characters' => [fn () => implode(',', array_fill(0, 50, str_repeat('a', 61).'.'.str_repeat('b', 20).'.com')).',x.com'],
    'empty string' => [''],
    'array' => [['a.com']],
    'object' => [['a' => 'a.com']],
    'null' => [null],
    'number' => [123],
    'bool' => [true],
]);

it('accepts exactly 300 entries', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    $list = implode(',', array_map(fn (int $i) => "d{$i}.com", range(1, 300)));

    // When 300 entries are written
    // Then they are stored
    antiCheatPatch('anticheat.disposable_domains', $list)->assertOk();
    expect(app(Settings::class)->domains())->toHaveCount(300);
});

it('serves the default list for a stored invalid list, warning with the key and never the value', function (mixed $stored) {
    // Given an invalid list that bypassed the write check
    retentionStoreRaw(SettingKey::AnticheatDisposableDomains, $stored instanceof Closure ? $stored() : $stored);
    Log::spy();

    // When the list is read
    $domains = app(Settings::class)->domains();

    // Then the default list is served and the warning carries the key only
    expect($domains)->toBe(app(Settings::class)::parseDomains(SettingKey::DEFAULT_DISPOSABLE_DOMAINS));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context === ['key' => 'anticheat.disposable_domains'] && ! str_contains($message, 'secret'))->once();
})->with([
    'uppercase' => ['SECRET.com'],
    'wildcard' => ['*.secret.com'],
    'junk' => ['secret'],
    'too many entries' => [fn () => implode(',', array_map(fn (int $i) => "d{$i}.com", range(1, 301)))],
    'too many characters' => [fn () => str_repeat('a', 4001)],
    'trailing comma' => ['a.com,'],
]);

it('reads a stored empty string as an empty list without a warning', function () {
    // Given a stored empty string (a direct database edit)
    retentionStoreRaw(SettingKey::AnticheatDisposableDomains, '');
    Log::spy();

    // When the list is read
    // Then it is empty (the rule is inert) and nothing was warned
    expect(app(Settings::class)->domains())->toBe([]);
    Log::shouldNotHaveReceived('warning');
});

it('keeps the alert ratio strictly below the watch ratio on write', function () {
    // Given an admin and the defaults 0.9 / 0.7
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When watch is raised, alert is lowered below watch, and the order would break
    antiCheatPatch('anticheat.product_watch_ratio', '0.95')->assertOk();
    antiCheatPatch('anticheat.product_alert_ratio', '0.5')->assertOk();

    // Then both moves work; an alert at or above the watch ratio and a watch at or below the alert ratio are refused
    antiCheatPatch('anticheat.product_alert_ratio', '0.95')->assertUnprocessable()->assertJsonValidationErrors('value');
    antiCheatPatch('anticheat.product_alert_ratio', '0.96')->assertUnprocessable();
    antiCheatPatch('anticheat.product_watch_ratio', '0.5')->assertUnprocessable()->assertJsonValidationErrors('value');
    antiCheatPatch('anticheat.product_watch_ratio', '0.4')->assertUnprocessable();
    expect(app(Settings::class)->integrityBands())->toBe(['watch' => 0.95, 'alert' => 0.5]);
});

it('treats an equal pair as inverted on write', function () {
    // Given an admin and the default alert of 0.7
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When watch is set to exactly the stored alert
    // Then it is refused (strict)
    antiCheatPatch('anticheat.product_watch_ratio', '0.7')->assertUnprocessable()->assertJsonValidationErrors('value');
});

it('serves both default bands, warning with key names, for a stored inverted pair', function () {
    // Given an inverted pair that bypassed the write check
    retentionStoreRaw(SettingKey::AnticheatProductWatchRatio, '0.6');
    retentionStoreRaw(SettingKey::AnticheatProductAlertRatio, '0.8');
    Log::spy();

    // When the bands are read
    $bands = app(Settings::class)->integrityBands();

    // Then both defaults are served with one warning naming the keys
    expect($bands)->toBe(['watch' => 0.9, 'alert' => 0.7]);
    Log::shouldHaveReceived('warning')->with('Integrity band settings are inverted; using the defaults.', ['keys' => ['anticheat.product_watch_ratio', 'anticheat.product_alert_ratio']])->once();
});

it('serves the stored bands when the pair is valid', function () {
    // Given a valid custom pair
    retentionStoreRaw(SettingKey::AnticheatProductWatchRatio, '0.8');
    retentionStoreRaw(SettingKey::AnticheatProductAlertRatio, '0.4');
    Log::spy();

    // When the bands are read
    // Then they are served and nothing was warned
    expect(app(Settings::class)->integrityBands())->toBe(['watch' => 0.8, 'alert' => 0.4]);
    Log::shouldNotHaveReceived('warning');
});

it('accepts the reviewed signal window through the admin API with its 7 to 3650 range', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the bounds are written
    // Then they are accepted and one outside is refused; the older window keeps its 30 day floor
    antiCheatPatch('retention.risk_signals_reviewed_days', 7)->assertOk();
    antiCheatPatch('retention.risk_signals_reviewed_days', 3650)->assertOk();
    antiCheatPatch('retention.risk_signals_reviewed_days', 6)->assertUnprocessable();
    antiCheatPatch('retention.risk_signals_reviewed_days', 3651)->assertUnprocessable();
    antiCheatPatch('retention.risk_signals_days', 29)->assertUnprocessable();
    expect(app(Settings::class)->days(SettingKey::RetentionRiskSignalsReviewedDays))->toBe(3650);
});

it('serves the default list for a wrongly typed stored list, warning about the type with the key only', function () {
    // Given an integer stored under the string key
    retentionStoreRaw(SettingKey::AnticheatDisposableDomains, 5);
    Log::spy();

    // When the list is read
    // Then the default list is served and the typed reader warned with the key, never the value
    expect(app(Settings::class)->domains())->toHaveCount(20);
    Log::shouldHaveReceived('warning')->with('Setting has a wrongly typed stored value; using the default.', ['key' => 'anticheat.disposable_domains'])->once();
});
