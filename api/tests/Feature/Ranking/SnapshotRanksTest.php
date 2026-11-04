<?php

use App\Actions\Ranking\SnapshotRanks;
use App\Actions\Ranking\TodayRanking;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:07:59 UTC')));

function snapshotRows(?string $capturedAt = null): array
{
    return DB::table('rank_snapshots')
        ->when($capturedAt !== null, fn ($q) => $q->where('captured_at', $capturedAt))
        ->orderBy('captured_at')->orderBy('rank')->get()
        ->map(fn ($r) => ['product_id' => $r->product_id, 'captured_at' => substr((string) $r->captured_at, 0, 19), 'rank' => $r->rank, 'score' => $r->score])
        ->all();
}

function liveToday(float $score, int $votes = 0, array $attributes = []): Product
{
    return Product::factory()->live('2026-10-21')->create(['hot_score' => $score, 'votes_count' => $votes, ...$attributes]);
}

it('writes one row per ranked product with ranks 1..n and the current scores', function () {
    // Given three launches today with different scores
    $low = liveToday(1.5);
    $top = liveToday(9.25);
    $mid = liveToday(4.0);

    // When the ranks are snapshotted
    $inserted = app(SnapshotRanks::class)->handle();

    // Then the rows follow the live order, under the 10-minute mark
    expect($inserted)->toBe(3);
    $rows = snapshotRows();
    expect(array_column($rows, 'product_id'))->toBe([$top->id, $mid->id, $low->id]);
    expect(array_column($rows, 'rank'))->toBe([1, 2, 3]);
    expect(array_column($rows, 'score'))->toBe([9.25, 4.0, 1.5]);
    expect(array_unique(array_column($rows, 'captured_at')))->toBe(['2026-10-21 12:00:00']);
});

it('uses the live tie-breaks: votes, then id', function () {
    // Given equal scores with different votes, and a full tie
    $fewVotes = liveToday(2.0, 1);
    $manyVotes = liveToday(2.0, 7);
    $tieA = liveToday(1.0, 0);
    $tieB = liveToday(1.0, 0);

    // When the ranks are snapshotted
    app(SnapshotRanks::class)->handle();

    // Then more votes rank first and a full tie falls back to id
    $tie = collect([$tieA, $tieB])->pluck('id')->sort()->values()->all();
    expect(array_column(snapshotRows(), 'product_id'))->toBe([$manyVotes->id, $fewVotes->id, ...$tie]);
});

it('inserts nothing the second time inside the same ten-minute window', function () {
    // Given a snapshot taken at 12:07:59
    liveToday(3.0);
    liveToday(2.0);
    $action = app(SnapshotRanks::class);
    expect($action->handle())->toBe(2);

    // When it runs again at 12:09:59, with a changed score
    $this->travelTo(new DateTimeImmutable('2026-10-21 12:09:59 UTC'));
    Product::query()->update(['hot_score' => 100]);
    $second = $action->handle();

    // Then nothing was inserted and the first snapshot is untouched
    expect($second)->toBe(0)->and(DB::table('rank_snapshots')->count())->toBe(2);
    expect(array_column(snapshotRows(), 'score'))->toBe([3.0, 2.0]);
});

it('writes a new snapshot in the next window, so history grows', function () {
    // Given a snapshot at 12:00
    $product = liveToday(3.0);
    app(SnapshotRanks::class)->handle();

    // When it runs at 12:10:00
    $this->travelTo(new DateTimeImmutable('2026-10-21 12:10:00 UTC'));
    $product->forceFill(['hot_score' => 5.0])->save();
    $inserted = app(SnapshotRanks::class)->handle();

    // Then the product has two rows, one per mark
    expect($inserted)->toBe(1);
    $rows = snapshotRows();
    expect(array_column($rows, 'captured_at'))->toBe(['2026-10-21 12:00:00', '2026-10-21 12:10:00'])
        ->and(array_column($rows, 'score'))->toBe([3.0, 5.0]);
});

it('floors captured_at to the ten-minute mark', function (string $now, string $expected) {
    // Given one product
    liveToday(1.0);

    // When the action runs at the given instant
    app(SnapshotRanks::class)->handle(new CarbonImmutable($now, 'UTC'));

    // Then the row sits on the boundary
    expect(array_column(snapshotRows(), 'captured_at'))->toBe([$expected]);
})->with([
    ['2026-10-21 00:07:59', '2026-10-21 00:00:00'],
    ['2026-10-21 00:10:00', '2026-10-21 00:10:00'],
    ['2026-10-21 12:59:59.999999', '2026-10-21 12:50:00'],
    ['2026-10-21 23:59:59', '2026-10-21 23:50:00'],
]);

it('floors in UTC whatever the zone of the given instant', function () {
    // Given one product and an instant expressed in +05:30 (which is 12:07 UTC)
    liveToday(1.0);

    // When the action runs
    app(SnapshotRanks::class)->handle(new CarbonImmutable('2026-10-21 17:37:59', '+05:30'));

    // Then the mark is the UTC one
    expect(array_column(snapshotRows(), 'captured_at'))->toBe(['2026-10-21 12:00:00']);
});

