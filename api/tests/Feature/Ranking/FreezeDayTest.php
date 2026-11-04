<?php

use App\Actions\Product\PublishLaunches;
use App\Actions\Ranking\BucketReader;
use App\Actions\Ranking\FinalOrder;
use App\Actions\Ranking\FreezeDay;
use App\Actions\Ranking\HotScore;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 00:05:00 UTC')));

function freezeBucket(Product $product, string $hourStart, int $votes): void
{
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => $hourStart.'+00', 'votes' => $votes, 'views' => 0]);
}

function frozenRows(string $day): array
{
    return DB::table('daily_rankings')->where('launch_date', $day)->orderBy('rank')->get()->map(fn ($r) => (array) $r)->all();
}

function finalScore(array $votesByHour): float
{
    return (new HotScore(0.9, 0.35))->score($votesByHour, 23);
}

it('ranks by final score, then votes, then id, and stores the scores', function () {
    // Given four launches on the 20th: two with equal score and votes, one with more votes in a late hour only
    $a = Product::factory()->live('2026-10-20')->create(['votes_count' => 5]);
    $b = Product::factory()->live('2026-10-20')->create(['votes_count' => 5]);
    $early = Product::factory()->live('2026-10-20')->create(['votes_count' => 3]);
    $late = Product::factory()->live('2026-10-20')->create(['votes_count' => 9]);
    foreach ([$a, $b] as $p) {
        freezeBucket($p, '2026-10-20 12:00:00', 5);
    }
    freezeBucket($early, '2026-10-20 00:00:00', 3);
    freezeBucket($late, '2026-10-20 22:00:00', 9);

    // When the day is frozen
    $frozen = app(FreezeDay::class)->handle();

    // Then the early bird leads, the tie is broken by id and the stored scores are the final scores
    expect($frozen)->toBe(1);
    $tie = collect([$a, $b])->pluck('id')->sort()->values()->all();
    $rows = frozenRows('2026-10-20');
    // 9 votes at hour 22 (about 3.35) beat 3 votes at hour 0 (about 1.22) beat 5 votes at hour 12 (about 0.50, tied, so by id)
    expect(array_column($rows, 'product_id'))->toBe([$late->id, $early->id, ...$tie]);
    expect(array_column($rows, 'rank'))->toBe([1, 2, 3, 4]);
    $byProduct = collect($rows)->keyBy('product_id');
    expect($byProduct[$a->id]['score'])->toEqualWithDelta(finalScore([12 => 5]), 1e-9)->and($byProduct[$late->id]['votes_count'])->toBe(9);
    expect($byProduct[$a->id]['frozen_at'])->not->toBeNull();
    expect((float) $a->fresh()->hot_score)->toEqualWithDelta(finalScore([12 => 5]), 1e-9);
});

it('breaks a full tie by product id', function () {
    // Given three products with the same score and votes
    $products = collect(range(1, 3))->map(fn () => Product::factory()->live('2026-10-20')->create(['votes_count' => 2]));

    // When the day is frozen
    app(FreezeDay::class)->handle();

    // Then ranks follow id order
    expect(array_column(frozenRows('2026-10-20'), 'product_id'))->toBe($products->pluck('id')->sort()->values()->all());
});

it('does not rank a product taken down before the freeze, but ranks one whose maker was deleted', function () {
    // Given a live product, a taken-down one and one by a deleted maker
    $live = Product::factory()->live('2026-10-20')->create();
    Product::factory()->takenDown()->create(['launch_date' => '2026-10-20']);
    $orphan = Product::factory()->live('2026-10-20')->create(['maker_id' => User::factory()->create(['deleted_at' => now()])->id]);

    // When the day is frozen
    app(FreezeDay::class)->handle();

    // Then visibility is a read filter: both live products are ranked
    expect(array_column(frozenRows('2026-10-20'), 'product_id'))->toEqualCanonicalizing([$live->id, $orphan->id]);
});

it('is idempotent: a second run freezes nothing and leaves the rows unchanged', function () {
    // Given a frozen day
    Product::factory()->live('2026-10-20')->create();
    expect(app(FreezeDay::class)->handle())->toBe(1);
    $before = frozenRows('2026-10-20');

    // When it runs again
    $second = app(FreezeDay::class)->handle();

    // Then nothing changed
    expect($second)->toBe(0)->and(frozenRows('2026-10-20'))->toBe($before);
});

