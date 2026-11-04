<?php

use App\Support\ViewCounters;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    viewRedisReset();
    $this->travelTo(new DateTimeImmutable(VIEW_NOON.' UTC'));
});

it('prints the report line and exits 0', function () {
    // Given a pending batch for one product
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 2, "r|{$product->id}|".FLUSH_DAY.'|x' => 1]);

    // When the command runs
    // Then it reports the batch, the buckets and the referrer rows
    $this->artisan('launchpad:flush-views')->expectsOutput('flushed 1 batch(es): 1 bucket(s), 2 referrer row(s), 0 product(s) skipped.')->assertSuccessful();
});

it('is a quiet success when nothing is pending', function () {
    // Given nothing pending
    // When the command runs
    // Then it flushed zero batches and exits 0
    $this->artisan('launchpad:flush-views')->expectsOutput('flushed 0 batch(es): 0 bucket(s), 0 referrer row(s), 0 product(s) skipped.')->assertSuccessful();
});

it('exits 1 with both lines when a batch fails, and reports the exception', function () {
    // Given pending views and a database fault
    Exceptions::fake();
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|google' => 3]);
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'INSERT INTO product_referrers_daily')) {
            throw new RuntimeException('injected fault');
        }
    });

    // When the command runs
    // Then both lines are printed, it fails, and the failure was reported
    $this->artisan('launchpad:flush-views')
        ->expectsOutput('flushed 0 batch(es): 0 bucket(s), 0 referrer row(s), 0 product(s) skipped.')
        ->expectsOutput('1 batch(es) failed and stay for the next run.')
        ->assertFailed();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'injected fault');
});

it('exits 1 and reports the exception when Redis itself is down', function () {
    // Given counters that throw on every call
    Exceptions::fake();
    $this->app->instance(ViewCounters::class, new class extends ViewCounters
    {
        public function rotate(): ?int
        {
            throw new RuntimeException('redis is down');
        }
    });

    // When the command runs
    // Then it fails with an error line and the exception is reported
    $this->artisan('launchpad:flush-views')->expectsOutput('The view counters could not be read; nothing was flushed.')->assertFailed();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'redis is down');
});

it('is scheduled once, every minute, with a 5 minute overlap lock on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'launchpad:flush-views'));

    // When the entry is read
    // Then there is exactly one, with the documented protection
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect([$event->expression, $event->expiresAt, $event->withoutOverlapping, $event->onOneServer])->toBe(['* * * * *', 5, true, true]);
});
