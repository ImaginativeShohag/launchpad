<?php

use App\Actions\Retention\PurgeAuditNetwork;
use App\Actions\Retention\PurgeAuthEvents;
use App\Actions\Retention\PurgeEmailMessages;
use App\Actions\Retention\PurgeExports;
use App\Actions\Retention\PurgeRankSnapshots;
use App\Console\Commands\PurgeRetentionCommand;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

it('prints one line per table and a total, and exits zero', function () {
    // Given one old row in each table
    $product = Product::factory()->live()->create();
    retentionSnapshot($product, retentionNow()->subDays(200));
    retentionAuthEvent(retentionNow()->subDays(400));
    retentionVote('2026-05-01');

    // When the command runs
    // Then it reports each table and the total
    $this->artisan('launchpad:purge-retention')
        ->expectsOutput('votes: 1 row(s) purged.')
        ->expectsOutput('auth_events: 1 row(s) purged.')
        ->expectsOutput('rank_snapshots: 1 row(s) purged.')
        ->expectsOutput('audit_logs: 0 row(s) purged.')
        ->expectsOutput('notifications: 0 row(s) purged.')
        ->expectsOutput('email_messages: 0 row(s) purged.')
        ->expectsOutput('digest_runs: 0 row(s) purged.')
        ->expectsOutput('exports: 0 row(s) purged.')
        ->expectsOutput('risk_signals: 0 row(s) purged.')
        ->expectsOutput('Total: 3 row(s) purged.')
        ->assertExitCode(0);
});

it('is idempotent: the second run purges nothing and still succeeds', function () {
    // Given a purged database
    retentionAuthEvent(retentionNow()->subDays(400));
    $this->artisan('launchpad:purge-retention')->assertExitCode(0);

    // When it runs again
    // Then nothing is purged
    $this->artisan('launchpad:purge-retention')->expectsOutput('Total: 0 row(s) purged.')->assertExitCode(0);
});

it('says when a table stopped at the per-run cap', function () {
    // Given three old snapshots and a rank_snapshots purge limited to one chunk of two
    $product = Product::factory()->live()->create();
    foreach (range(1, 3) as $i) {
        retentionSnapshot($product, retentionNow()->subDays(200)->addMinutes($i));
    }
    app()->bind(PurgeRankSnapshots::class, fn () => new PurgeRankSnapshots(app(Settings::class), 2, 1));

    // When the command runs
    // Then it flags the cap and still exits zero (the next run continues)
    $this->artisan('launchpad:purge-retention')
        ->expectsOutputToContain('rank_snapshots: 2 row(s) purged; stopped at the per-run cap, the next nightly run continues.')
        ->assertExitCode(0);
    expect(DB::table('rank_snapshots')->count())->toBe(1);
});

it('exits non-zero when a table fails, names it, and still purges the others', function () {
    // Given a failing rank_snapshots purge and an old auth event
    Exceptions::fake();
    retentionAuthEvent(retentionNow()->subDays(400));
    app()->bind(PurgeRankSnapshots::class, fn () => new class(app(Settings::class)) extends PurgeRankSnapshots
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('select 1/0');

            return 0;
        }
    });

    // When the command runs
    // Then it fails loudly, the other tables were handled, and the error was reported
    $this->artisan('launchpad:purge-retention')
        ->expectsOutput('auth_events: 1 row(s) purged.')
        ->expectsOutput('rank_snapshots: FAILED after 0 row(s) purged (see the log); the other tables were still processed.')
        ->expectsOutput('1 table(s) failed.')
        ->assertExitCode(1);
    expect(DB::table('auth_events')->count())->toBe(0);
    Exceptions::assertReported(QueryException::class);
});

it('is scheduled once, daily at 03:15, with an overlap lock and on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:purge-retention'));

    // When the entries for the command are read
    // Then there is exactly one, daily, protected like the others
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('15 3 * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(60)->and($event->onOneServer)->toBeTrue();
});

it('includes rows from chunks that committed before the failure in the output and total, and exits non-zero', function () {
    // Given three old auth events, a chunk of two where the second chunk fails
    Exceptions::fake();
    foreach (range(1, 3) as $i) {
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

    // When the command runs
    // Then the partial count is shown and totalled
    $this->artisan('launchpad:purge-retention')
        ->expectsOutputToContain('auth_events: FAILED after 2 row(s) purged')
        ->expectsOutput('Total: 2 row(s) purged.')
        ->assertExitCode(1);
});

it('prints the cap notice as a styled warning, not a plain line', function () {
    // Given a capped rank_snapshots purge and a decorated output buffer
    $product = Product::factory()->live()->create();
    foreach (range(1, 3) as $i) {
        retentionSnapshot($product, retentionNow()->subDays(200)->addMinutes($i));
    }
    app()->bind(PurgeRankSnapshots::class, fn () => new PurgeRankSnapshots(app(Settings::class), 2, 1));
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    $command = app(PurgeRetentionCommand::class);
    $command->setLaravel(app());

    // When the command runs
    $exit = $command->run(new ArrayInput([]), $output);

    // Then the notice carries colour codes (warn) and the exit code is still zero
    expect($exit)->toBe(0)->and($output->fetch())->toMatch('/\e\[[0-9;]+m[^\n]*stopped at the per-run cap/');
});

it('reports an audit_logs line and keeps the schedule at one purge command', function () {
    // Given an old audit row with network data
    retentionAuditLog(retentionNow()->subDays(500));

    // When the command runs
    // Then it reports the emptied audit row
    $this->artisan('launchpad:purge-retention')
        ->expectsOutput('audit_logs: 1 row(s) purged.')
        ->expectsOutput('Total: 1 row(s) purged.')
        ->assertExitCode(0);

    // And the audit purge did not add a schedule entry of its own
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'purge'));
    expect($events)->toHaveCount(1);
});