it('catches up three missed days in order and skips today and the future', function () {
    // Given launches on three finished days, today and a scheduled tomorrow
    foreach (['2026-10-19', '2026-10-17', '2026-10-18'] as $day) {
        Product::factory()->live($day)->create();
    }
    Product::factory()->live('2026-10-21')->create();
    Product::factory()->scheduled('2026-10-22')->create();

    // When the freeze runs
    $frozen = app(FreezeDay::class)->handle();

    // Then the three days are frozen, in date order, and nothing else
    expect($frozen)->toBe(3);
    expect(DB::table('daily_rankings')->orderByRaw('ctid')->pluck('launch_date')->all())->toBe(['2026-10-17', '2026-10-18', '2026-10-19']); // insert order
    expect(DB::table('daily_rankings')->where('launch_date', '>=', '2026-10-20')->count())->toBe(0);
});

it('looks back 31 days by default, freezes at most 31 days per run, and --from widens the window', function () {
    // Given 33 finished days with one launch each (the two oldest are outside the default window)
    foreach (range(1, 33) as $i) {
        Product::factory()->live(now('UTC')->subDays($i)->toDateString())->create();
    }

    // When the freeze runs with the default window
    $first = app(FreezeDay::class)->handle();
    $again = app(FreezeDay::class)->handle();

    // Then only the 31 days inside the window are frozen (today - 31 included), once
    expect($first)->toBe(31)->and($again)->toBe(0);
    expect(DB::table('daily_rankings')->min('launch_date'))->toBe('2026-09-20');

    // And a backfill from further back picks up the older gaps
    $this->artisan('launchpad:freeze-day', ['--from' => '2026-09-01'])->expectsOutput('Froze 2 day(s).')->assertSuccessful();
    expect(DB::table('daily_rankings')->count())->toBe(33)->and(DB::table('daily_rankings')->min('launch_date'))->toBe('2026-09-18');
});

it('refuses a malformed --from', function (string $from) {
    // Given a finished day
    Product::factory()->live('2026-10-20')->create();

    // When the command gets a bad lower bound
    // Then it fails with a message and freezes nothing
    $this->artisan('launchpad:freeze-day', ['--from' => $from])->expectsOutput('--from must be a real date in the form YYYY-MM-DD.')->assertExitCode(2);
    expect(DB::table('daily_rankings')->count())->toBe(0);
})->with(['2026-02-31', '2026-2-3', 'abc', '20261001', '2026-10-01x', '']);

it('freezes a day only after it has been over for 60 seconds', function () {
    // Given a launch on the 20th
    Product::factory()->live('2026-10-20')->create();

    // When the freeze runs at 00:00:30 and then at 00:01:05 on the 21st
    $early = app(FreezeDay::class)->handle(CarbonImmutable::parse('2026-10-21 00:00:30 UTC'));
    $late = app(FreezeDay::class)->handle(CarbonImmutable::parse('2026-10-21 00:01:05 UTC'));

    // Then only the second run freezes it
    expect($early)->toBe(0)->and($late)->toBe(1);
});

it('never rewrites a frozen day, whatever happens to the products afterwards', function () {
    // Given a frozen day
    $product = Product::factory()->live('2026-10-20')->create(['votes_count' => 4]);
    freezeBucket($product, '2026-10-20 03:00:00', 4);
    $action = app(FreezeDay::class);
    $action->handle();
    $before = frozenRows('2026-10-20');
    $score = (float) $product->fresh()->hot_score;

    // When the product changes and the freeze runs again
    DB::table('products')->where('id', $product->id)->update(['votes_count' => 99, 'hot_score' => 500]);
    freezeBucket($product, '2026-10-20 05:00:00', 50);
    $second = $action->handle();

    // Then nothing is written: no day, no failure, same rows, and the live hot_score is not recomputed
    expect($second)->toBe(0)->and($action->failed)->toBe(0)->and(frozenRows('2026-10-20'))->toBe($before);
    expect((float) $product->fresh()->hot_score)->toBe(500.0)->and($score)->toBeGreaterThan(0.0);
});

