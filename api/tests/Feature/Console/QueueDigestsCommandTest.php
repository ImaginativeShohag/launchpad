<?php

use App\Actions\Email\QueueDigest;
use App\Actions\Ranking\Leaderboard;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__.'/../../Support/DigestFixtures.php';

it('prints one line per kind and exits zero', function () {
    // Given a Tuesday morning after the send time with a frozen day and a member
    $this->travelTo(new DateTimeImmutable('2026-10-21 08:00:00 UTC'));
    digestFreeze('2026-10-20', [digestProduct('2026-10-20')]);

    // When the command runs twice
    // Then the first run completes the daily digest (the product's maker is the only member) and the second one finds it done
    $this->artisan('launchpad:queue-digests')
        ->expectsOutput('daily_top5 2026-10-20: completed, 1 enqueued')
        ->expectsOutput('weekly_best: not due')
        ->assertExitCode(0);
    $this->artisan('launchpad:queue-digests')
        ->expectsOutput('daily_top5 2026-10-20: already done')
        ->expectsOutput('weekly_best: not due')
        ->assertExitCode(0);
});

it('says when it waits for an unfrozen day, and when nothing is due yet', function () {
    // Given a Monday after the weekly time, with no frozen day and no launches
    $this->travelTo(new DateTimeImmutable('2026-10-26 08:00:00 UTC'));

    // When the command runs
    // Then the daily digest waits and the weekly best completes empty
    $this->artisan('launchpad:queue-digests')
        ->expectsOutput('daily_top5 2026-10-25: waiting')
        ->expectsOutput('weekly_best 2026-10-19: completed, 0 enqueued')
        ->assertExitCode(0);

    // And before the send time both are not due
    $this->travelTo(new DateTimeImmutable('2026-10-27 06:00:00 UTC'));
    $this->artisan('launchpad:queue-digests')->expectsOutput('daily_top5: not due')->expectsOutput('weekly_best: not due')->assertExitCode(0);
});

it('keeps going with the weekly digest when the daily one fails, names the failed kind and exits one', function () {
    // Given a Monday morning and a daily kind whose product pick fails with a SQL error
    Exceptions::fake();
    $this->travelTo(new DateTimeImmutable('2026-10-26 08:00:00 UTC'));
    $product = digestProduct('2026-10-22', ['votes_count' => 3]);
    app()->bind(QueueDigest::class, fn () => new class(app(Settings::class), app(Leaderboard::class)) extends QueueDigest
    {
        protected function dailyProducts(string $period, CarbonImmutable $now): ?array
        {
            DB::transaction(fn () => DB::select('select 1/0'));

            return [];
        }
    });

    // When the command runs
    $exit = Artisan::call('launchpad:queue-digests');
    $output = Artisan::output();

    // Then the daily kind is named as failed, the weekly one completed, and the exit code is 1
    expect($exit)->toBe(1)->and($output)->toContain('daily_top5: FAILED (see the log)')->toContain('weekly_best 2026-10-19: completed')->toContain('1 digest kind(s) failed');
    Exceptions::assertReported(QueryException::class);
    expect(json_decode(digestRun('weekly_best', '2026-10-19')['product_ids']))->toBe([$product->id]);
});

it('is scheduled once, every ten minutes, with a 60 minute overlap lock and on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:queue-digests'));

    // When the entries for the command are read
    // Then there is exactly one, protected like the others
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('*/10 * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(60)->and($event->onOneServer)->toBeTrue()->and($event->runInBackground)->toBeTrue();
});

it('exits non-zero and says so when it abandons a run that is too old to resume', function () {
    // Given an open run that started 25 hours ago
    $this->travelTo(new DateTimeImmutable('2026-10-21 08:00:00 UTC'));
    DB::table('digest_runs')->insert(['kind' => 'daily_top5', 'period_date' => '2026-10-19', 'product_ids' => '[]', 'last_user_id' => null, 'enqueued' => 3, 'started_at' => '2026-10-20 07:00:00+00', 'completed_at' => null]);

    // When the command runs
    $exit = Artisan::call('launchpad:queue-digests');
    $output = Artisan::output();

    // Then the abandoned run is named and the exit code is 1; the run is closed so the next tick is quiet
    expect($exit)->toBe(1)->and($output)->toContain('daily_top5 2026-10-19: abandoned');
    expect(Artisan::call('launchpad:queue-digests'))->not->toBe(1);
});
