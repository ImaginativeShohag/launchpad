<?php

use App\Actions\Retention\PurgeAuthEvents;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

it('deletes auth events older than the window and keeps the boundary and newer rows', function () {
    // Given rows one second older than, exactly at, and one second inside the 180 day cutoff, plus a fresh one
    $cutoff = retentionNow()->subDays(180);
    $old = retentionAuthEvent($cutoff->subSecond());
    $atBoundary = retentionAuthEvent($cutoff);
    $inside = retentionAuthEvent($cutoff->addSecond());
    $fresh = retentionAuthEvent(retentionNow()->subMinute());

    // When the purge runs
    $purged = app(PurgeAuthEvents::class)->purge(retentionNow());

    // Then only the older row is gone
    expect($purged)->toBe(1);
    expect(DB::table('auth_events')->pluck('id')->sort()->values()->all())->toBe(collect([$atBoundary, $inside, $fresh])->sort()->values()->all());
    expect(DB::table('auth_events')->where('id', $old)->exists())->toBeFalse();
});

it('is idempotent: a second run deletes nothing', function () {
    // Given one old and one fresh row, purged once
    retentionAuthEvent(retentionNow()->subDays(400));
    $fresh = retentionAuthEvent(retentionNow()->subDay());
    expect(app(PurgeAuthEvents::class)->purge(retentionNow()))->toBe(1);

    // When it runs again
    $second = app(PurgeAuthEvents::class)->purge(retentionNow());

    // Then nothing more is deleted
    expect($second)->toBe(0)->and(DB::table('auth_events')->pluck('id')->all())->toBe([$fresh]);
});

it('keeps rows that reference a user, because the user stays and only old events go', function () {
    // Given an old event of a real user and a fresh one
    $user = User::factory()->create();
    retentionAuthEvent(retentionNow()->subDays(500), ['user_id' => $user->id]);
    retentionAuthEvent(retentionNow()->subDay(), ['user_id' => $user->id]);

    // When the purge runs
    app(PurgeAuthEvents::class)->purge(retentionNow());

    // Then the user and the fresh event remain
    expect(DB::table('auth_events')->count())->toBe(1)->and(User::query()->count())->toBe(1);
});

it('moves the cutoff when the setting changes', function () {
    // Given an event 40 days old and the default window of 180 days
    $id = retentionAuthEvent(retentionNow()->subDays(40));
    expect(app(PurgeAuthEvents::class)->purge(retentionNow()))->toBe(0);

    // When an admin shortens the window to 30 days
    app(Settings::class)->set(SettingKey::RetentionAuthEventsDays, 30, User::factory()->role(Role::Admin)->create());

    // Then the same event is now old enough
    expect(app(PurgeAuthEvents::class)->purge(retentionNow()))->toBe(1)->and(DB::table('auth_events')->where('id', $id)->exists())->toBeFalse();
});

it('works through more than one chunk and ends cleanly when the last chunk is short', function () {
    // Given seven old rows and a chunk of two
    foreach (range(1, 7) as $i) {
        retentionAuthEvent(retentionNow()->subDays(300 + $i));
    }
    $keep = retentionAuthEvent(retentionNow()->subDay());
    $purge = retentionPurger(PurgeAuthEvents::class, 2, 10);

    // When it runs
    $purged = $purge->purge(retentionNow());

    // Then all seven are gone, the cap was not hit, and the fresh row stays
    expect($purged)->toBe(7)->and($purge->capped)->toBeFalse()->and(DB::table('auth_events')->pluck('id')->all())->toBe([$keep]);
});

it('stops at the chunk cap, leaves the rest for the next run and says so', function () {
    // Given seven old rows, a chunk of two and a cap of two chunks
    foreach (range(1, 7) as $i) {
        retentionAuthEvent(retentionNow()->subDays(300 + $i));
    }
    $purge = retentionPurger(PurgeAuthEvents::class, 2, 2);

    // When it runs twice
    $first = $purge->purge(retentionNow());
    $remaining = DB::table('auth_events')->count();
    $firstCapped = $purge->capped;
    $second = $purge->purge(retentionNow());

    // Then the first run did four rows and flagged the cap, the second finished the other three
    expect($first)->toBe(4)->and($firstCapped)->toBeTrue()->and($remaining)->toBe(3);
    expect($second)->toBe(3)->and($purge->capped)->toBeFalse()->and(DB::table('auth_events')->count())->toBe(0);
});