it('appends a product that became live after the freeze, with the next rank, leaving existing rows alone', function () {
    // Given a frozen day with two products, and a third that is made live afterwards (with votes in its buckets)
    $first = Product::factory()->live('2026-10-20')->create(['votes_count' => 2]);
    $second = Product::factory()->live('2026-10-20')->create(['votes_count' => 1]);
    freezeBucket($first, '2026-10-20 10:00:00', 2);
    freezeBucket($second, '2026-10-20 10:00:00', 1);
    app(FreezeDay::class)->handle();
    $before = frozenRows('2026-10-20');
    $late = Product::factory()->live('2026-10-20')->create(['votes_count' => 30]);
    freezeBucket($late, '2026-10-20 10:00:00', 30);

    // When the freeze runs again, and once more
    $action = app(FreezeDay::class);
    $third = $action->handle();
    $after = frozenRows('2026-10-20');
    $fourth = $action->handle();

    // Then the late product is rank 3 (even with the best score), the old rows are identical, and a further run is a no-op
    expect($third)->toBe(1)->and($fourth)->toBe(0)->and($action->failed)->toBe(0);
    expect(array_slice($after, 0, 2))->toBe($before);
    expect(array_column($after, 'product_id'))->toBe([$first->id, $second->id, $late->id])->and(array_column($after, 'rank'))->toBe([1, 2, 3]);
    expect($after[2]['votes_count'])->toBe(30)->and((float) $late->fresh()->hot_score)->toEqualWithDelta(finalScore([10 => 30]), 1e-9);
    expect(frozenRows('2026-10-20'))->toBe($after);
});

it('appends several late products in final order after the last rank', function () {
    // Given a frozen day and three late arrivals with different scores
    $old = Product::factory()->live('2026-10-20')->create();
    app(FreezeDay::class)->handle();
    $weak = Product::factory()->live('2026-10-20')->create(['votes_count' => 1]);
    $strong = Product::factory()->live('2026-10-20')->create(['votes_count' => 8]);
    freezeBucket($weak, '2026-10-20 10:00:00', 1);
    freezeBucket($strong, '2026-10-20 10:00:00', 8);

    // When the freeze runs
    app(FreezeDay::class)->handle();

    // Then they follow in score order from rank 2
    expect(array_column(frozenRows('2026-10-20'), 'product_id'))->toBe([$old->id, $strong->id, $weak->id]);
});

it('ranks a product restored after the freeze at the next run', function () {
    // Given a product taken down before the freeze (so unranked), and a ranked one
    $ranked = Product::factory()->live('2026-10-20')->create();
    $down = Product::factory()->takenDown()->create(['launch_date' => '2026-10-20']);
    app(FreezeDay::class)->handle();
    expect(array_column(frozenRows('2026-10-20'), 'product_id'))->toBe([$ranked->id]);

    // When it is restored to live and the freeze runs
    DB::table('products')->where('id', $down->id)->update(['status' => 'live', 'taken_down_at' => null, 'takedown_reason' => null]);
    app(FreezeDay::class)->handle();

    // Then it is appended as rank 2
    expect(array_column(frozenRows('2026-10-20'), 'product_id'))->toBe([$ranked->id, $down->id]);
});

it('ranks the launch of a suspended maker once the scheduler releases it after the freeze', function () {
    // Given a frozen day, and a scheduled launch for that day whose maker is suspended (the scheduler holds it)
    $ranked = Product::factory()->live('2026-10-20')->create();
    app(FreezeDay::class)->handle();
    $held = Product::factory()->scheduled('2026-10-20')->create(['maker_id' => User::factory()->suspended()->create()->id]);
    expect(app(PublishLaunches::class)->handle())->toBe(0);
    expect(app(FreezeDay::class)->handle())->toBe(0);

    // When the maker is reinstated, the scheduler publishes it and the freeze runs
    DB::table('users')->where('id', $held->maker_id)->update(['suspended_at' => null, 'suspended_reason' => null]);
    expect(app(PublishLaunches::class)->handle())->toBe(1);
    $frozen = app(FreezeDay::class)->handle();

    // Then it is appended after the existing rank
    expect($frozen)->toBe(1)->and(array_column(frozenRows('2026-10-20'), 'product_id'))->toBe([$ranked->id, $held->id]);
});

