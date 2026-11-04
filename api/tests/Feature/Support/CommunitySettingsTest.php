<?php

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

it('seeds community.comment_edit_minutes as the integer 15', function () {
    // Given an empty settings table
    // When the seeder runs
    $this->seed(SettingSeeder::class);

    // Then the key exists with the default 15 as a JSON integer
    expect(Setting::query()->find('community.comment_edit_minutes')->value)->toBe(15);
    expect(SettingKey::CommunityCommentEditMinutes->default())->toBe(15)->and(SettingKey::CommunityCommentEditMinutes->intRange())->toBe([0, 1440]);
});

it('throws for intRange on any other key', function () {
    // Given a key that is not an integer-range setting
    // When its range is asked for
    // Then it is a programming error
    expect(fn () => SettingKey::LaunchMaxDaysAhead->intRange())->toThrow(LogicException::class);
});

it('bounds the edit window at 0 and 1440 through the key rules', function (mixed $value, bool $valid) {
    // Given the key's own rules
    $validator = validator(['value' => $value], ['value' => SettingKey::CommunityCommentEditMinutes->rules()]);

    // When / Then
    expect($validator->passes())->toBe($valid);
})->with([
    'zero' => [0, true], 'max' => [1440, true], 'mid' => [15, true],
    'below' => [-1, false], 'above' => [1441, false], 'string' => ['abc', false], 'array' => [[15], false], 'null' => [null, false],
]);

it('reads the stored integer through Settings::integer', function () {
    // Given an admin who sets the window to 60
    app(Settings::class)->set(SettingKey::CommunityCommentEditMinutes, 60, User::factory()->role(Role::Admin)->create());

    // When / Then
    expect(app(Settings::class)->integer(SettingKey::CommunityCommentEditMinutes))->toBe(60);
});

it('serves the default for a fresh database and accepts 0 as a real value', function () {
    // Given no stored row
    // When read, then after an admin stored 0
    expect(app(Settings::class)->integer(SettingKey::CommunityCommentEditMinutes))->toBe(15);
    app(Settings::class)->set(SettingKey::CommunityCommentEditMinutes, 0, User::factory()->role(Role::Admin)->create());

    // Then 0 ("no edits") is served, not replaced by the default
    expect(app(Settings::class)->integer(SettingKey::CommunityCommentEditMinutes))->toBe(0);
});

it('falls back to the default and logs the key only for a corrupt stored value', function (mixed $stored) {
    // Given a value that bypassed the write rules (a direct database edit)
    DB::table('settings')->updateOrInsert(['key' => 'community.comment_edit_minutes'], ['value' => json_encode($stored), 'updated_at' => now()]);
    Cache::flush();
    Log::spy();

    // When it is read
    $value = app(Settings::class)->integer(SettingKey::CommunityCommentEditMinutes);

    // Then the default is served and a warning names the key but not the value
    expect($value)->toBe(15);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $context === ['key' => 'community.comment_edit_minutes'])->atLeast()->once();
})->with([
    'negative' => [-5], 'far above' => [99999], 'just above' => [1441], 'string' => ['x'], 'float' => [15.5], 'bool' => [true], 'array' => [[15]], 'object' => [['a' => 15]],
]);

it('lets an admin set 0 and 1440 and refuses invalid shapes with 422', function (mixed $value, int $status) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the value is sent
    $response = $this->patchJson('/api/v1/admin/settings/community.comment_edit_minutes', ['value' => $value]);

    // Then it is stored or refused with a validation error, never a 500
    $response->assertStatus($status);
    $status === 422 ? $response->assertJsonValidationErrors('value') : $response->assertOk();
})->with([
    'zero' => [0, 200], 'max' => [1440, 200], 'minus one' => [-1, 422], 'above' => [1441, 422], 'digit string' => ['15', 200], 'junk string' => ['abc', 422],
    'true' => [true, 422], 'float' => [1.5, 422], 'array' => [[15], 422], 'object' => [['a' => 1], 422], 'null' => [null, 422],
]);

it('refuses the same values from a non-admin with 403 before validating', function (mixed $value) {
    // Given a reviewer
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When any value is sent
    // Then it is 403
    $this->patchJson('/api/v1/admin/settings/community.comment_edit_minutes', ['value' => $value])->assertForbidden();
})->with([[0], [-1], [[15]], [null]]);

it('seeds community.report_open_cap as the integer 20 with the range 1 to 200', function () {
    // Given an empty settings table
    // When the seeder runs
    $this->seed(SettingSeeder::class);

    // Then the key exists with the default 20 as a JSON integer
    expect(Setting::query()->find('community.report_open_cap')->value)->toBe(20);
    expect(SettingKey::CommunityReportOpenCap->default())->toBe(20)->and(SettingKey::CommunityReportOpenCap->intRange())->toBe([1, 200]);
});

it('bounds the report cap at 1 and 200 through the key rules', function (mixed $value, bool $valid) {
    // Given the key's own rules
    $validator = validator(['value' => $value], ['value' => SettingKey::CommunityReportOpenCap->rules()]);

    // When / Then
    expect($validator->passes())->toBe($valid);
})->with([
    'one' => [1, true], 'max' => [200, true], 'mid' => [20, true],
    'zero' => [0, false], 'above' => [201, false], 'string' => ['abc', false], 'array' => [[20], false], 'null' => [null, false],
]);

it('falls back to 20 and logs the key only for a corrupt stored report cap', function (mixed $stored) {
    // Given a value that bypassed the write rules (a direct database edit)
    DB::table('settings')->updateOrInsert(['key' => 'community.report_open_cap'], ['value' => json_encode($stored), 'updated_at' => now()]);
    Cache::flush();
    Log::spy();

    // When it is read
    $value = app(Settings::class)->integer(SettingKey::CommunityReportOpenCap);

    // Then the default is served (never 0, which would block every report) and a warning names the key only
    expect($value)->toBe(20);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $context === ['key' => 'community.report_open_cap'])->atLeast()->once();
})->with([
    'zero' => [0], 'negative' => [-5], 'far above' => [99999], 'just above' => [201], 'string' => ['x'], 'float' => [20.5], 'bool' => [true], 'array' => [[20]], 'object' => [['a' => 20]],
]);

it('lets an admin set the report cap to 1 and 200 and refuses invalid shapes with 422', function (mixed $value, int $status) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the value is sent
    $response = $this->patchJson('/api/v1/admin/settings/community.report_open_cap', ['value' => $value]);

    // Then it is stored or refused with a validation error, never a 500
    $response->assertStatus($status);
    $status === 422 ? $response->assertJsonValidationErrors('value') : $response->assertOk();
})->with([
    'one' => [1, 200], 'max' => [200, 200], 'zero' => [0, 422], 'above' => [201, 422], 'digit string' => ['20', 200], 'junk string' => ['abc', 422],
    'true' => [true, 422], 'float' => [1.5, 422], 'array' => [[20], 422], 'object' => [['a' => 1], 422], 'null' => [null, 422],
]);

it('refuses the same report cap values from a non-admin with 403 before validating', function (mixed $value) {
    // Given a reviewer
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When any value is sent
    // Then it is 403
    $this->patchJson('/api/v1/admin/settings/community.report_open_cap', ['value' => $value])->assertForbidden();
})->with([[1], [0], [[20]], [null]]);
