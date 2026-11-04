<?php

use App\Actions\Retention\PurgeAuditNetwork;
use App\Actions\Retention\PurgeAuthEvents;
use App\Actions\Retention\PurgeDigestRuns;
use App\Actions\Retention\PurgeEmailMessages;
use App\Actions\Retention\PurgeExports;
use App\Actions\Retention\PurgeNotifications;
use App\Actions\Retention\PurgeRetention;
use App\Actions\Retention\PurgeRiskSignals;
use App\Actions\Retention\PurgeVoteHashes;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

/** One old and one fresh row in each of the three tables that are seeded here (audit_logs is covered by its own tests). */
function retentionSeedAll(): array
{
    $product = Product::factory()->live()->create();
    retentionSnapshot($product, retentionNow()->subDays(200));
    retentionSnapshot($product, retentionNow()->subDay());
    retentionAuthEvent(retentionNow()->subDays(400));
    retentionAuthEvent(retentionNow()->subDay());

    return ['oldVote' => retentionVote('2026-05-01'), 'freshVote' => retentionVote('2026-10-21')];
}

it('purges every table and reports one outcome per table', function () {
    // Given old and fresh rows in votes, auth_events and rank_snapshots (the notification tables have their own tests below)
    $votes = retentionSeedAll();

    // When the retention run executes
    $action = app(PurgeRetention::class);
    $total = $action->handle(retentionNow());

    // Then each table lost exactly its old rows and the outcomes say so
    expect($total)->toBe(3)->and($action->failed)->toBe(0);
    expect($action->results)->toBe([
        'votes' => ['purged' => 1, 'capped' => false, 'failed' => false],
        'auth_events' => ['purged' => 1, 'capped' => false, 'failed' => false],
        'rank_snapshots' => ['purged' => 1, 'capped' => false, 'failed' => false],
        'audit_logs' => ['purged' => 0, 'capped' => false, 'failed' => false],
        'notifications' => ['purged' => 0, 'capped' => false, 'failed' => false],
        'email_messages' => ['purged' => 0, 'capped' => false, 'failed' => false],
        'digest_runs' => ['purged' => 0, 'capped' => false, 'failed' => false],
        'exports' => ['purged' => 0, 'capped' => false, 'failed' => false],
        'risk_signals' => ['purged' => 0, 'capped' => false, 'failed' => false],
    ]);
    expect(DB::table('auth_events')->count())->toBe(1)->and(DB::table('rank_snapshots')->count())->toBe(1);
    expect(DB::table('votes')->whereNull('ip_hash')->pluck('id')->all())->toBe([$votes['oldVote']->id]);
});

it('is idempotent across the whole run', function () {
    // Given seeded tables, purged once
    retentionSeedAll();
    app(PurgeRetention::class)->handle(retentionNow());

    // When it runs again
    $action = app(PurgeRetention::class);
    $second = $action->handle(retentionNow());

    // Then nothing changes
    expect($second)->toBe(0)->and($action->failed)->toBe(0);
});

it('reads the clock once per run and uses it for every table', function () {
    // Given a rank snapshot and an auth event that are old only by the real clock minus the fixed instant
    $product = Product::factory()->live()->create();
    retentionSnapshot($product, retentionNow()->subDays(181));
    retentionAuthEvent(retentionNow()->subDays(181));

    // When the run gets an instant ten days earlier than the (travelled) real clock
    $action = app(PurgeRetention::class);
    $action->handle(retentionNow()->subDays(10));

    // Then the fixed instant decided, so neither row was old enough
    expect(DB::table('rank_snapshots')->count())->toBe(1)->and(DB::table('auth_events')->count())->toBe(1);
});

it('keeps purging the other tables when one fails, reports it, and counts it', function () {
    // Given old rows everywhere and an auth_events purge whose chunk fails with a SQL error
    Exceptions::fake();
    retentionSeedAll();
    app()->bind(PurgeAuthEvents::class, fn () => new class(app(Settings::class)) extends PurgeAuthEvents
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('select 1/0');

            return 0;
        }
    });

    // When the run executes
    $action = app(PurgeRetention::class);
    $total = $action->handle(retentionNow());

    // Then votes and rank_snapshots were purged, auth_events kept its rows, and the failure is counted and reported
    expect($total)->toBe(2)->and($action->failed)->toBe(1);
    expect($action->results['auth_events'])->toBe(['purged' => 0, 'capped' => false, 'failed' => true]);
    expect(DB::table('auth_events')->count())->toBe(2)->and(DB::table('rank_snapshots')->count())->toBe(1);
    expect(DB::table('votes')->whereNull('ip_hash')->count())->toBe(1);
    Exceptions::assertReported(QueryException::class);
});

