<?php

use App\Actions\AntiCheat\DetectAbuse;
use App\Actions\Retention\PurgeRiskSignals;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\RiskSignal;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

it('keeps an unreviewed signal exactly 365 days old and deletes one a second older', function () {
    // Given unreviewed signals 364 days old, exactly 365, and one second past
    $inside = antiCheatPurgeSignal(364);
    $edge = antiCheatPurgeSignal(365);
    $past = antiCheatPurgeSignal(365 + 1 / 86400);

    // When the purge runs
    $purged = app(PurgeRiskSignals::class)->purge(retentionNow());

    // Then only the older one went
    expect($purged)->toBe(1)->and(antiCheatPurgeSurvivors([$inside, $edge, $past]))->toBe([$inside, $edge]);
});

it('keeps a reviewed signal reviewed exactly 90 days ago and deletes one reviewed a second earlier', function (RiskOutcome $outcome) {
    // Given signals detected recently and reviewed 89 days ago, exactly 90, and one second past
    $inside = antiCheatPurgeSignal(100, $outcome, 89);
    $edge = antiCheatPurgeSignal(100, $outcome, 90);
    $past = antiCheatPurgeSignal(100, $outcome, 90 + 1 / 86400);

    // When the purge runs
    $purged = app(PurgeRiskSignals::class)->purge(retentionNow());

    // Then the one past the review window went
    expect($purged)->toBe(1)->and(antiCheatPurgeSurvivors([$inside, $edge, $past]))->toBe([$inside, $edge]);
})->with([[RiskOutcome::Confirmed], [RiskOutcome::Dismissed]]);

it('applies the 365 day cap to a signal reviewed yesterday, and the review window to one detected recently', function () {
    // Given a signal detected 366 days ago but reviewed yesterday, one reviewed 91 days ago but detected 10 days ago, and a fresh reviewed one
    $capped = antiCheatPurgeSignal(366, RiskOutcome::Confirmed, 1);
    $oldReview = antiCheatPurgeSignal(10 + 0, RiskOutcome::Confirmed, 91);
    $fresh = antiCheatPurgeSignal(10, RiskOutcome::Confirmed, 1);

    // When the purge runs
    $purged = app(PurgeRiskSignals::class)->purge(retentionNow());

    // Then the first two are gone
    expect($purged)->toBe(2)->and(antiCheatPurgeSurvivors([$capped, $oldReview, $fresh]))->toBe([$fresh]);
});

it('keeps the reviewed rows of the once-per-account kinds for ever, whatever the outcome, and purges everything else past its window', function () {
    // Given dismissed and confirmed rows of the three once-per-account kinds long past every window, another dismissed kind, and unreviewed rows
    $kept = collect([RiskSignalKind::FreshAccount, RiskSignalKind::DisposableEmail, RiskSignalKind::SingleMakerVoter])
        ->flatMap(fn (RiskSignalKind $kind) => [antiCheatPurgeSignal(800, RiskOutcome::Dismissed, 700, $kind), antiCheatPurgeSignal(800, RiskOutcome::Confirmed, 700, $kind)])->all();
    $otherKind = antiCheatPurgeSignal(800, RiskOutcome::Dismissed, 700, RiskSignalKind::SharedDevice);
    $otherConfirmed = antiCheatPurgeSignal(800, RiskOutcome::Confirmed, 700, RiskSignalKind::SharedDevice);
    $unreviewed = antiCheatPurgeSignal(800, null, null, RiskSignalKind::DisposableEmail);

    // When the purge runs
    $purged = app(PurgeRiskSignals::class)->purge(retentionNow());

    // Then only the once-per-account reviewed rows remain (an unreviewed one still goes at 365 days)
    expect($purged)->toBe(3)->and(antiCheatPurgeSurvivors([...$kept, $otherKind, $otherConfirmed, $unreviewed]))->toBe($kept);
});

