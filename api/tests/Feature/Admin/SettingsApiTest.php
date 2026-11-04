<?php

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

it('lists every setting with its value and default', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When they list settings
    $response = $this->getJson('/api/v1/admin/settings');

    // Then every key is there with value and default
    $response->assertOk()->assertJsonCount(count(SettingKey::cases()), 'data')
        ->assertJsonFragment(['key' => 'launch.max_per_maker_per_day', 'value' => 2, 'default' => 2])
        ->assertJsonFragment(['key' => 'product.max_pending_per_maker', 'value' => 5, 'default' => 5]);
});

it('changes a setting, serves the new value, records updated_by and audits before and after', function () {
    // Given an admin and a warm cache
    $admin = User::factory()->role(Role::Admin)->create();
    Sanctum::actingAs($admin);
    expect(app(Settings::class)->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(2);

    // When they raise the per-day launch cap
    $response = $this->patchJson('/api/v1/admin/settings/launch.max_per_maker_per_day', ['value' => 3]);

    // Then the new value is stored, served fresh, attributed, and audited
    $response->assertOk()->assertJsonPath('data.value', 3);
    expect(app(Settings::class)->get(SettingKey::LaunchMaxPerMakerPerDay))->toBe(3);
    expect(Setting::query()->findOrFail('launch.max_per_maker_per_day')->updated_by)->toBe($admin->id);
    $log = AuditLog::query()->where('action', 'setting.updated')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id);
    expect($log->meta)->toMatchArray(['key' => 'launch.max_per_maker_per_day', 'before' => 2, 'after' => 3]);
});

it('stores a numeric string for an integer setting as a number', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the value arrives as "5"
    $this->patchJson('/api/v1/admin/settings/product.max_screenshots', ['value' => '5'])->assertOk();

    // Then it is stored as the integer 5
    expect(app(Settings::class)->get(SettingKey::ProductMaxScreenshots))->toBe(5);
    expect(Setting::query()->findOrFail('product.max_screenshots')->value)->toBe(5);
});

it('changes a time setting', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the digest time changes
    $this->patchJson('/api/v1/admin/settings/digest.daily_send_at_utc', ['value' => '09:30'])->assertOk();

    // Then the new time is served
    expect(app(Settings::class)->get(SettingKey::DigestDailySendAtUtc))->toBe('09:30');
});

it('rejects out-of-range, wrongly typed and unknown settings', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the value is out of range, malformed, or the key is unknown
    $range = $this->patchJson('/api/v1/admin/settings/launch.max_per_maker_per_day', ['value' => 99]);
    $time = $this->patchJson('/api/v1/admin/settings/digest.daily_send_at_utc', ['value' => '7am']);
    $unknown = $this->patchJson('/api/v1/admin/settings/made.up.key', ['value' => 1]);

    // Then each is refused and nothing is audited
    $range->assertUnprocessable()->assertJsonValidationErrors('value');
    $time->assertUnprocessable()->assertJsonValidationErrors('value');
    $unknown->assertNotFound();
    expect(AuditLog::query()->count())->toBe(0);
    expect(Setting::query()->count())->toBe(0);
});

it('rejects array, object, null, boolean and float values with 422', function (string $key, array $payload) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the value has the wrong shape
    $response = $this->patchJson('/api/v1/admin/settings/'.$key, $payload);

    // Then it is a validation error, never a 500, and nothing is stored
    $response->assertUnprocessable()->assertJsonValidationErrors('value');
    expect(Setting::query()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    'int key array' => ['launch.max_per_maker_per_day', ['value' => [3]]],
    'int key object' => ['launch.max_per_maker_per_day', ['value' => ['a' => 3]]],
    'int key null' => ['launch.max_per_maker_per_day', ['value' => null]],
    'int key empty' => ['launch.max_per_maker_per_day', ['value' => '']],
    'int key missing' => ['launch.max_per_maker_per_day', []],
    'int key true' => ['launch.max_per_maker_per_day', ['value' => true]],
    'int key float' => ['launch.max_per_maker_per_day', ['value' => 3.5]],
    'int key padded' => ['launch.max_per_maker_per_day', ['value' => ' 3 x']],
    'time key array' => ['digest.daily_send_at_utc', ['value' => ['07:00']]],
    'time key object' => ['digest.daily_send_at_utc', ['value' => ['a' => '07:00']]],
    'time key null' => ['digest.daily_send_at_utc', ['value' => null]],
    'time key int' => ['digest.daily_send_at_utc', ['value' => 700]],
    'time key bool' => ['digest.daily_send_at_utc', ['value' => true]],
    'daily time 22:00' => ['digest.daily_send_at_utc', ['value' => '22:00']],
    'weekly time 23:30' => ['digest.weekly_send_at_utc', ['value' => '23:30']],
    'notifications days array' => ['retention.notifications_days', ['value' => [90]]],
    'notifications days object' => ['retention.notifications_days', ['value' => ['a' => 90]]],
    'notifications days null' => ['retention.notifications_days', ['value' => null]],
    'notifications days below' => ['retention.notifications_days', ['value' => 6]],
    'email days junk string' => ['retention.email_messages_days', ['value' => '9x']],
    'email days below the floor' => ['retention.email_messages_days', ['value' => 29]],
    'email days bool' => ['retention.email_messages_days', ['value' => true]],
]);

it('does not audit or store an unchanged value', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the default value is submitted
    $this->patchJson('/api/v1/admin/settings/launch.max_per_maker_per_day', ['value' => 2])->assertOk();

    // Then nothing is written
    expect(AuditLog::query()->count())->toBe(0);
    expect(Setting::query()->count())->toBe(0);
});

it('keeps settings away from non-admins', function () {
    // Given a reviewer
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());

    // When they read or change settings (even with an invalid value or an unknown key)
    // Then they are refused before validation
    $this->getJson('/api/v1/admin/settings')->assertForbidden();
    $this->patchJson('/api/v1/admin/settings/launch.max_per_maker_per_day', ['value' => 99])->assertForbidden();
    $this->patchJson('/api/v1/admin/settings/made.up.key', ['value' => 1])->assertForbidden();
});

it('requires sign-in, an active account and a confirmed email', function () {
    // Given no one is signed in
    $this->getJson('/api/v1/admin/settings')->assertUnauthorized();
    $this->patchJson('/api/v1/admin/settings/product.max_topics', ['value' => 4])->assertUnauthorized();

    // Given a suspended admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    $this->patchJson('/api/v1/admin/settings/product.max_topics', ['value' => 4])
        ->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    // Given an unconfirmed admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    $this->patchJson('/api/v1/admin/settings/product.max_topics', ['value' => 4])
        ->assertForbidden()->assertJsonPath('code', 'email_unverified');
    expect(Cache::get('settings:product.max_topics'))->toBeNull();
});
