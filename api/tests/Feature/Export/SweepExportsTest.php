<?php

use App\Actions\Export\SweepExports;
use App\Enums\ExportStatus;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(dashNow());
});

/** An export of a fresh product in $state whose created_at and updated_at are $minutes old. */
function sweepExport(string $state, float $minutes): Export
{
    $at = CarbonImmutable::now('UTC')->subSeconds((int) ($minutes * 60));

    return exportFor(Product::factory()->live()->create(), $state, ['created_at' => $at, 'updated_at' => $at]);
}

it('dispatches a queued export that is 11 minutes old, once, and leaves a 9 minute old one', function () {
    // Given two queued exports
    Queue::fake();
    $old = sweepExport('queued', 11);
    sweepExport('queued', 9);

    // When the sweeper runs
    $result = app(SweepExports::class)->handle();

    // Then only the old one is dispatched, once
    expect($result)->toBe(['redispatched' => 1, 'failed_stalled' => 0, 'failed' => 0]);
    Queue::assertPushed(GenerateExport::class, 1);
    Queue::assertPushed(GenerateExport::class, fn (GenerateExport $job) => $job->exportId === $old->id);
});

it('fails a processing export untouched for 11 minutes as stalled, and leaves a 9 minute old one', function () {
    // Given two processing exports
    Queue::fake();
    $stuck = sweepExport('processing', 11);
    $fresh = sweepExport('processing', 9);

    // When the sweeper runs
    $result = app(SweepExports::class)->handle();

    // Then the stuck one is failed with the code stalled; the fresh one is untouched
    expect($result)->toBe(['redispatched' => 0, 'failed_stalled' => 1, 'failed' => 0]);
    expect($stuck->refresh()->status)->toBe(ExportStatus::Failed)->and($stuck->error)->toBe('stalled')->and($fresh->refresh()->status)->toBe(ExportStatus::Processing);
    Queue::assertNothingPushed();
});

it('removes a zip a killed worker left behind when it fails the export as stalled', function () {
    // Given a stuck processing export whose zip was written before the worker died
    Storage::fake('exports');
    $stuck = sweepExport('processing', 30);
    $path = exportStoreFile($stuck);

    // When the sweeper runs
    app(SweepExports::class)->handle();

    // Then the row is failed and the orphan file is gone
    expect($stuck->refresh()->status)->toBe(ExportStatus::Failed);
    Storage::disk('exports')->assertMissing($path);
});

it('never touches ready, failed or expired rows however old', function () {
    // Given old rows in the final states
    Queue::fake();
    $rows = array_map(fn (string $state) => sweepExport($state, 600), ['ready', 'failed', 'expired']);
    $snapshot = fn () => DB::table('exports')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $before = $snapshot();

    // When the sweeper runs
    $result = app(SweepExports::class)->handle();

    // Then nothing changed
    expect($result)->toBe(['redispatched' => 0, 'failed_stalled' => 0, 'failed' => 0]);
    expect($snapshot())->toBe($before)->and($rows)->toHaveCount(3);
    Queue::assertNothingPushed();
});

it('reports and counts a dispatch that throws, still dispatches the others and exits non-zero from the command', function () {
    // Given two old queued exports and a bus that throws on the first dispatch only
    Exceptions::fake();
    $a = sweepExport('queued', 20);
    $b = sweepExport('queued', 20);
    $calls = [];
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andReturnUsing(function (GenerateExport $job) use (&$calls) {
        $calls[] = $job->exportId;
        if (count($calls) === 1) {
            throw new RuntimeException('queue down');
        }

        return 0;
    });
    $this->app->instance(Dispatcher::class, $bus);

    // When the command runs
    $this->artisan('launchpad:sweep-exports')->expectsOutput('re-dispatched 1, failed stalled 0, failed 1')->assertExitCode(1);

    // Then both were tried and the failure was reported
    expect($calls)->toHaveCount(2)->and($calls)->toContain($a->id, $b->id);
    Exceptions::assertReported(RuntimeException::class);
});

it('asks for a stuck processing row with SKIP LOCKED, never waiting for a row another session holds', function () {
    // Given a stuck processing export
    $stuck = sweepExport('processing', 30);

    // When the sweeper runs and its locking statements are captured
    $statements = exportLockingStatements(fn () => app(SweepExports::class)->handle());

    // Then the read that locks the row skips locked rows (the two-session proof is in ExportSkipLockedTest)
    expect($statements)->toHaveCount(1)->and($statements[0])->toContain('for update skip locked');
    expect($stuck->refresh()->status)->toBe(ExportStatus::Failed);
});

it('prints the line, exits zero on success and takes no product or user lock', function () {
    // Given a stuck processing export and a stuck queued one
    Queue::fake();
    sweepExport('processing', 30);
    sweepExport('queued', 30);

    // When the command runs
    $locks = exportLockingStatements(fn () => $this->artisan('launchpad:sweep-exports')->expectsOutput('re-dispatched 1, failed stalled 1, failed 0')->assertExitCode(0));

    // Then only export rows were locked
    foreach ($locks as $sql) {
        expect($sql)->toContain('from "exports"');
    }
});

it('reads the clock once: the injected instant decides, not the real clock', function () {
    // Given a queued export 11 minutes older than a fixed instant, but fresh by the real clock
    Queue::fake();
    $export = sweepExport('queued', 1);

    // When the sweeper runs with an instant an hour later
    $none = app(SweepExports::class)->handle();
    $later = app(SweepExports::class)->handle(CarbonImmutable::now('UTC')->addHour());

    // Then the real clock sees nothing and the injected one sees the stuck row
    expect($none['redispatched'])->toBe(0)->and($later['redispatched'])->toBe(1);
    Queue::assertPushed(GenerateExport::class, fn (GenerateExport $job) => $job->exportId === $export->id);
});

it('works through more than one chunk of stuck rows', function () {
    // Given 205 stuck queued exports (one more than a chunk of 200)
    Queue::fake();
    $now = CarbonImmutable::now('UTC')->subMinutes(30)->format('Y-m-d H:i:s.uP');
    $rows = [];
    foreach (Product::factory()->live()->count(205)->create() as $product) {
        $rows[] = ['id' => strtolower((string) Str::ulid()), 'user_id' => $product->maker_id, 'product_id' => $product->id, 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now];
    }
    DB::table('exports')->insert($rows);

    // When the sweeper runs
    $result = app(SweepExports::class)->handle();

    // Then every one was dispatched
    expect($result['redispatched'])->toBe(205);
    Queue::assertPushed(GenerateExport::class, 205);
});

it('registers the schedule once: every five minutes, a ten minute lock, without overlapping, on one server', function () {
    // Given the schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'launchpad:sweep-exports'));

    // When the entry is read
    // Then there is exactly one, with the documented frequency and protection
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect([$event->expression, $event->expiresAt, $event->withoutOverlapping, $event->onOneServer])->toBe(['*/5 * * * *', 10, true, true]);
});