it('finds candidates with a bounded anti-join on the primary key, inside the window', function () {
    // Given a finished day
    Product::factory()->live('2026-10-20')->create();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    // When the freeze runs
    app(FreezeDay::class)->handle();

    // Then the candidate query is bounded on both sides and anti-joins on the product too
    $candidate = collect($sql)->first(fn ($s) => str_contains($s, 'select distinct "launch_date"'));
    expect($candidate)->toContain('"launch_date" >= ?')->toContain('"launch_date" < ?')->toContain('not exists')
        ->toContain('"daily_rankings"."product_id" = "products"."id"')->toContain('limit 31');
});

it('writes rows and scores across insert chunks', function () {
    // Given three products and an action that inserts two rows at a time
    $products = collect(range(1, 3))->map(fn (int $i) => Product::factory()->live('2026-10-20')->create(['votes_count' => $i]));
    foreach ($products as $i => $product) {
        freezeBucket($product, '2026-10-20 10:00:00', $i + 1);
    }
    $action = new FreezeDay(app(FinalOrder::class), null, 2);

    // When it runs
    $action->handle();

    // Then all three are ranked 1..3 by score and every product got its final score
    $rows = frozenRows('2026-10-20');
    expect(array_column($rows, 'product_id'))->toBe([$products[2]->id, $products[1]->id, $products[0]->id])->and(array_column($rows, 'rank'))->toBe([1, 2, 3]);
    foreach ($products as $i => $product) {
        expect((float) $product->fresh()->hot_score)->toEqualWithDelta(finalScore([10 => $i + 1]), 1e-9);
    }
});

it('reads buckets across chunks of ids', function () {
    // Given five products with a bucket each and a reader that takes two ids per query
    $products = collect(range(1, 5))->map(fn (int $i) => Product::factory()->live('2026-10-20')->create());
    foreach ($products as $i => $product) {
        freezeBucket($product, '2026-10-20 07:00:00', $i + 1);
    }
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    // When the day is read
    $buckets = (new BucketReader(2))->forDay($products->pluck('id')->all(), CarbonImmutable::parse('2026-10-20 15:00:00 UTC'));

    // Then every product is there and three queries ran
    expect(array_map(fn ($b) => $b[7], $buckets))->toBe($products->mapWithKeys(fn ($p, $i) => [$p->id => $i + 1])->all());
    expect($queries)->toBe(3);
});

it('skips a day another worker froze after the candidate query (the re-check under the lock)', function () {
    // Given a finished day, and a listener that freezes it right after this run locked the products
    $product = Product::factory()->live('2026-10-20')->create();
    $done = false;
    DB::listen(function ($query) use (&$done, $product) {
        if (! $done && str_contains($query->sql, 'for update')) {
            $done = true;
            DB::table('daily_rankings')->insert(['launch_date' => '2026-10-20', 'product_id' => $product->id, 'rank' => 1, 'votes_count' => 0, 'score' => 0, 'frozen_at' => now()]);
        }
    });

    // When the freeze runs
    $action = app(FreezeDay::class);
    $frozen = $action->handle();

    // Then it recognises the day as done: no failure, no second insert
    expect($frozen)->toBe(0)->and($action->failed)->toBe(0)->and(DB::table('daily_rankings')->count())->toBe(1);
});

it('locks the day products in id order and waits for them (no skip locked)', function () {
    // Given a finished day
    Product::factory()->live('2026-10-20')->create();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    // When the freeze runs
    app(FreezeDay::class)->handle();

    // Then the lock statement is FOR UPDATE ordered by id, without SKIP LOCKED
    $locks = array_values(array_filter($sql, fn ($s) => str_contains($s, 'for update')));
    expect($locks)->toHaveCount(1);
    expect($locks[0])->toContain('order by "id" asc for update')->not->toContain('skip locked');
});

it('includes a vote committed at the last second of the day, and closes voting after midnight', function () {
    // Given a product launching on the 20th, a member, and a vote at 23:59:59
    signedInMember();
    $product = Product::factory()->live('2026-10-20')->create();
    $this->travelTo(new DateTimeImmutable('2026-10-20 23:59:59 UTC'));
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk();

    // When midnight passes, a second member tries to vote and the freeze runs
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:02:00 UTC'));
    $this->actingAs(User::factory()->create(), 'sanctum');
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertStatus(409)->assertJsonPath('code', 'voting_closed');
    app(FreezeDay::class)->handle();

    // Then the frozen row carries the last-second vote and the score of its bucket
    $row = frozenRows('2026-10-20')[0];
    expect($row['votes_count'])->toBe(1)->and($row['score'])->toEqualWithDelta(finalScore([23 => 1]), 1e-9);
});