it('deletes oldest first, so a capped run never skips ahead', function () {
    // Given four old rows of different ages and a run that can only do two
    $oldest = retentionAuthEvent(retentionNow()->subDays(900));
    $older = retentionAuthEvent(retentionNow()->subDays(800));
    $newer = retentionAuthEvent(retentionNow()->subDays(300));
    $newest = retentionAuthEvent(retentionNow()->subDays(200));

    // When a run of one chunk of two executes
    retentionPurger(PurgeAuthEvents::class, 2, 1)->purge(retentionNow());

    // Then the two oldest are the ones that went
    expect(DB::table('auth_events')->pluck('id')->sort()->values()->all())->toBe(collect([$newer, $newest])->sort()->values()->all());
    expect(DB::table('auth_events')->whereIn('id', [$oldest, $older])->exists())->toBeFalse();
});

it('has no immutability trigger to bypass and no foreign key pointing at it', function () {
    // Given the migrated schema
    // When triggers on the table and foreign keys referencing it are read
    $triggers = DB::select("select tgname from pg_trigger where tgrelid = 'auth_events'::regclass and not tgisinternal");
    $references = DB::select("select conname from pg_constraint where contype = 'f' and confrelid = 'auth_events'::regclass");

    // Then there are none: a plain delete is correct (if a guard is ever added, this test says the purge needs a bypass)
    expect($triggers)->toBe([])->and($references)->toBe([]);
});

it('uses the created_at index for the select that picks the rows', function () {
    // Given a table with rows, analysed, and the SQL the purge really runs (captured)
    foreach (range(1, 50) as $i) {
        retentionAuthEvent(retentionNow()->subDays(100 + $i * 10));
    }
    DB::statement('ANALYZE auth_events');
    $captured = retentionCaptureSql(fn () => app(PurgeAuthEvents::class)->purge(retentionNow()), 'WITH picked');
    $sql = $captured[0] ?? '';

    // When its plan is read with scans and sorts that avoid indexes disabled
    $plan = retentionPlan($sql);

    // Then the picked-rows CTE is served by auth_events_created_at_index (no sort of the table, no seq scan)
    expect($sql)->toContain('AS MATERIALIZED')->toContain('FOR UPDATE SKIP LOCKED')->toContain('ORDER BY created_at');
    expect($plan)->toContain('auth_events_created_at_index')->not->toContain('Seq Scan on auth_events');
});

it('never reaches SQL with a corrupt setting value and falls back to the default window, not zero', function (mixed $stored) {
    // Given a stored value that bypassed validation, a row 100 days old (inside the default 180) and one 200 days old
    retentionStoreRaw(SettingKey::RetentionAuthEventsDays, $stored);
    $inside = retentionAuthEvent(retentionNow()->subDays(100));
    $outside = retentionAuthEvent(retentionNow()->subDays(200));
    $bindings = [];
    DB::listen(function ($query) use (&$bindings) {
        array_push($bindings, ...$query->bindings);
    });

    // When the purge runs
    app(PurgeAuthEvents::class)->purge(retentionNow());

    // Then the default (180 days) applied: the 100 day row survives, the 200 day row is gone, and no array or junk was bound
    expect(DB::table('auth_events')->pluck('id')->all())->toBe([$inside])->and(DB::table('auth_events')->where('id', $outside)->exists())->toBeFalse();
    foreach ($bindings as $binding) {
        expect($binding)->not->toBeArray()->and($binding)->not->toBeObject()->and((string) $binding)->not->toContain('junk');
    }
})->with([
    'zero' => [0], 'negative' => [-5], 'below the floor' => [3], 'above the ceiling' => [999999], 'float' => [1.5], 'bool' => [true],
    'numeric string' => ['30'], 'garbage string' => ['junk'], 'null' => [null], 'array' => [['junk']], 'object' => [['junk' => 1]], 'empty array' => [[]],
]);

it('touches at most one chunk of rows whatever plan the planner picks', function (array $off) {
    // Given twelve eligible old events, a chunk of three, and a planner pushed towards a given join strategy
    foreach (range(1, 12) as $i) {
        retentionAuthEvent(retentionNow()->subDays(300 + $i));
    }
    DB::statement('ANALYZE auth_events');
    retentionPlannerOff($off);

    // When one chunk runs
    $purged = retentionPurger(PurgeAuthEvents::class, 3, 1)->purge(retentionNow());

    // Then exactly three rows were deleted and nine remain
    expect($purged)->toBe(3)->and(DB::table('auth_events')->count())->toBe(9);
})->with(retentionPlannerModes());
