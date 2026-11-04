<?php

use App\Actions\Notification\Reconcile;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

it('reads each retention window as whole days, defaulting to the documented values', function (SettingKey $key, int $default) {
    // Given a fresh database (no setting rows)
    // When the window is read
    // Then it is the default
    expect(app(Settings::class)->days($key))->toBe($default)->and($key->default())->toBe($default);
})->with([
    'auth events' => [SettingKey::RetentionAuthEventsDays, 180],
    'vote hashes' => [SettingKey::RetentionVoteHashesDays, 90],
    'risk signals' => [SettingKey::RetentionRiskSignalsDays, 365],
    'rank snapshots' => [SettingKey::RetentionRankSnapshotsDays, 180],
    'audit network' => [SettingKey::RetentionAuditNetworkDays, 365],
    'notifications' => [SettingKey::RetentionNotificationsDays, 90],
    'email messages' => [SettingKey::RetentionEmailMessagesDays, 90],
    'exports' => [SettingKey::RetentionExportsDays, 7],
    'reviewed risk signals' => [SettingKey::RetentionRiskSignalsReviewedDays, 90],
]);

it('serves an admin-set value inside the range', function () {
    // Given an admin who sets the rank snapshot window to 60 days
    app(Settings::class)->set(SettingKey::RetentionRankSnapshotsDays, 60, User::factory()->role(Role::Admin)->create());

    // When it is read
    // Then the new value is served
    expect(app(Settings::class)->days(SettingKey::RetentionRankSnapshotsDays))->toBe(60);
});

it('falls back to the default, never to zero, for a corrupt stored window', function (SettingKey $key, mixed $stored) {
    // Given a value that bypassed the write rules (a direct database edit)
    retentionStoreRaw($key, $stored);

    // When it is read as days
    // Then the default is served: a bad value must not mean "purge everything"
    expect(app(Settings::class)->days($key))->toBe($key->default());
})->with(function () {
    $cases = [];
    foreach ([SettingKey::RetentionAuthEventsDays, SettingKey::RetentionVoteHashesDays, SettingKey::RetentionRiskSignalsDays, SettingKey::RetentionRankSnapshotsDays, SettingKey::RetentionAuditNetworkDays, SettingKey::RetentionNotificationsDays, SettingKey::RetentionEmailMessagesDays, SettingKey::RetentionRiskSignalsReviewedDays] as $key) {
        foreach (['zero' => 0, 'negative' => -1, 'one' => 1, 'far above' => 100000, 'float' => 30.5, 'string' => '30', 'junk' => 'abc', 'array' => [30], 'object' => ['a' => 30], 'bool' => true, 'null' => null] as $name => $value) {
            $cases[$key->value.' '.$name] = [$key, $value];
        }
    }

    return $cases;
});