it('states every predicate again in the locking step', function () {
    // Given the SQL the purge really runs
    antiCheatPurgeSignal(400);
    $sql = retentionCaptureSql(fn () => app(PurgeRiskSignals::class)->purge(retentionNow()), 'WITH due')[0] ?? '';

    // When the locking step is cut out
    $picked = substr($sql, (int) strpos($sql, 'picked AS MATERIALIZED'));

    // Then it repeats the unreviewed, reviewed-at, detected-at and keep predicates
    expect($picked)->toContain('s.reviewed_at IS NULL AND s.detected_at < ')->toContain('s.reviewed_at < ')->toContain('s.detected_at < ')->toContain("s.kind NOT IN ('fresh_account', 'disposable_email', 'single_maker_voter')");
});

it('does not resurrect a dismissed once-per-account signal when detection runs after the purge', function () {
    // Given a throwaway-address member whose signal was dismissed long ago, and a vote today (the purge ran in between)
    antiCheatFreeze();
    $member = User::factory()->create(['email' => 'x'.strtolower((string) Str::ulid()).'@mailinator.com', 'created_at' => antiCheatNow()->subDays(400)]);
    antiCheatVote($member, antiCheatLiveProduct());
    antiCheatDetect();
    $signal = RiskSignal::query()->where('kind', 'disposable_email')->where('user_id', $member->id)->firstOrFail();
    DB::table('risk_signals')->where('id', $signal->id)->update([
        'reviewed_by' => User::factory()->role(Role::Reviewer)->create()->id, 'reviewed_at' => antiCheatNow()->subDays(200), 'outcome' => 'dismissed', 'detected_at' => antiCheatNow()->subDays(300),
    ]);

    // When the purge runs and detection runs again
    app(PurgeRiskSignals::class)->purge(antiCheatNow());
    antiCheatDetect();

    // Then the dismissed row is still the only disposable_email signal, still dismissed
    $rows = DB::table('risk_signals')->where('kind', 'disposable_email')->where('user_id', $member->id)->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->outcome)->toBe('dismissed');
});

it('moves both windows with the settings', function () {
    // Given an unreviewed signal 150 days old and a reviewed one reviewed 40 days ago (both inside the defaults)
    $unreviewed = antiCheatPurgeSignal(150);
    $reviewed = antiCheatPurgeSignal(60, RiskOutcome::Confirmed, 40);
    expect(app(PurgeRiskSignals::class)->purge(retentionNow()))->toBe(0);
    $admin = User::factory()->role(Role::Admin)->create();

    // When an admin shortens the unreviewed window to 100 days and the reviewed one to 30
    app(Settings::class)->set(SettingKey::RetentionRiskSignalsDays, 100, $admin);
    app(Settings::class)->set(SettingKey::RetentionRiskSignalsReviewedDays, 30, $admin);

    // Then both are now old enough
    expect(app(PurgeRiskSignals::class)->purge(retentionNow()))->toBe(2)->and(antiCheatPurgeSurvivors([$unreviewed, $reviewed]))->toBe([]);
});

it('falls back to the default windows for a corrupt stored value, never to zero, and logs the key only', function (mixed $stored) {
    // Given a stored value that bypassed validation for both windows, a signal inside the defaults and one outside
    Log::spy();
    retentionStoreRaw(SettingKey::RetentionRiskSignalsDays, $stored);
    retentionStoreRaw(SettingKey::RetentionRiskSignalsReviewedDays, $stored);
    $inside = antiCheatPurgeSignal(200);
    $insideReviewed = antiCheatPurgeSignal(50, RiskOutcome::Confirmed, 50);
    $outside = antiCheatPurgeSignal(400);
    $bindings = [];
    DB::listen(function ($query) use (&$bindings) {
        array_push($bindings, ...$query->bindings);
    });

    // When the purge runs
    app(PurgeRiskSignals::class)->purge(retentionNow());

    // Then the defaults applied and no junk reached SQL
    expect(antiCheatPurgeSurvivors([$inside, $insideReviewed, $outside]))->toBe([$inside, $insideReviewed]);
    foreach ($bindings as $binding) {
        expect($binding)->not->toBeArray()->and($binding)->not->toBeObject();
    }
    // (a JSON null reads as "not set" and is served silently; every other bad value logs a warning that names the key and nothing else)
    if ($stored !== null) {
        foreach (['retention.risk_signals_days', 'retention.risk_signals_reviewed_days'] as $key) {
            Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context === ['key' => $key])->atLeast()->once();
        }
    }
})->with([
    'zero' => [0], 'negative' => [-1], 'one' => [1], 'above the ceiling' => [100000], 'float' => [30.5], 'bool' => [true],
    'garbage string' => ['x'], 'null' => [null], 'array' => [['x']],
]);