it('survives a SQL error in the first table: the failed statement does not poison the later ones', function () {
    // Given a votes purge that fails and old rows in the other tables
    Exceptions::fake();
    retentionSeedAll();
    app()->bind(PurgeVoteHashes::class, fn () => new class(app(Settings::class)) extends PurgeVoteHashes
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('select 1/0');

            return 0;
        }
    });

    // When the run executes
    $action = app(PurgeRetention::class);
    $action->handle(retentionNow());

    // Then the later tables still ran after the SQL error (the failed statement did not poison the connection)
    expect($action->failed)->toBe(1)->and($action->results['votes']['failed'])->toBeTrue();
    expect(DB::table('auth_events')->count())->toBe(1)->and(DB::table('rank_snapshots')->count())->toBe(1);
    expect(DB::table('votes')->whereNotNull('ip_hash')->count())->toBe(2);
});

it('never deletes audit_logs rows: old rows survive the run, only their network columns are emptied', function () {
    // Given audit rows from years ago with network data, and seeded purgeable rows
    retentionSeedAll();
    AuditLog::factory()->count(3)->create(['created_at' => retentionNow()->subYears(8), 'ip_hash' => str_repeat('c', 64), 'user_agent' => 'Agent']);

    // When the run executes
    $action = app(PurgeRetention::class);
    $action->handle(retentionNow());

    // Then every audit row is still there with its action, meta and time, minus ip_hash and user_agent
    expect(AuditLog::query()->count())->toBe(3)->and($action->results['audit_logs']['purged'])->toBe(3);
    expect(DB::table('audit_logs')->whereNotNull('ip_hash')->orWhereNotNull('user_agent')->count())->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'test.action')->count())->toBe(3);
    foreach (glob(app_path('Actions/Retention/*.php')) as $file) {
        expect(file_get_contents($file))->not->toMatch('/DELETE\s+FROM\s+audit_logs|TRUNCATE|table\([\'"]audit_logs[\'"]\)->delete/i');
    }
});

it('keeps purging the other tables when the audit_logs purge fails', function () {
    // Given old rows everywhere and an audit purge whose chunk fails with a SQL error
    Exceptions::fake();
    retentionSeedAll();
    app()->bind(PurgeAuditNetwork::class, fn () => new class(app(Settings::class)) extends PurgeAuditNetwork
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('select 1/0');

            return 0;
        }
    });

    // When the run executes
    $action = app(PurgeRetention::class);
    $total = $action->handle(retentionNow());

    // Then the other three tables were purged and the failure is counted and reported
    expect($total)->toBe(3)->and($action->failed)->toBe(1);
    expect($action->results['audit_logs'])->toBe(['purged' => 0, 'capped' => false, 'failed' => true]);
    expect(DB::table('auth_events')->count())->toBe(1)->and(DB::table('rank_snapshots')->count())->toBe(1);
    Exceptions::assertReported(QueryException::class);
});

it('reports and totals the chunks that committed before a later chunk failed', function () {
    // Given five old auth events, a chunk of two, and a purge whose second chunk throws a SQL error
    Exceptions::fake();
    foreach (range(1, 5) as $i) {
        retentionAuthEvent(retentionNow()->subDays(300 + $i));
    }
    app()->bind(PurgeAuthEvents::class, fn () => new class(app(Settings::class), 2, 10) extends PurgeAuthEvents
    {
        private int $calls = 0;

        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            if (++$this->calls === 2) {
                DB::select('select 1/0');
            }

            return parent::purgeChunk($now, $limit);
        }
    });

    // When the run executes
    $action = app(PurgeRetention::class);
    $total = $action->handle(retentionNow());

    // Then the two rows of chunk one are gone, reported and counted, and the failure is flagged
    expect(DB::table('auth_events')->count())->toBe(3);
    expect($action->results['auth_events'])->toBe(['purged' => 2, 'capped' => false, 'failed' => true]);
    expect($total)->toBe(2)->and($action->failed)->toBe(1);
});

/** One old and one fresh row in each of the three notification tables. */
function retentionSeedNotificationTables(): void
{
    $user = User::factory()->create();
    retentionBell($user->id, retentionNow()->subDays(200));
    retentionBell($user->id, retentionNow()->subDay());
    retentionMail($user->id, 'sent', retentionNow()->subDays(200));
    retentionMail($user->id, 'sent', retentionNow()->subDay());
    retentionMail($user->id, 'queued', retentionNow()->subDays(500));
    retentionDigestRun('2026-04-01');
    retentionDigestRun('2026-10-20');
}