it('names audit_logs and exits non-zero when its purge fails, and the other tables still run', function () {
    // Given a failing audit purge and an old auth event
    Exceptions::fake();
    retentionAuthEvent(retentionNow()->subDays(400));
    app()->bind(PurgeAuditNetwork::class, fn () => new class(app(Settings::class)) extends PurgeAuditNetwork
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('select 1/0');

            return 0;
        }
    });

    // When the command runs
    // Then it fails loudly and names the table
    $this->artisan('launchpad:purge-retention')
        ->expectsOutput('auth_events: 1 row(s) purged.')
        ->expectsOutput('audit_logs: FAILED after 0 row(s) purged (see the log); the other tables were still processed.')
        ->assertExitCode(1);
    Exceptions::assertReported(QueryException::class);
});

it('reports the three notification tables and purges their old rows', function () {
    // Given an old row in each of the notification tables, and a waiting outbox row that must stay
    $user = User::factory()->create();
    retentionBell($user->id, retentionNow()->subDays(200));
    retentionMail($user->id, 'sent', retentionNow()->subDays(200));
    retentionMail($user->id, 'queued', retentionNow()->subDays(200));
    retentionDigestRun('2026-04-01');

    // When the command runs
    // Then each table reports its purge, the waiting row stays, and the total adds up
    $this->artisan('launchpad:purge-retention')
        ->expectsOutput('notifications: 1 row(s) purged.')
        ->expectsOutput('email_messages: 1 row(s) purged.')
        ->expectsOutput('digest_runs: 1 row(s) purged.')
        ->expectsOutput('Total: 3 row(s) purged.')
        ->assertExitCode(0);
    expect(DB::table('email_messages')->pluck('status')->all())->toBe(['queued']);
});

it('names a failing notification table, exits non-zero and still purges the others', function () {
    // Given a failing email_messages purge and an old bell row
    Exceptions::fake();
    $user = User::factory()->create();
    retentionBell($user->id, retentionNow()->subDays(200));
    app()->bind(PurgeEmailMessages::class, fn () => new class(app(Settings::class)) extends PurgeEmailMessages
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('select 1/0');

            return 0;
        }
    });

    // When the command runs
    // Then it fails loudly and names the table, and the bell row was purged
    $this->artisan('launchpad:purge-retention')
        ->expectsOutput('notifications: 1 row(s) purged.')
        ->expectsOutput('email_messages: FAILED after 0 row(s) purged (see the log); the other tables were still processed.')
        ->assertExitCode(1);
    Exceptions::assertReported(QueryException::class);
});

it('mentions the new tables in its description', function () {
    // Given the command
    // When its description is read
    // Then it names every table family it purges
    $description = (new PurgeRetentionCommand)->getDescription();
    expect($description)->toContain('notifications')->toContain('outbox mail')->toContain('digest runs')->toContain('exports')->toContain('risk signals');
});

it('names a failing exports purge, exits non-zero and still purges the others', function () {
    // Given a failing exports purge and an old bell row
    Exceptions::fake();
    $user = User::factory()->create();
    retentionBell($user->id, retentionNow()->subDays(200));
    app()->bind(PurgeExports::class, fn () => new class(app(Settings::class)) extends PurgeExports
    {
        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            DB::select('select 1/0');

            return 0;
        }
    });

    // When the command runs
    // Then it fails loudly and names the table, and the bell row was purged
    $this->artisan('launchpad:purge-retention')
        ->expectsOutput('notifications: 1 row(s) purged.')
        ->expectsOutput('exports: FAILED after 0 row(s) purged (see the log); the other tables were still processed.')
        ->assertExitCode(1);
    Exceptions::assertReported(QueryException::class);
});

it('prints the exports line with the rows it expired', function () {
    // Given an expired ready export
    Storage::fake('exports');
    exportFor(Product::factory()->live()->create(), 'ready', ['expires_at' => retentionNow()->subHour()]);

    // When the command runs
    // Then the exports line counts the expired row (and the leftover file step does not count it twice)
    $this->artisan('launchpad:purge-retention')->expectsOutput('exports: 1 row(s) purged.')->assertExitCode(0);
});