it('bounds the windows at their documented limits', function (SettingKey $key, mixed $value, bool $valid) {
    // Given the key's own rules
    $validator = validator(['value' => $value], ['value' => $key->rules()]);

    // When / Then
    expect($validator->passes())->toBe($valid);
})->with([
    'snapshots low edge' => [SettingKey::RetentionRankSnapshotsDays, 7, true],
    'snapshots high edge' => [SettingKey::RetentionRankSnapshotsDays, 365, true],
    'snapshots below' => [SettingKey::RetentionRankSnapshotsDays, 6, false],
    'snapshots above' => [SettingKey::RetentionRankSnapshotsDays, 366, false],
    'snapshots string' => [SettingKey::RetentionRankSnapshotsDays, 'abc', false],
    'snapshots array' => [SettingKey::RetentionRankSnapshotsDays, [30], false],
    'snapshots null' => [SettingKey::RetentionRankSnapshotsDays, null, false],
    'auth low edge' => [SettingKey::RetentionAuthEventsDays, 7, true],
    'auth high edge' => [SettingKey::RetentionAuthEventsDays, 3650, true],
    'auth below' => [SettingKey::RetentionAuthEventsDays, 6, false],
    'votes above' => [SettingKey::RetentionVoteHashesDays, 3651, false],
    'risk low edge' => [SettingKey::RetentionRiskSignalsDays, 30, true],
    'risk below' => [SettingKey::RetentionRiskSignalsDays, 29, false],
    'audit network low edge' => [SettingKey::RetentionAuditNetworkDays, 30, true],
    'audit network high edge' => [SettingKey::RetentionAuditNetworkDays, 3650, true],
    'audit network below' => [SettingKey::RetentionAuditNetworkDays, 29, false],
    'audit network above' => [SettingKey::RetentionAuditNetworkDays, 3651, false],
    'audit network array' => [SettingKey::RetentionAuditNetworkDays, [365], false],
    'notifications low edge' => [SettingKey::RetentionNotificationsDays, 7, true],
    'notifications high edge' => [SettingKey::RetentionNotificationsDays, 365, true],
    'notifications below' => [SettingKey::RetentionNotificationsDays, 6, false],
    'notifications above' => [SettingKey::RetentionNotificationsDays, 366, false],
    'notifications float' => [SettingKey::RetentionNotificationsDays, 1.5, false],
    'notifications array' => [SettingKey::RetentionNotificationsDays, [90], false],
    'notifications null' => [SettingKey::RetentionNotificationsDays, null, false],
    'email messages low edge' => [SettingKey::RetentionEmailMessagesDays, 30, true],
    'email messages high edge' => [SettingKey::RetentionEmailMessagesDays, 365, true],
    'email messages below' => [SettingKey::RetentionEmailMessagesDays, 29, false],
    'email messages above' => [SettingKey::RetentionEmailMessagesDays, 366, false],
    'email messages float' => [SettingKey::RetentionEmailMessagesDays, 1.5, false],
    'email messages array' => [SettingKey::RetentionEmailMessagesDays, [90], false],
    'email messages null' => [SettingKey::RetentionEmailMessagesDays, null, false],
    'exports low edge' => [SettingKey::RetentionExportsDays, 1, true],
    'exports high edge' => [SettingKey::RetentionExportsDays, 30, true],
    'exports below' => [SettingKey::RetentionExportsDays, 0, false],
    'exports above' => [SettingKey::RetentionExportsDays, 31, false],
    'exports float' => [SettingKey::RetentionExportsDays, 1.5, false],
    'exports array' => [SettingKey::RetentionExportsDays, [7], false],
    'exports object' => [SettingKey::RetentionExportsDays, ['a' => 7], false],
    'exports null' => [SettingKey::RetentionExportsDays, null, false],
    'reviewed signals low edge' => [SettingKey::RetentionRiskSignalsReviewedDays, 7, true],
    'reviewed signals high edge' => [SettingKey::RetentionRiskSignalsReviewedDays, 3650, true],
    'reviewed signals below' => [SettingKey::RetentionRiskSignalsReviewedDays, 6, false],
    'reviewed signals above' => [SettingKey::RetentionRiskSignalsReviewedDays, 3651, false],
    'reviewed signals array' => [SettingKey::RetentionRiskSignalsReviewedDays, [90], false],
    'reviewed signals null' => [SettingKey::RetentionRiskSignalsReviewedDays, null, false],
]);

it('accepts the new key through the admin API, audits it and rejects bad payloads with 422', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the rank snapshot window is set to 45
    $this->patchJson('/api/v1/admin/settings/retention.rank_snapshots_days', ['value' => 45])->assertOk()->assertJsonPath('data.value', 45);

    // Then it is stored and audited, and bad payloads are validation errors
    expect(Setting::query()->findOrFail('retention.rank_snapshots_days')->value)->toBe(45);
    expect(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(1);
    foreach ([['value' => 6], ['value' => 366], ['value' => 'abc'], ['value' => [30]], ['value' => ['a' => 1]], ['value' => null], []] as $payload) {
        $this->patchJson('/api/v1/admin/settings/retention.rank_snapshots_days', $payload)->assertUnprocessable()->assertJsonValidationErrors('value');
    }
    expect(Setting::query()->findOrFail('retention.rank_snapshots_days')->value)->toBe(45);
});

it('accepts the audit network window through the admin API, audits it and rejects bad payloads with 422', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the audit network window is set to 400
    $this->patchJson('/api/v1/admin/settings/retention.audit_network_days', ['value' => 400])->assertOk()->assertJsonPath('data.value', 400);

    // Then it is stored and audited, and bad payloads (including values below the 30 day floor) are validation errors
    expect(Setting::query()->findOrFail('retention.audit_network_days')->value)->toBe(400);
    expect(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(1);
    foreach ([['value' => 29], ['value' => 3651], ['value' => 'abc'], ['value' => true], ['value' => [365]], ['value' => ['a' => 1]], ['value' => null], []] as $payload) {
        $this->patchJson('/api/v1/admin/settings/retention.audit_network_days', $payload)->assertUnprocessable()->assertJsonValidationErrors('value');
    }
    expect(Setting::query()->findOrFail('retention.audit_network_days')->value)->toBe(400);
});

it('lists the audit network key with its default', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When they list settings
    // Then the key is present with value and default 365
    $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonFragment(['key' => 'retention.audit_network_days', 'value' => 365, 'default' => 365]);
});

