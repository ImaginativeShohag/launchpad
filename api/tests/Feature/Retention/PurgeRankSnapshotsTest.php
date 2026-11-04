<?php

use App\Actions\Retention\PurgeRankSnapshots;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

function retentionSnapshotTimes(): array
{
    return DB::table('rank_snapshots')->orderBy('captured_at')->pluck('captured_at')->map(fn ($t) => substr((string) $t, 0, 19))->all();
}

it('deletes snapshots captured before the window and keeps the boundary and newer ones', function () {
    // Given one product with snapshots one second older than, exactly at, and inside the 180 day cutoff
    $product = Product::factory()->live()->create();
    $cutoff = retentionNow()->subDays(180);
    retentionSnapshot($product, $cutoff->subSecond());
    retentionSnapshot($product, $cutoff);
    retentionSnapshot($product, $cutoff->addSecond());
    retentionSnapshot($product, retentionNow()->subMinutes(10));

    // When the purge runs
    $purged = app(PurgeRankSnapshots::class)->purge(retentionNow());

    // Then only the older snapshot is gone
    expect($purged)->toBe(1);
    expect(retentionSnapshotTimes())->toBe([
        $cutoff->toDateTimeString(), $cutoff->addSecond()->toDateTimeString(), retentionNow()->subMinutes(10)->toDateTimeString(),
    ]);
});

it('purges by time across all products and is idempotent', function () {
    // Given two products, each with an old and a fresh snapshot
    $a = Product::factory()->live()->create();
    $b = Product::factory()->live()->create();
    foreach ([$a, $b] as $product) {
        retentionSnapshot($product, retentionNow()->subDays(200));
        retentionSnapshot($product, retentionNow()->subDays(2));
    }

    // When the purge runs twice
    $first = app(PurgeRankSnapshots::class)->purge(retentionNow());
    $second = app(PurgeRankSnapshots::class)->purge(retentionNow());

    // Then both old rows went on the first run and nothing changed on the second; products are untouched
    expect($first)->toBe(2)->and($second)->toBe(0)->and(DB::table('rank_snapshots')->count())->toBe(2)->and(Product::query()->count())->toBe(2);
});

it('moves the cutoff when the setting changes', function () {
    // Given a snapshot 20 days old and the default window of 180 days
    $product = Product::factory()->live()->create();
    retentionSnapshot($product, retentionNow()->subDays(20));
    expect(app(PurgeRankSnapshots::class)->purge(retentionNow()))->toBe(0);

    // When an admin shortens the window to the minimum of 7 days
    app(Settings::class)->set(SettingKey::RetentionRankSnapshotsDays, 7, User::factory()->role(Role::Admin)->create());

    // Then the snapshot is now too old
    expect(app(PurgeRankSnapshots::class)->purge(retentionNow()))->toBe(1)->and(DB::table('rank_snapshots')->count())->toBe(0);
});

it('works through chunks and honours the per-run cap', function () {
    // Given seven old snapshots (distinct marks) and one fresh one
    $product = Product::factory()->live()->create();
    foreach (range(1, 7) as $i) {
        retentionSnapshot($product, retentionNow()->subDays(200)->addMinutes(10 * $i));
    }
    retentionSnapshot($product, retentionNow());
    $purge = retentionPurger(PurgeRankSnapshots::class, 2, 2);

    // When it runs twice with a cap of two chunks of two
    $first = $purge->purge(retentionNow());
    $firstCapped = $purge->capped;
    $second = $purge->purge(retentionNow());

    // Then the first run did four and flagged the cap, the second finished the other three, the fresh row stays
    expect($first)->toBe(4)->and($firstCapped)->toBeTrue()->and($second)->toBe(3)->and($purge->capped)->toBeFalse();
    expect(retentionSnapshotTimes())->toBe([retentionNow()->toDateTimeString()]);
});

it('uses the captured_at index for the select that picks the rows', function () {
    // Given an analysed table of snapshots over many days and the SQL the purge really runs (captured)
    $product = Product::factory()->live()->create();
    foreach (range(1, 60) as $i) {
        retentionSnapshot($product, retentionNow()->subDays(190 + $i));
    }
    DB::statement('ANALYZE rank_snapshots');
    $captured = retentionCaptureSql(fn () => app(PurgeRankSnapshots::class)->purge(retentionNow()), 'WITH picked');
    $sql = $captured[0] ?? '';

    // When its plan is read with scans and sorts that avoid indexes disabled
    $plan = retentionPlan($sql);

    // Then the picked-rows CTE is served by rank_snapshots_captured_at_index
    expect($sql)->toContain('AS MATERIALIZED')->toContain('FOR UPDATE SKIP LOCKED')->toContain('ORDER BY captured_at');
    expect($plan)->toContain('rank_snapshots_captured_at_index')->not->toContain('Seq Scan on rank_snapshots');
});

it('never reaches SQL with a corrupt setting value and falls back to the default window, not zero', function (mixed $stored) {
    // Given a stored value that bypassed validation, a snapshot 20 days old (inside the default 180) and one 40 days old
    retentionStoreRaw(SettingKey::RetentionRankSnapshotsDays, $stored);
    $product = Product::factory()->live()->create();
    retentionSnapshot($product, retentionNow()->subDays(20));
    retentionSnapshot($product, retentionNow()->subDays(200));
    $bindings = [];
    DB::listen(function ($query) use (&$bindings) {
        array_push($bindings, ...$query->bindings);
    });

    // When the purge runs
    app(PurgeRankSnapshots::class)->purge(retentionNow());

    // Then the default (180 days) applied and nothing but scalars was bound
    expect(retentionSnapshotTimes())->toBe([retentionNow()->subDays(20)->toDateTimeString()]);
    foreach ($bindings as $binding) {
        expect($binding)->not->toBeArray()->and($binding)->not->toBeObject()->and((string) $binding)->not->toContain('junk');
    }
})->with([
    'zero' => [0], 'negative' => [-1], 'below the floor' => [6], 'above the ceiling' => [366], 'float' => [7.5], 'bool' => [false],
    'garbage string' => ['junk'], 'array' => [['junk']], 'object' => [['junk' => 1]],
]);

it('touches at most one chunk of rows whatever plan the planner picks', function (array $off) {
    // Given twelve eligible old snapshots, a chunk of three, and a planner pushed towards a given join strategy
    $product = Product::factory()->live()->create();
    foreach (range(1, 12) as $i) {
        retentionSnapshot($product, retentionNow()->subDays(200)->addMinutes(10 * $i));
    }
    DB::statement('ANALYZE rank_snapshots');
    retentionPlannerOff($off);

    // When one chunk runs
    $purged = retentionPurger(PurgeRankSnapshots::class, 3, 1)->purge(retentionNow());

    // Then exactly three rows were deleted and nine remain
    expect($purged)->toBe(3)->and(DB::table('rank_snapshots')->count())->toBe(9);
})->with(retentionPlannerModes());
