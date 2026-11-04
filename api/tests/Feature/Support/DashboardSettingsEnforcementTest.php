<?php

use App\Actions\Dashboard\RankHistory;
use App\Actions\Retention\PurgeExports;
use App\Actions\Retention\PurgeRankSnapshots;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Jobs\GenerateExport;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(function () {
    Storage::fake('exports');
    $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC'));
});

it('maps retention.exports_days to code that reads it, seeded at 7 with the range 1 to 30', function () {
    // Given the key and the classes that enforce it (the job that stamps expires_at, and the purge that honours the stamp)
    $key = SettingKey::RetentionExportsDays;

    // When its facts and the enforcing sources are read
    // Then it is in the enum, defaults to 7 satisfying its own rules, ranges 1 to 30, and the job reads it by name
    expect($key->default())->toBe(7)->and($key->dayRange())->toBe([1, 30]);
    expect(validator(['value' => $key->default()], ['value' => $key->rules()])->passes())->toBeTrue();
    expect(file_get_contents((new ReflectionClass(GenerateExport::class))->getFileName()))->toContain('SettingKey::RetentionExportsDays');
    expect(class_exists(PurgeExports::class))->toBeTrue();
});

it('moves a new export\'s expires_at when the setting changes, and the purge honours the stored expiry', function () {
    // Given an admin who shortens the window from 7 to 2 days
    $admin = User::factory()->role(Role::Admin)->create();
    $before = exportFor(Product::factory()->live(DASH_DAY)->create(), 'queued');
    exportGenerate($before);
    app(Settings::class)->set(SettingKey::RetentionExportsDays, 2, $admin);
    $after = exportFor(Product::factory()->live(DASH_DAY)->create(), 'queued');
    exportGenerate($after);

    // When both are read, then the purge runs three days later
    $before->refresh();
    $after->refresh();
    expect((int) round($before->completed_at->diffInDays($before->expires_at, true)))->toBe(7)->and((int) round($after->completed_at->diffInDays($after->expires_at, true)))->toBe(2);
    $purge = retentionPurger(PurgeExports::class, 10, 2);
    $purge->purge(CarbonImmutable::now('UTC')->addDays(3));

    // Then the 2 day export is expired and the 7 day one is not
    expect($after->refresh()->status->value)->toBe('expired')->and($before->refresh()->status->value)->toBe('ready');
});

it('keeps retention.rank_snapshots_days at 180 by default and moves the dashboard\'s timeline boundary with it', function () {
    // Given the default window and a product that launched 20 days ago (inside 180, outside 10)
    expect(SettingKey::RetentionRankSnapshotsDays->default())->toBe(180)->and(app(Settings::class)->days(SettingKey::RetentionRankSnapshotsDays))->toBe(180);
    $day = CarbonImmutable::now('UTC')->subDays(20)->toDateString();
    $product = Product::factory()->live($day)->create();
    $now = CarbonImmutable::now('UTC');
    $available = fn () => app(RankHistory::class)->facts($product->fresh(), $now)['timeline_available'];

    // When the timeline is asked for under the default, then after an admin shortens the window to 10 days
    $underDefault = $available();
    app(Settings::class)->set(SettingKey::RetentionRankSnapshotsDays, 10, User::factory()->role(Role::Admin)->create());
    $underTen = $available();

    // Then the boundary moved, and the purge that enforces the same key is the one named in the enforcement map
    expect($underDefault)->toBeTrue()->and($underTen)->toBeFalse();
    expect(file_get_contents((new ReflectionClass(PurgeRankSnapshots::class))->getFileName()))->toContain('SettingKey::RetentionRankSnapshotsDays');
});
