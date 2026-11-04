<?php

use App\Actions\Retention\PurgeDigestRuns;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

/** @return list<string> the "kind period" of every remaining run */
function digestRunsLeft(): array
{
    return DB::table('digest_runs')->orderBy('period_date')->orderBy('kind')->get()->map(fn ($r) => $r->kind.' '.$r->period_date)->all();
}

it('purges runs by period date: the day exactly 90 days back is kept, the day before it goes', function () {
    // Given runs of both kinds on the 91st, 90th and 89th day back (today is 2026-10-21, so 90 days back is 2026-07-23)
    retentionDigestRun('2026-07-22');
    retentionDigestRun('2026-07-22', 'weekly_best');
    retentionDigestRun('2026-07-23');
    retentionDigestRun('2026-07-24', 'weekly_best');
    retentionDigestRun('2026-10-20');

    // When the purge runs
    $purged = app(PurgeDigestRuns::class)->purge(retentionNow());

    // Then only the two oldest are gone
    expect($purged)->toBe(2)->and(digestRunsLeft())->toBe(['daily_top5 2026-07-23', 'weekly_best 2026-07-24', 'daily_top5 2026-10-20']);
});

it('is idempotent and follows the email window setting', function () {
    // Given a run 40 days old and the default window of 90 days
    retentionDigestRun('2026-09-11');
    expect(app(PurgeDigestRuns::class)->purge(retentionNow()))->toBe(0)->and(app(PurgeDigestRuns::class)->purge(retentionNow()))->toBe(0);

    // When an admin shortens the email window to 30 days
    app(Settings::class)->set(SettingKey::RetentionEmailMessagesDays, 30, User::factory()->role(Role::Admin)->create());

    // Then the run goes once, and a repeat purges nothing
    expect(app(PurgeDigestRuns::class)->purge(retentionNow()))->toBe(1)->and(app(PurgeDigestRuns::class)->purge(retentionNow()))->toBe(0);
});

it('works through chunks, stops at the cap and continues next run', function () {
    // Given five old runs, a chunk of two and a cap of one chunk
    foreach (range(1, 5) as $i) {
        retentionDigestRun('2026-06-0'.$i);
    }
    $purge = retentionPurger(PurgeDigestRuns::class, 2, 1);

    // When it runs three times
    $first = $purge->purge(retentionNow());
    $capped = $purge->capped;
    $second = $purge->purge(retentionNow());
    $third = $purge->purge(retentionNow());

    // Then 2, 2, then the last one (not capped: the chunk was short)
    expect($first)->toBe(2)->and($capped)->toBeTrue()->and($second)->toBe(2)->and($third)->toBe(1)->and($purge->capped)->toBeFalse()->and(DB::table('digest_runs')->count())->toBe(0);
});

it('falls back to the default window for a corrupt stored value', function (mixed $stored) {
    // Given a stored value that bypassed validation, a run 60 days old and one 120 days old
    retentionStoreRaw(SettingKey::RetentionEmailMessagesDays, $stored);
    retentionDigestRun('2026-08-22');
    retentionDigestRun('2026-06-23');

    // When the purge runs
    app(PurgeDigestRuns::class)->purge(retentionNow());

    // Then the default (90 days) applied
    expect(digestRunsLeft())->toBe(['daily_top5 2026-08-22']);
})->with(['zero' => [0], 'negative' => [-1], 'below the floor' => [5], 'far above' => [10000], 'string' => ['x'], 'null' => [null], 'array' => [[30]]]);

it('keeps the running count when a later chunk fails', function () {
    // Given five old runs and a purge whose second chunk throws
    foreach (range(1, 5) as $i) {
        retentionDigestRun('2026-06-0'.$i);
    }
    $purge = new class(app(Settings::class), 2, 10) extends PurgeDigestRuns
    {
        private int $calls = 0;

        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            if (++$this->calls === 2) {
                DB::select('select 1/0');
            }

            return parent::purgeChunk($now, $limit);
        }
    };

    // When it runs
    // Then the failure surfaces and the first chunk is committed and counted
    expect(fn () => $purge->purge(retentionNow()))->toThrow(QueryException::class);
    expect($purge->purgedSoFar)->toBe(2)->and(DB::table('digest_runs')->count())->toBe(3);
});

it('locks no user or product row and bounds one chunk whatever plan the planner picks', function (array $off) {
    // Given twelve old runs (both kinds), a chunk of three and a pushed planner
    foreach (range(1, 6) as $i) {
        retentionDigestRun('2026-05-0'.$i);
        retentionDigestRun('2026-05-0'.$i, 'weekly_best');
    }
    retentionPlannerOff($off);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    // When one chunk runs
    $purged = retentionPurger(PurgeDigestRuns::class, 3, 1)->purge(retentionNow());

    // Then exactly three rows were deleted, and no other table was touched
    expect($purged)->toBe(3)->and(DB::table('digest_runs')->count())->toBe(9);
    foreach ($sql as $statement) {
        expect($statement)->not->toMatch('/\b(users|products)\b/');
    }
})->with(retentionPlannerModes());