it('reads each window once per chunk', function () {
    // Given three chunks of eligible rows (chunk of 2, 5 rows) and a recorder of the settings reads
    foreach (range(1, 5) as $i) {
        antiCheatPurgeSignal(400 + $i);
    }
    Cache::flush();
    $reads = 0;
    DB::listen(function ($query) use (&$reads) {
        if (str_contains($query->sql, 'from "settings"') || str_contains($query->sql, 'from settings')) {
            $reads++;
        }
    });

    // When the purge runs in chunks of two
    $purge = retentionPurger(PurgeRiskSignals::class, 2, 10);
    $purged = $purge->purge(retentionNow());

    // Then everything went in three chunks, and the settings were fetched from the database at most once per window (cached after)
    expect($purged)->toBe(5)->and($reads)->toBeLessThanOrEqual(2);
});

it('works through chunks, stops at the cap, and is idempotent', function () {
    // Given seven due rows, a chunk of two and a cap of two chunks
    $ids = collect(range(1, 7))->map(fn ($i) => antiCheatPurgeSignal(400 + $i))->all();
    $purge = retentionPurger(PurgeRiskSignals::class, 2, 2);

    // When it runs twice, then once more
    $first = $purge->purge(retentionNow());
    $firstCapped = $purge->capped;
    $remaining = DB::table('risk_signals')->count();
    $second = $purge->purge(retentionNow());
    $third = $purge->purge(retentionNow());

    // Then the first run did four rows and flagged the cap, the second finished, the third found nothing
    expect($first)->toBe(4)->and($firstCapped)->toBeTrue()->and($remaining)->toBe(3)->and($second)->toBe(3)->and($purge->capped)->toBeFalse()->and($third)->toBe(0);
    expect(antiCheatPurgeSurvivors($ids))->toBe([]);
});

it('deletes the oldest due rows first across the three branches', function () {
    // Given four due rows of different ages (one reviewed) and a run that can only do two
    $oldest = antiCheatPurgeSignal(900);
    $older = antiCheatPurgeSignal(800, RiskOutcome::Confirmed, 1);
    $newer = antiCheatPurgeSignal(400);
    $newest = antiCheatPurgeSignal(100, RiskOutcome::Confirmed, 200);

    // When a run of one chunk of two executes
    retentionPurger(PurgeRiskSignals::class, 2, 1)->purge(retentionNow());

    // Then the two oldest went
    expect(antiCheatPurgeSurvivors([$oldest, $older, $newer, $newest]))->toBe([$newer, $newest]);
});

it('touches at most one chunk of rows whatever plan the planner picks', function (array $off) {
    // Given twelve due rows spread over the three branches, a chunk of three, and a planner pushed towards a join strategy
    foreach (range(1, 4) as $i) {
        antiCheatPurgeSignal(400 + $i);
        antiCheatPurgeSignal(100, RiskOutcome::Confirmed, 100 + $i);
        antiCheatPurgeSignal(500 + $i, RiskOutcome::Confirmed, 1);
    }
    DB::statement('ANALYZE risk_signals');
    retentionPlannerOff($off);

    // When one chunk runs
    $purged = retentionPurger(PurgeRiskSignals::class, 3, 1)->purge(retentionNow());

    // Then exactly three rows were deleted
    expect($purged)->toBe(3)->and(DB::table('risk_signals')->count())->toBe(9);
})->with(retentionPlannerModes());

