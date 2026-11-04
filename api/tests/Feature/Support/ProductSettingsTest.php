<?php

use App\Enums\SettingKey;
use App\Support\Settings;

it('ships the product and launch defaults from the spec', function () {
    // Given a fresh database (no setting rows)
    $settings = app(Settings::class);

    // When the values are read
    // Then they are the spec's defaults
    expect($settings->get(SettingKey::LaunchMaxDaysAhead))->toBe(60);
    expect($settings->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(2);
    expect($settings->get(SettingKey::ProductMaxScreenshots))->toBe(6);
    expect($settings->get(SettingKey::ProductMaxTopics))->toBe(3);
    expect($settings->get(SettingKey::ProductMaxPendingPerMaker))->toBe(5);
});

it('bounds product.max_pending_per_maker between 1 and 50', function (mixed $value, bool $valid) {
    // Given the key's own rules
    $validator = validator(['value' => $value], ['value' => SettingKey::ProductMaxPendingPerMaker->rules()]);

    // When / Then
    expect($validator->passes())->toBe($valid);
})->with([[0, false], [1, true], [50, true], [51, false], ['x', false]]);

it('bounds launch.max_days_ahead between 1 and 365', function (mixed $value, bool $valid) {
    // Given the key's own rules
    $validator = validator(['value' => $value], ['value' => SettingKey::LaunchMaxDaysAhead->rules()]);

    // When / Then
    expect($validator->passes())->toBe($valid);
})->with([[0, false], [1, true], [365, true], [366, false], ['x', false]]);