it('purges the three notification tables after the old four and leaves fresh and waiting rows', function () {
    // Given old and fresh rows in notifications, email_messages (plus an ancient queued one) and digest_runs
    retentionSeedNotificationTables();

    // When the run executes
    $action = app(PurgeRetention::class);
    $total = $action->handle(retentionNow());

    // Then each lost exactly its old rows, the outcomes follow the four older tables in order, and nothing failed
    expect($total)->toBe(3)->and($action->failed)->toBe(0);
    expect(array_keys($action->results))->toBe(['votes', 'auth_events', 'rank_snapshots', 'audit_logs', 'notifications', 'email_messages', 'digest_runs', 'exports', 'risk_signals']);
    expect($action->results['notifications'])->toBe(['purged' => 1, 'capped' => false, 'failed' => false]);
    expect($action->results['email_messages'])->toBe(['purged' => 1, 'capped' => false, 'failed' => false]);
    expect($action->results['digest_runs'])->toBe(['purged' => 1, 'capped' => false, 'failed' => false]);
    expect(DB::table('notifications')->count())->toBe(1)->and(DB::table('email_messages')->pluck('status')->sort()->values()->all())->toBe(['queued', 'sent'])->and(DB::table('digest_runs')->count())->toBe(1);
});

it('keeps purging the other tables when a notification table fails', function (string $table) {
    // Given old rows everywhere and one notification purge whose chunk fails with a SQL error
    Exceptions::fake();
    retentionSeedAll();
    retentionSeedNotificationTables();
    match ($table) {
        'notifications' => app()->bind(PurgeNotifications::class, fn () => new class(app(Settings::class)) extends PurgeNotifications
        {
            protected function purgeChunk(CarbonImmutable $now, int $limit): int
            {
                DB::select('select 1/0');

                return 0;
            }
        }),
        'email_messages' => app()->bind(PurgeEmailMessages::class, fn () => new class(app(Settings::class)) extends PurgeEmailMessages
        {
            protected function purgeChunk(CarbonImmutable $now, int $limit): int
            {
                DB::select('select 1/0');

                return 0;
            }
        }),
        'digest_runs' => app()->bind(PurgeDigestRuns::class, fn () => new class(app(Settings::class)) extends PurgeDigestRuns
        {
            protected function purgeChunk(CarbonImmutable $now, int $limit): int
            {
                DB::select('select 1/0');

                return 0;
            }
        }),
        'exports' => app()->bind(PurgeExports::class, fn () => new class(app(Settings::class)) extends PurgeExports
        {
            protected function purgeChunk(CarbonImmutable $now, int $limit): int
            {
                DB::select('select 1/0');

                return 0;
            }
        }),
        'risk_signals' => app()->bind(PurgeRiskSignals::class, fn () => new class(app(Settings::class)) extends PurgeRiskSignals
        {
            protected function purgeChunk(CarbonImmutable $now, int $limit): int
            {
                DB::select('select 1/0');

                return 0;
            }
        }),
    };

    // When the run executes
    $action = app(PurgeRetention::class);
    $action->handle(retentionNow());

    // Then only that table failed (reported and counted); the other tables purged their old rows
    expect($action->failed)->toBe(1)->and($action->results[$table])->toBe(['purged' => 0, 'capped' => false, 'failed' => true]);
    foreach (['votes', 'auth_events', 'rank_snapshots', 'notifications', 'email_messages', 'digest_runs', 'exports', 'risk_signals'] as $other) {
        if ($other !== $table) {
            expect($action->results[$other]['failed'])->toBeFalse();
            // (nothing is seeded in exports or risk_signals here: they only have to survive the others failing)
            expect($action->results[$other]['purged'])->toBeGreaterThanOrEqual(in_array($other, ['exports', 'risk_signals'], true) ? 0 : 1);
        }
    }
    Exceptions::assertReported(QueryException::class);
})->with(['notifications', 'email_messages', 'digest_runs', 'exports', 'risk_signals']);

it('expires and removes an old export in the same run and reports it last', function () {
    // Given a ready export past its expiry with a file, and an old failed export without one
    Storage::fake('exports');
    $expired = exportFor(Product::factory()->live()->create(), 'ready', ['expires_at' => retentionNow()->subMinute()]);
    $path = exportStoreFile($expired);
    $old = exportFor(Product::factory()->live()->create(), 'failed', ['updated_at' => retentionNow()->subDays(40)]);

    // When the run executes
    $action = app(PurgeRetention::class);
    $total = $action->handle(retentionNow());

    // Then the export table reports the rows it touched (risk_signals follows it as the last one); the file is gone and the old failed row deleted
    expect($action->results['exports'])->toBe(['purged' => 2, 'capped' => false, 'failed' => false])->and(array_key_last($action->results))->toBe('risk_signals')->and($total)->toBe(2);
    Storage::disk('exports')->assertMissing($path);
    expect(DB::table('exports')->where('id', $expired->id)->value('status'))->toBe('expired')->and(DB::table('exports')->where('id', $old->id)->exists())->toBeFalse();
});