it('snapshots only the visible launches of today', function () {
    // Given a visible one, a suspended maker's, and products that must not appear
    $visible = liveToday(5.0);
    $suspended = liveToday(4.0, 0, ['maker_id' => User::factory()->suspended()->create()->id]);
    Product::factory()->takenDown()->create(['launch_date' => '2026-10-21', 'hot_score' => 99]);
    Product::factory()->pending()->create(['hot_score' => 98]);
    liveToday(97.0, 0, ['maker_id' => User::factory()->create(['deleted_at' => now()])->id]);
    Product::factory()->live('2026-10-22')->create(['hot_score' => 96]);
    Product::factory()->live('2026-10-20')->create(['hot_score' => 95]);

    // When the ranks are snapshotted
    $inserted = app(SnapshotRanks::class)->handle();

    // Then only the two visible launches of today are ranked
    expect($inserted)->toBe(2)->and(array_column(snapshotRows(), 'product_id'))->toBe([$visible->id, $suspended->id]);
});

it('writes nothing and succeeds on an empty day', function () {
    // Given no launches today
    Product::factory()->live('2026-10-20')->create();

    // When the ranks are snapshotted
    $action = app(SnapshotRanks::class);

    // Then zero rows were written and nothing failed
    expect($action->handle())->toBe(0)->and($action->failed)->toBe(0)->and(DB::table('rank_snapshots')->count())->toBe(0);
});

it('inserts in chunks, all in one transaction, with ON CONFLICT DO NOTHING', function () {
    // Given five products and a chunk size of two
    foreach (range(1, 5) as $i) {
        liveToday((float) $i);
    }
    $inserts = [];
    $levels = [];
    DB::listen(function ($query) use (&$inserts, &$levels) {
        if (str_starts_with($query->sql, 'insert into "rank_snapshots"')) {
            $inserts[] = $query->sql;
            $levels[] = DB::transactionLevel();
        }
    });

    $baseline = DB::transactionLevel();

    // When the ranks are snapshotted
    $inserted = app()->make(SnapshotRanks::class, ['chunk' => 2])->handle();

    // Then three statements ran, each inside a transaction opened by the action, and all five rows are present
    expect($inserted)->toBe(5)->and($inserts)->toHaveCount(3);
    expect($levels)->each->toBeGreaterThan($baseline);
    expect($inserts)->each->toContain('on conflict do nothing');
    expect(array_column(snapshotRows(), 'rank'))->toBe([1, 2, 3, 4, 5]);
});

it('leaves no half snapshot when a later chunk fails, reports it and counts it', function () {
    // Given five products, a chunk size of two and a second insert that fails
    Exceptions::fake();
    foreach (range(1, 5) as $i) {
        liveToday((float) $i);
    }
    $count = 0;
    DB::listen(function ($query) use (&$count) {
        if (str_starts_with($query->sql, 'insert into "rank_snapshots"') && ++$count === 2) {
            throw new RuntimeException('boom');
        }
    });
    $action = app()->make(SnapshotRanks::class, ['chunk' => 2]);

    // When the ranks are snapshotted
    $inserted = $action->handle();

    // Then nothing was written (the first chunk rolled back too), and the failure is counted and reported
    expect($inserted)->toBe(0)->and($action->failed)->toBe(1)->and(DB::table('rank_snapshots')->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);

    // And the next run (no failure now) writes the whole snapshot and resets the counter
    expect($action->handle())->toBe(5)->and($action->failed)->toBe(0);
});

it('caps a run at 500 products and ranks the best ones', function () {
    // Given 502 products with distinct scores
    expect(SnapshotRanks::MAX)->toBe(500);
    foreach (range(1, 502) as $i) {
        liveToday((float) $i);
    }

    // When the ranks are snapshotted
    $inserted = app(SnapshotRanks::class)->handle();

    // Then 500 rows exist, ranks 1..500, and the two lowest scores are out
    expect($inserted)->toBe(500)->and(DB::table('rank_snapshots')->count())->toBe(500);
    expect(DB::table('rank_snapshots')->min('rank'))->toBe(1)->and(DB::table('rank_snapshots')->max('rank'))->toBe(500);
    expect(DB::table('rank_snapshots')->min('score'))->toBe(3.0);
});

it('lets the cap be overridden through the constructor', function () {
    // Given four products and a cap of two
    $a = liveToday(4.0);
    $b = liveToday(3.0);
    liveToday(2.0);
    liveToday(1.0);

    // When the ranks are snapshotted
    $inserted = app()->make(SnapshotRanks::class, ['max' => 2])->handle();

    // Then only the top two are written
    expect($inserted)->toBe(2)->and(array_column(snapshotRows(), 'product_id'))->toBe([$a->id, $b->id]);
});

