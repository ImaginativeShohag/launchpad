<?php

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('ships the ranking defaults as decimal strings', function () {
    // Given a fresh database (no setting rows)
    $settings = app(Settings::class);

    // When the values are read
    // Then they are the spec's defaults, stored as strings
    expect($settings->get(SettingKey::RankingDecayExponent))->toBe('0.9');
    expect($settings->get(SettingKey::RankingEarlyBonus))->toBe('0.35');
    expect(SettingKey::RankingDecayExponent->value)->toBe('ranking.decay_exponent');
    expect(SettingKey::RankingEarlyBonus->value)->toBe('ranking.early_bonus');
});

it('reads decimals as floats', function () {
    // Given defaults and an admin-set value
    $settings = app(Settings::class);
    $admin = User::factory()->role(Role::Admin)->create();
    $settings->set(SettingKey::RankingEarlyBonus, '1.25', $admin);

    // When read through decimal()
    // Then they are floats
    expect($settings->decimal(SettingKey::RankingDecayExponent))->toBe(0.9);
    expect($settings->decimal(SettingKey::RankingEarlyBonus))->toBe(1.25);
});

it('falls back to the default float when the stored value is malformed', function () {
    // Given a stored value that is a string but not a decimal
    Setting::query()->create(['key' => 'ranking.decay_exponent', 'value' => 'abc']);

    // When read through decimal()
    // Then the default float is served
    expect(app(Settings::class)->decimal(SettingKey::RankingDecayExponent))->toBe(0.9);
});

it('bounds the decay exponent between 0.1 and 3 and the early bonus between 0 and 5', function (SettingKey $key, mixed $value, bool $valid) {
    // Given the key's own rules
    $validator = validator(['value' => $value], ['value' => $key->rules()]);

    // When / Then
    expect($validator->passes())->toBe($valid);
})->with([
    'decay low edge' => [SettingKey::RankingDecayExponent, '0.1', true],
    'decay high edge' => [SettingKey::RankingDecayExponent, '3', true],
    'decay below' => [SettingKey::RankingDecayExponent, '0.09', false],
    'decay zero' => [SettingKey::RankingDecayExponent, '0', false],
    'decay above' => [SettingKey::RankingDecayExponent, '3.1', false],
    'bonus zero' => [SettingKey::RankingEarlyBonus, '0', true],
    'bonus high edge' => [SettingKey::RankingEarlyBonus, '5', true],
    'bonus above' => [SettingKey::RankingEarlyBonus, '5.1', false],
    'bonus negative' => [SettingKey::RankingEarlyBonus, '-1', false],
    'leading zero' => [SettingKey::RankingDecayExponent, '03', false],
    'double zero' => [SettingKey::RankingEarlyBonus, '00', false],
    'four decimals' => [SettingKey::RankingDecayExponent, '3.001', false],
    'no leading digit' => [SettingKey::RankingDecayExponent, '.5', false],
    'trailing dot' => [SettingKey::RankingDecayExponent, '1.', false],
    'trailing newline' => [SettingKey::RankingDecayExponent, "0.5\n", false],
    'exponent' => [SettingKey::RankingDecayExponent, '1e0', false],
    'trailing zero is fine' => [SettingKey::RankingDecayExponent, '0.90', true],
]);

it('serves the default for a stored value outside the range or not a plain decimal', function (string $stored) {
    // Given a stored value that bypassed the write rules (a direct database edit)
    DB::table('settings')->updateOrInsert(['key' => 'ranking.decay_exponent'], ['value' => json_encode($stored), 'updated_at' => now()]);
    Cache::flush();

    // When it is read as a decimal
    $value = app(Settings::class)->decimal(SettingKey::RankingDecayExponent);

    // Then the default is served
    expect($value)->toBe(0.9);
})->with(['99', '0', '0.05', '3.5', '1e2', "0.5\n", '03', 'abc']);

it('accepts a valid decimal through the admin API and serves it', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the decay exponent is set to "0.5"
    $response = $this->patchJson('/api/v1/admin/settings/ranking.decay_exponent', ['value' => '0.5']);

    // Then it is stored as a string, served as a float, and audited
    $response->assertOk()->assertJsonPath('data.value', '0.5');
    expect(Setting::query()->findOrFail('ranking.decay_exponent')->value)->toBe('0.5');
    expect(app(Settings::class)->decimal(SettingKey::RankingDecayExponent))->toBe(0.5);
    expect(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(1);
});

it('rejects malformed ranking values with 422 and stores nothing', function (string $key, array $payload) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the value is malformed
    $response = $this->patchJson('/api/v1/admin/settings/'.$key, $payload);

    // Then it is a validation error, never a 500
    $response->assertUnprocessable()->assertJsonValidationErrors('value');
    expect(Setting::query()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'decay abc' => ['ranking.decay_exponent', ['value' => 'abc']],
    'decay zero' => ['ranking.decay_exponent', ['value' => '0']],
    'decay too high' => ['ranking.decay_exponent', ['value' => '3.1']],
    'decay exponent notation' => ['ranking.decay_exponent', ['value' => '1e2']],
    'decay array' => ['ranking.decay_exponent', ['value' => ['0.5']]],
    'decay object' => ['ranking.decay_exponent', ['value' => ['a' => '0.5']]],
    'decay null' => ['ranking.decay_exponent', ['value' => null]],
    'decay empty' => ['ranking.decay_exponent', ['value' => '']],
    'decay missing' => ['ranking.decay_exponent', []],
    'decay float' => ['ranking.decay_exponent', ['value' => 0.5]],
    'decay int' => ['ranking.decay_exponent', ['value' => 1]],
    'decay bool' => ['ranking.decay_exponent', ['value' => true]],
    'decay four decimals' => ['ranking.decay_exponent', ['value' => '0.5555']],
    'bonus negative' => ['ranking.early_bonus', ['value' => '-0.1']],
    'bonus too high' => ['ranking.early_bonus', ['value' => '5.1']],
    'bonus array' => ['ranking.early_bonus', ['value' => [1]]],
    'bonus null' => ['ranking.early_bonus', ['value' => null]],
]);

it('lists both ranking keys with their string defaults', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When they list settings
    $response = $this->getJson('/api/v1/admin/settings');

    // Then both keys are present with value and default
    $response->assertOk()
        ->assertJsonFragment(['key' => 'ranking.decay_exponent', 'value' => '0.9', 'default' => '0.9'])
        ->assertJsonFragment(['key' => 'ranking.early_bonus', 'value' => '0.35', 'default' => '0.35']);
});