it('rejects UPDATE, DELETE and TRUNCATE on daily_rankings with the guard exception', function (string $statement) {
    // Given a frozen row
    Product::factory()->live('2026-10-20')->create();
    app(FreezeDay::class)->handle();

    // When the statement runs inside a savepoint
    $state = null;
    $message = null;
    try {
        DB::transaction(fn () => DB::unprepared($statement));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
        $message = $e->errorInfo[2] ?? '';
    }

    // Then the guard raised and the row is still there
    expect($state)->toBe('P0001')->and($message)->toContain('daily_rankings is insert-only');
    expect(DB::table('daily_rankings')->count())->toBe(1);
})->with([
    'update' => 'UPDATE daily_rankings SET rank = 2',
    'delete' => 'DELETE FROM daily_rankings',
    'truncate' => 'TRUNCATE TABLE daily_rankings',
    'truncate cascade' => 'TRUNCATE TABLE daily_rankings CASCADE',
]);

it('has the three guard triggers', function () {
    // Given the migrated schema
    // When the triggers on the table are listed
    $triggers = DB::table('pg_trigger')->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
        ->where('pg_class.relname', 'daily_rankings')->where('pg_trigger.tgisinternal', false)->pluck('tgname')->sort()->values()->all();

    // Then update, delete and truncate are guarded
    expect($triggers)->toBe(['daily_rankings_no_delete', 'daily_rankings_no_truncate', 'daily_rankings_no_update']);
});

it('reports a failing day, still freezes the next one and exits non-zero', function () {
    // Given two finished days and an insert into daily_rankings that fails for the first day only
    Exceptions::fake();
    Product::factory()->live('2026-10-19')->create();
    $second = Product::factory()->live('2026-10-20')->create();
    $failedOnce = false;
    DB::listen(function ($query) use (&$failedOnce) {
        if (! $failedOnce && str_starts_with($query->sql, 'insert into "daily_rankings"')) {
            $failedOnce = true;
            throw new RuntimeException('boom');
        }
    });

    // When the command runs
    $this->artisan('launchpad:freeze-day')->expectsOutput('Froze 1 day(s).')->assertFailed();

    // Then the first day rolled back whole, the second is frozen, and the failure was reported
    expect(DB::table('daily_rankings')->where('launch_date', '2026-10-19')->count())->toBe(0);
    expect(array_column(frozenRows('2026-10-20'), 'product_id'))->toBe([$second->id]);
    Exceptions::assertReported(RuntimeException::class);
});

it('counts failures on the action and retries the failed day next run', function () {
    // Given a day whose insert fails once
    Exceptions::fake();
    Product::factory()->live('2026-10-19')->create();
    $failedOnce = false;
    DB::listen(function ($query) use (&$failedOnce) {
        if (! $failedOnce && str_starts_with($query->sql, 'insert into "daily_rankings"')) {
            $failedOnce = true;
            throw new RuntimeException('boom');
        }
    });
    $action = app(FreezeDay::class);

    // When it runs twice
    $first = $action->handle();
    $failedFirst = $action->failed;
    $second = $action->handle();

    // Then the first run failed and counted nothing, the second froze it and resets the counter
    expect($first)->toBe(0)->and($failedFirst)->toBe(1)->and($second)->toBe(1)->and($action->failed)->toBe(0);
});

it('prints the count and succeeds', function () {
    // Given one finished day
    Product::factory()->live('2026-10-20')->create();

    // When the command runs twice
    // Then it freezes once, then nothing
    $this->artisan('launchpad:freeze-day')->expectsOutput('Froze 1 day(s).')->assertSuccessful();
    $this->artisan('launchpad:freeze-day')->expectsOutput('Froze 0 day(s).')->assertSuccessful();
});

it('registers the command every minute, without overlapping, on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:freeze-day'));

    // When it is inspected
    // Then there is one entry, every minute, protected against overlap and duplicate servers
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
