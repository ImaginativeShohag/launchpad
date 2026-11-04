<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Enums\SettingKey;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

it('reads the documented defaults on a fresh database', function () {
    // Given no setting rows
    // When the config is read
    $config = RuleConfig::fromSettings(app(Settings::class));

    // Then every value is the default
    expect($config->weightSharedDevice)->toBe(40)->and($config->weightCoordinatedVotes)->toBe(35)
        ->and($config->groupWindowHours)->toBe(24)->and($config->ipClusterDiscount)->toBe(0.5)->and($config->burstRatio)->toBe(4.0)
        ->and($config->userBurstVotes)->toBe(8)->and($config->decayDays)->toBe(14)->and($config->flagScore)->toBe(70)
        ->and($config->domains)->toHaveCount(20)->and($config->bands)->toBe(['watch' => 0.9, 'alert' => 0.7]);
});

it('serves an admin-changed value', function () {
    // Given an admin who raised two values
    $config = antiCheatConfig(['anticheat.weight.shared_device' => 55, 'anticheat.single_maker.share' => '0.6']);

    // When the config is read
    // Then the new values are served
    expect($config->weightSharedDevice)->toBe(55)->and($config->singleMakerShare)->toBe(0.6);
});

it('falls back to the default for a stored out-of-range value and warns once with the key only', function () {
    // Given a value that bypassed the write rules
    retentionStoreRaw(SettingKey::AnticheatUserBurstVotes, 9999);
    Log::spy();

    // When the config is read
    $config = RuleConfig::fromSettings(app(Settings::class));

    // Then the default is served and one warning names the key, never the value
    expect($config->userBurstVotes)->toBe(8);
    Log::shouldHaveReceived('warning')->with('Integer setting is invalid; using the default.', ['key' => 'anticheat.user_burst.votes'])->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ! str_contains(json_encode([$message, $context]), '9999'));
});

it('is immutable', function () {
    // Given a config
    $config = RuleConfig::fromSettings(app(Settings::class));

    // When a property is written
    // Then PHP refuses
    expect(fn () => $config->decayDays = 1)->toThrow(Error::class);
});