it('takes the day from the run clock at the midnight edge', function () {
    // Given a product of the 20th and one of the 21st
    $yesterday = Product::factory()->live('2026-10-20')->create(['hot_score' => 7.0]);
    $today = liveToday(2.0);

    // When it runs at 23:55 on the 20th and at 00:00 on the 21st
    $this->travelTo(new DateTimeImmutable('2026-10-20 23:55:00 UTC'));
    app(SnapshotRanks::class)->handle();
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:00 UTC'));
    app(SnapshotRanks::class)->handle();

    // Then 23:50 holds the old day's list and 00:00 the new day's
    expect(array_column(snapshotRows('2026-10-20 23:50:00+00'), 'product_id'))->toBe([$yesterday->id]);
    expect(array_column(snapshotRows('2026-10-21 00:00:00+00'), 'product_id'))->toBe([$today->id]);
});

it('registers the command every ten minutes, without overlapping, on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:snapshot-ranks'));

    // When it is inspected
    // Then there is one entry, every ten minutes, protected against overlap and duplicate servers
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('*/10 * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
    expect($event->expiresAt)->toBe(15);
});

it('prints the count and succeeds, then prints 0 for the same window', function () {
    // Given two products
    liveToday(2.0);
    liveToday(1.0);

    // When the command runs twice
    // Then it snapshots two, then nothing
    $this->artisan('launchpad:snapshot-ranks')->expectsOutput('Snapshotted 2 product(s).')->assertSuccessful();
    $this->artisan('launchpad:snapshot-ranks')->expectsOutput('Snapshotted 0 product(s).')->assertSuccessful();
});

it('prints 0 and succeeds on an empty day', function () {
    // Given no launches
    // When the command runs
    // Then it succeeds
    $this->artisan('launchpad:snapshot-ranks')->expectsOutput('Snapshotted 0 product(s).')->assertSuccessful();
});

it('exits non-zero when the write fails', function () {
    // Given a product and an insert that fails
    Exceptions::fake();
    liveToday(1.0);
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'insert into "rank_snapshots"')) {
            throw new RuntimeException('boom');
        }
    });

    // When the command runs
    // Then it reports zero rows, prints the error and fails, and the exception was reported
    $this->artisan('launchpad:snapshot-ranks')->expectsOutput('Snapshotted 0 product(s).')
        ->expectsOutput('The snapshot failed and nothing was written; the next run retries.')->assertFailed();
    expect(DB::table('rank_snapshots')->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('counts a read failure, reports it and exits non-zero', function () {
    // Given a ranking whose read throws
    Exceptions::fake();
    $this->app->instance(TodayRanking::class, new class extends TodayRanking
    {
        public function ranking(CarbonImmutable $now, int $limit = self::LIMIT): Collection
        {
            throw new RuntimeException('read boom');
        }
    });

    // When the action and the command run
    $action = app(SnapshotRanks::class);
    $inserted = $action->handle();
    $this->artisan('launchpad:snapshot-ranks')->expectsOutput('Snapshotted 0 product(s).')->assertFailed();

    // Then it wrote nothing, counted one failure and reported it
    expect($inserted)->toBe(0)->and($action->failed)->toBe(1)->and(DB::table('rank_snapshots')->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('succeeds with no rows at 00:00:00 when nothing is live yet', function () {
    // Given midnight, with a scheduled (not yet live) launch for today
    Product::factory()->scheduled('2026-10-21')->create();
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:00 UTC'));

    // When the command runs
    // Then zero rows and success
    $this->artisan('launchpad:snapshot-ranks')->expectsOutput('Snapshotted 0 product(s).')->assertSuccessful();
    expect(DB::table('rank_snapshots')->count())->toBe(0);
});

it('keeps the old rows of a product taken down after the snapshot', function () {
    // Given a snapshot containing a product
    $product = liveToday(3.0);
    app(SnapshotRanks::class)->handle();

    // When it is taken down and the next window is snapshotted
    $product->forceFill(['status' => ProductStatus::TakenDown, 'taken_down_at' => now(), 'takedown_reason' => 'x'])->save();
    $this->travelTo(new DateTimeImmutable('2026-10-21 12:10:00 UTC'));
    $inserted = app(SnapshotRanks::class)->handle();

    // Then the old row stays and no new one is written
    expect($inserted)->toBe(0)->and(array_column(snapshotRows(), 'captured_at'))->toBe(['2026-10-21 12:00:00']);
});

it('ranking() returns the same id order as products() on tied data and loads no relations', function () {
    // Given ties on hot_score, ties on votes_count and a full tie
    liveToday(5.0, 1);
    liveToday(5.0, 3);
    liveToday(5.0, 3);
    liveToday(2.0, 0);
    liveToday(2.0, 0);
    liveToday(9.0, 0);
    $ranking = app(TodayRanking::class);
    $now = CarbonImmutable::now('UTC');

    // When both are read, counting queries for the lean one
    $full = $ranking->products($now)->pluck('id')->all();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $lean = $ranking->ranking($now);
    $queries = DB::getQueryLog();

    // Then the order is identical, one query ran, and only id, hot_score and votes_count are loaded
    expect($lean->pluck('id')->all())->toBe($full)->and($queries)->toHaveCount(1);
    expect(array_keys($lean->first()->getAttributes()))->toBe(['id', 'hot_score', 'votes_count']);
    expect($lean->first()->relationLoaded('maker'))->toBeFalse();
});