it('serves each branch from its partial index and locks with SKIP LOCKED in a materialized step', function () {
    // Given a table with rows in all three states, analysed, and the SQL the purge really runs
    foreach (range(1, 40) as $i) {
        antiCheatPurgeSignal(100 + $i * 10);
        antiCheatPurgeSignal(100, RiskOutcome::Confirmed, 20 + $i * 5);
    }
    DB::statement('ANALYZE risk_signals');
    $captured = retentionCaptureSql(fn () => app(PurgeRiskSignals::class)->purge(retentionNow()), 'WITH due');
    $sql = $captured[0] ?? '';

    // When its plan is read with scans and sorts that avoid indexes disabled
    $plan = retentionPlan($sql);

    // Then the three partial indexes serve the three branches, with no sequential scan of the table
    expect($sql)->toContain('AS MATERIALIZED')->toContain('FOR UPDATE OF s SKIP LOCKED')->toContain('UNION');
    expect($plan)->toContain('risk_signals_unreviewed_detected_index')->toContain('risk_signals_reviewed_index')->toContain('risk_signals_detected_index')->not->toContain('Seq Scan on risk_signals');
});

it('locks no user or product row', function () {
    // Given a due signal about a member and a product
    antiCheatPurgeSignal(400);

    // When the purge runs
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });
    app(PurgeRiskSignals::class)->purge(retentionNow());

    // Then no statement touches users or products
    expect(collect($queries)->filter(fn ($q) => str_contains($q, 'users') || str_contains($q, 'products')))->toBeEmpty();
});

it('reports the chunks that committed before a later chunk failed', function () {
    // Given five due rows, a chunk of two and a second chunk that throws a SQL error
    Exceptions::fake();
    foreach (range(1, 5) as $i) {
        antiCheatPurgeSignal(400 + $i);
    }
    $purge = new class(app(Settings::class), 2, 10) extends PurgeRiskSignals
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
    // Then it throws, the first chunk's rows are gone and counted, and the connection still works
    expect(fn () => $purge->purge(retentionNow()))->toThrow(QueryException::class);
    expect($purge->purgedSoFar)->toBe(2)->and(DB::table('risk_signals')->count())->toBe(3);
});

it('lowers the member score at the next scoring pass when a confirmed signal is purged, and keeps the audit rows, votes and users', function () {
    // Given a member scored 40 by a confirmed signal reviewed 100 days ago (past the review window), an audit row of that review, and a vote
    antiCheatFreeze();
    $member = User::factory()->create(['created_at' => antiCheatNow()->subDays(400)]);
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $signal = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 120, RiskOutcome::Confirmed, ['reviewed_by' => $reviewer->id, 'reviewed_at' => antiCheatNow()->subDays(100)]);
    antiCheatSetScore($member, 40);
    AuditLog::query()->create(['actor_id' => $reviewer->id, 'action' => 'risk_signal.reviewed', 'subject_type' => 'risk_signal', 'subject_id' => $signal->id, 'meta' => [], 'created_at' => antiCheatNow()->subDays(100)]);
    $vote = antiCheatVote($member, antiCheatLiveProduct());
    $users = DB::table('users')->count();

    // When the purge runs and then detection scores again
    $purged = app(PurgeRiskSignals::class)->purge(antiCheatNow());
    app(DetectAbuse::class)->handle(antiCheatNow());

    // Then the signal is gone, the member's score fell to 0, and the audit row, the vote and every user remain
    expect($purged)->toBe(1)->and(antiCheatScoreOf($member))->toBe(0);
    expect(AuditLog::query()->where('subject_id', $signal->id)->count())->toBe(1)->and(DB::table('votes')->where('id', $vote->id)->exists())->toBeTrue()->and(DB::table('users')->count())->toBe($users);
});
