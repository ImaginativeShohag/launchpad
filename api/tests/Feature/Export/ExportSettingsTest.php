<?php

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

it('seeds retention.exports_days as the integer 7 with the range 1 to 30', function () {
    // Given the key
    // When its default and range are read
    // Then they are the documented ones, and the default satisfies the key's own rules
    expect(SettingKey::RetentionExportsDays->value)->toBe('retention.exports_days')->and(SettingKey::RetentionExportsDays->default())->toBe(7)
        ->and(SettingKey::RetentionExportsDays->dayRange())->toBe([1, 30]);
    expect(validator(['value' => 7], ['value' => SettingKey::RetentionExportsDays->rules()])->passes())->toBeTrue();
});

it('accepts 1 and 30 through the admin API and refuses bad payloads and non-admins', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the window is set to both edges
    $this->patchJson('/api/v1/admin/settings/retention.exports_days', ['value' => 1])->assertOk()->assertJsonPath('data.value', 1);
    $this->patchJson('/api/v1/admin/settings/retention.exports_days', ['value' => 30])->assertOk()->assertJsonPath('data.value', 30);

    // Then bad payloads are validation errors, never a 500
    foreach ([['value' => 0], ['value' => 31], ['value' => true], ['value' => 1.5], ['value' => [7]], ['value' => ['a' => 7]], ['value' => null], ['value' => 'abc'], []] as $payload) {
        $this->patchJson('/api/v1/admin/settings/retention.exports_days', $payload)->assertUnprocessable()->assertJsonValidationErrors('value');
    }
    expect(app(Settings::class)->days(SettingKey::RetentionExportsDays))->toBe(30);

    // And a reviewer is refused before validation
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    $this->patchJson('/api/v1/admin/settings/retention.exports_days', ['value' => 99])->assertForbidden();
});

it('serves the stored value and falls back to 7 for a corrupt one, warning without the value', function (mixed $stored) {
    // Given a value that bypassed the write rules (a direct database edit)
    Log::spy();
    retentionStoreRaw(SettingKey::RetentionExportsDays, $stored);

    // When it is read as days
    $days = app(Settings::class)->days(SettingKey::RetentionExportsDays);

    // Then the default is served and the warning never carries the stored value
    expect($days)->toBe(7);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['key' => 'retention.exports_days']);
})->with(['zero' => [0], 'thirty one' => [31], 'string' => ['7'], 'float' => [7.5], 'negative' => [-3]]);

it('serves a stored value inside the range', function () {
    // Given a stored 12
    retentionStoreRaw(SettingKey::RetentionExportsDays, 12);

    // When it is read
    // Then 12 is served
    expect(app(Settings::class)->days(SettingKey::RetentionExportsDays))->toBe(12);
});

it('keeps the exports disk private: no url, no public root, no link, throwing writes', function () {
    // Given the disk configuration
    $disk = config('filesystems.disks.exports');

    // When it is inspected
    // Then it is local, private, throws on failure and is exposed nowhere
    expect($disk['driver'])->toBe('local')->and($disk['visibility'])->toBe('private')->and($disk['throw'])->toBeTrue()
        ->and($disk)->not->toHaveKey('url')->and($disk)->not->toHaveKey('serve');
    expect($disk['root'])->toBe(storage_path('app/private/exports'))->not->toContain('app/public');
    expect(array_values(config('filesystems.links')))->not->toContain($disk['root'])->not->toContain(storage_path('app/private'));
    expect(array_keys(config('filesystems.links')))->each->not->toContain('export');
});

it('can be replaced by a fake disk in tests', function () {
    // Given a faked exports disk
    Storage::fake('exports');

    // When a file is written and read back
    Storage::disk('exports')->put('p/e.zip', 'zip');

    // Then the fake serves it
    expect(Storage::disk('exports')->get('p/e.zip'))->toBe('zip');
    expect(DB::table('settings')->count())->toBe(0);
});

it('reads an integer setting back from a cache that returns numbers as strings (the Redis store does)', function () {
    // Given the cache holding "12" for an integer key, as a warm Redis cache would return it
    Cache::put('settings:retention.exports_days', '12', 600);
    Log::spy();

    // When the setting is read
    $settings = app(Settings::class);

    // Then it is the integer 12 (not the default 7), with no warning
    expect($settings->get(SettingKey::RetentionExportsDays))->toBe(12)->and($settings->days(SettingKey::RetentionExportsDays))->toBe(12);
    Log::shouldNotHaveReceived('warning');
});

it('leaves a non-numeric cached string under an integer key to the default and keeps string keys as strings', function () {
    // Given junk cached under an integer key and a numeric-looking string under a string key
    Cache::put('settings:retention.exports_days', 'junk', 600);
    Cache::put('settings:ranking.decay_exponent', '1', 600);

    // When both are read
    $settings = app(Settings::class);

    // Then the junk is still refused (the default is served) and the decimal setting stays the string it is
    expect($settings->days(SettingKey::RetentionExportsDays))->toBe(7)->and($settings->get(SettingKey::RankingDecayExponent))->toBe('1');
});