it('lists the new key with its default', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When they list settings
    // Then the key is present with value and default 180
    $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonFragment(['key' => 'retention.rank_snapshots_days', 'value' => 180, 'default' => 180]);
});

it('indexes rank_snapshots by captured_at and keeps unpurged vote signals in a partial index', function () {
    // Given the migrated schema
    // When the index definitions are read
    $snapshots = DB::table('pg_indexes')->where('indexname', 'rank_snapshots_captured_at_index')->value('indexdef');
    $votes = DB::table('pg_indexes')->where('indexname', 'votes_unpurged_signals_index')->value('indexdef');

    // Then the purge has an index each (the snapshot primary key leads with product_id)
    expect($snapshots)->toContain('USING btree (captured_at)');
    expect($votes)->toContain('USING btree (product_id)')->toContain('ip_hash IS NOT NULL')->toContain('device_hash IS NOT NULL')->toContain('user_agent IS NOT NULL');
});

it('logs a warning with the key only when a stored window is invalid, and none for a good or missing value', function () {
    // Given a good value and a missing row
    Log::spy();
    app(Settings::class)->days(SettingKey::RetentionAuthEventsDays);
    retentionStoreRaw(SettingKey::RetentionVoteHashesDays, 45);
    app(Settings::class)->days(SettingKey::RetentionVoteHashesDays);
    Log::shouldNotHaveReceived('warning');

    // When an out-of-range and a wrongly typed value are read
    retentionStoreRaw(SettingKey::RetentionVoteHashesDays, 0);
    app(Settings::class)->days(SettingKey::RetentionVoteHashesDays);
    retentionStoreRaw(SettingKey::RetentionRankSnapshotsDays, 'secret-junk');
    app(Settings::class)->days(SettingKey::RetentionRankSnapshotsDays);

    // Then each warned with the key and never with the stored value
    Log::shouldHaveReceived('warning')->with('Retention setting is invalid; using the default.', ['key' => 'retention.vote_hashes_days'])->once();
    Log::shouldHaveReceived('warning')->with('Setting has a wrongly typed stored value; using the default.', ['key' => 'retention.rank_snapshots_days'])->once();
});

it('accepts the two notification windows through the admin API, audits them, rejects bad payloads with 422 and refuses non-admins', function (string $key, int $valid, array $bad) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the window is set to a value inside its range
    $this->patchJson('/api/v1/admin/settings/'.$key, ['value' => $valid])->assertOk()->assertJsonPath('data.value', $valid);

    // Then it is stored and audited once, an unchanged value writes nothing more, and bad payloads are validation errors
    expect(Setting::query()->findOrFail($key)->value)->toBe($valid)->and(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(1);
    $this->patchJson('/api/v1/admin/settings/'.$key, ['value' => $valid])->assertOk();
    expect(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(1);
    foreach ([...array_map(fn ($v) => ['value' => $v], $bad), []] as $payload) {
        $this->patchJson('/api/v1/admin/settings/'.$key, $payload)->assertUnprocessable()->assertJsonValidationErrors('value');
    }
    expect(Setting::query()->findOrFail($key)->value)->toBe($valid);

    // And a reviewer is refused before validation
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    $this->patchJson('/api/v1/admin/settings/'.$key, ['value' => 1])->assertForbidden();
})->with([
    'notifications' => ['retention.notifications_days', 120, [6, 366, true, 1.5, [90], ['a' => 1], null, 'abc', -1, 0]],
    'email messages' => ['retention.email_messages_days', 45, [29, 366, true, 1.5, [90], ['a' => 1], null, 'abc', -1, 0]],
    'exports' => ['retention.exports_days', 14, [0, 31, true, 1.5, [7], ['a' => 7], null, 'abc', '7x', -1]],
]);

it('lists the two notification windows with their defaults', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When they list settings
    // Then both keys are present with value and default 90
    $response = $this->getJson('/api/v1/admin/settings')->assertOk();
    $response->assertJsonFragment(['key' => 'retention.notifications_days', 'value' => 90, 'default' => 90]);
    $response->assertJsonFragment(['key' => 'retention.email_messages_days', 'value' => 90, 'default' => 90]);
});

it('keeps the email window floor above the reconcile window plus a day', function () {
    // Given the range of the email window
    // When the floor is compared with the sweep window plus its margin
    // Then it is longer, so a purged sent row can never be re-queued by the sweep
    expect(SettingKey::RetentionEmailMessagesDays->dayRange()[0] * 24)->toBeGreaterThanOrEqual(Reconcile::MIN_EMAIL_RETENTION_HOURS);
});
