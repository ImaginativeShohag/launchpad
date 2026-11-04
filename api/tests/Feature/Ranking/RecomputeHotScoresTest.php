<?php

use App\Actions\Ranking\HotScore;
use App\Actions\Ranking\RecomputeHotScores;
use App\Enums\SettingKey;
use App\Events\RankingUpdated;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-20 05:30:00 UTC')));
afterEach(fn () => Product::flushEventListeners());

function bucket(Product $product, string $hourStart, int $votes): void
{
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => $hourStart.'+00', 'votes' => $votes, 'views' => 0]);
}

function expectedScore(array $votesByHour, int $hour): float
{
    return (new HotScore(0.9, 0.35))->score($votesByHour, $hour);
}

it('reads the decay and early bonus from the settings', function () {
    // Given the default settings, then a changed decay exponent
    $settings = app(Settings::class);
    $default = HotScore::fromSettings($settings)->score([0 => 10], 10);
    $admin = User::factory()->create();
    $settings->set(SettingKey::RankingDecayExponent, '1.2', $admin);

    // When a score is built from the settings again
    $changed = HotScore::fromSettings($settings)->score([0 => 10], 10);

    // Then it matches the explicit constants for each
    expect($default)->toEqualWithDelta((new HotScore(0.9, 0.35))->score([0 => 10], 10), 1e-12);
    expect($changed)->toEqualWithDelta((new HotScore(1.2, 0.35))->score([0 => 10], 10), 1e-12);
    expect($changed)->not->toBe($default);
});

it('scores only live products launching today and ignores buckets of other days', function () {
    // Given today's live product with buckets today and yesterday, plus yesterday's live, tomorrow's scheduled, taken-down and pending products
    $today = Product::factory()->live('2026-10-20')->create();
    bucket($today, '2026-10-20 00:00:00', 4);
    bucket($today, '2026-10-20 05:00:00', 2);
    bucket($today, '2026-10-19 23:00:00', 50);
    bucket($today, '2026-10-21 00:00:00', 70);
    $others = [
        Product::factory()->live('2026-10-19')->create(),
        Product::factory()->scheduled('2026-10-21')->create(),
        Product::factory()->takenDown()->create(),
        Product::factory()->pending()->create(),
    ];
    foreach ($others as $other) {
        bucket($other, '2026-10-20 00:00:00', 9);
    }

    // When the recompute runs
    $count = app(RecomputeHotScores::class)->handle();

    // Then only today's product has a score, from today's buckets only
    expect($count)->toBe(1);
    expect($today->fresh()->hot_score)->toEqualWithDelta(expectedScore([0 => 4, 5 => 2], 5), 1e-9);
    foreach ($others as $other) {
        expect($other->fresh()->hot_score)->toBe(0.0);
    }
});

it('leaves a product without buckets at zero and counts it as unchanged', function () {
    // Given a live product with no buckets
    Event::fake([RankingUpdated::class]);
    $product = Product::factory()->live('2026-10-20')->create();

    // When the recompute runs
    $count = app(RecomputeHotScores::class)->handle();

    // Then nothing is written or announced
    expect($count)->toBe(0)->and($product->fresh()->hot_score)->toBe(0.0);
    Event::assertNotDispatched(RankingUpdated::class);
});

it('writes a changed score without touching updated_at, and skips an unchanged one', function () {
    // Given a product with a bucket and a known updated_at
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 00:00:00', 3);
    DB::table('products')->where('id', $product->id)->update(['updated_at' => '2026-10-01 12:00:00']);
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (str_starts_with($query->sql, 'update "products"')) {
            $writes[] = $query->sql;
        }
    });

    // When the recompute runs twice at the same hour
    $first = app(RecomputeHotScores::class)->handle();
    $second = app(RecomputeHotScores::class)->handle();

    // Then the first wrote the score only, the second wrote nothing, and updated_at never moved
    expect($first)->toBe(1)->and($second)->toBe(0);
    expect($writes)->toHaveCount(1);
    $fresh = $product->fresh();
    expect($fresh->hot_score)->toEqualWithDelta(expectedScore([0 => 3], 5), 1e-9);
    expect($fresh->updated_at->utc()->toDateTimeString())->toBe('2026-10-01 12:00:00');
});

it('re-scores as the hour moves on (decay)', function () {
    // Given a scored product and a clock one hour later
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 05:00:00', 8);
    app(RecomputeHotScores::class)->handle();
    $before = $product->fresh()->hot_score;
    $this->travelTo(new DateTimeImmutable('2026-10-20 06:30:00 UTC'));

    // When the recompute runs again
    $count = app(RecomputeHotScores::class)->handle();

    // Then the older vote counts for less
    expect($count)->toBe(1)->and($product->fresh()->hot_score)->toBeLessThan($before);
});

it('works through more than one batch, advancing the id cursor, and terminates', function () {
    // Given five scoring products and a batch size of two
    $products = Product::factory()->live('2026-10-20')->count(5)->create()->sortBy('id')->values();
    foreach ($products as $product) {
        bucket($product, '2026-10-20 05:00:00', 2);
    }
    $locking = [];
    DB::listen(function ($query) use (&$locking) {
        if (str_contains($query->sql, 'skip locked')) {
            $locking[] = $query->bindings;
        }
    });

    // When the recompute runs with that batch size
    $count = app()->make(RecomputeHotScores::class, ['batch' => 2])->handle();

    // Then all five are scored once, in three locking selects, each after the previous batch's last id
    expect($count)->toBe(5);
    expect(Product::query()->where('hot_score', '>', 0)->count())->toBe(5);
    expect($locking)->toHaveCount(3);
    expect($locking[0])->toContain('')->and($locking[1])->toContain($products[1]->id)->and($locking[2])->toContain($products[3]->id);
});

it('loads the buckets of a whole batch in one query', function () {
    // Given three scoring products in one batch
    foreach (Product::factory()->live('2026-10-20')->count(3)->create() as $product) {
        bucket($product, '2026-10-20 05:00:00', 2);
    }
    $reads = 0;
    DB::listen(function ($query) use (&$reads) {
        $reads += str_contains($query->sql, 'product_hourly_stats') ? 1 : 0;
    });

    // When the recompute runs
    app(RecomputeHotScores::class)->handle();

    // Then one query read the buckets
    expect($reads)->toBe(1);
});

it('treats a huge score as unchanged within the relative tolerance and fires nothing the second time', function () {
    // Given a product whose score is around a million
    Event::fake([RankingUpdated::class]);
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 05:00:00', 2_000_000);
    expect(app(RecomputeHotScores::class)->handle())->toBe(1);
    expect($product->fresh()->hot_score)->toBeGreaterThan(1e5);

    // When the recompute runs again at the same hour
    $second = app(RecomputeHotScores::class)->handle();

    // Then nothing is rewritten and no second event fires
    expect($second)->toBe(0);
    Event::assertDispatchedTimes(RankingUpdated::class, 1);
});

it('counts a batch that fails as a whole, stops the run and announces nothing', function () {
    // Given a scoring product and a locking select that throws
    Event::fake([RankingUpdated::class]);
    Exceptions::fake();
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 05:00:00', 2);
    Product::retrieved(function () {
        throw new RuntimeException('batch broke');
    });

    // When the recompute runs
    $action = app(RecomputeHotScores::class);
    $count = $action->handle();

    // Then nothing was scored, one failure counted, the error reported and no event fired
    expect($count)->toBe(0)->and($action->failed)->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
    Event::assertNotDispatched(RankingUpdated::class);
    expect((float) DB::table('products')->where('id', $product->id)->value('hot_score'))->toBe(0.0);
});

it('exits non-zero when a whole batch fails', function () {
    // Given a locking select whose rows cannot be hydrated
    Exceptions::fake();
    Product::factory()->live('2026-10-20')->create();
    Product::retrieved(function () {
        throw new RuntimeException('batch broke');
    });

    // When the command runs
    // Then it reports zero scores and fails
    $this->artisan('launchpad:recompute-hot-scores')->expectsOutput('Recomputed 0 score(s).')->assertFailed();
});

it('keeps scoring the others when one product fails, even on a SQL error (savepoint)', function () {
    // Given three scoring products and an update that fails with a SQL error for the middle one
    Exceptions::fake();
    $products = Product::factory()->live('2026-10-20')->count(3)->create()->sortBy('id')->values();
    foreach ($products as $product) {
        bucket($product, '2026-10-20 05:00:00', 2);
    }
    $bad = $products[1]->id;
    Product::updating(function (Product $product) use ($bad) {
        if ($product->id === $bad) {
            DB::statement('select 1/0');
        }
    });

    // When the recompute runs
    $action = app(RecomputeHotScores::class);
    $count = $action->handle();

    // Then the other two are scored, the failure is counted and reported
    expect($count)->toBe(2)->and($action->failed)->toBe(1);
    expect($products[0]->fresh()->hot_score)->toBeGreaterThan(0.0);
    expect($products[1]->fresh()->hot_score)->toBe(0.0);
    expect($products[2]->fresh()->hot_score)->toBeGreaterThan(0.0);
    Exceptions::assertReported(QueryException::class);
});

it('builds the locking select with id order, skip locked and the id cursor binding', function () {
    // Given a recorder of queries and one scoring product
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 05:00:00', 1);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When the recompute runs
    app(RecomputeHotScores::class)->handle();

    // Then the selection orders by id and uses SKIP LOCKED
    $select = collect($queries)->first(fn ($sql) => str_contains($sql, 'for update'));
    expect($select)->toContain('order by "id" asc')->toContain('skip locked')->toContain('"status" = ?')->toContain('"launch_date" = ?')->toContain('"id" > ?');
});

it('does not score the old day when the lock wait crosses midnight', function () {
    // Given a product launching on the 20th, selected at 23:59:59 while the clock passes midnight right after the locking select
    $product = Product::factory()->live('2026-10-20')->create(['hot_score' => 7.0]);
    bucket($product, '2026-10-20 23:00:00', 5);
    $this->travelTo(new DateTimeImmutable('2026-10-20 23:59:59 UTC'));
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'skip locked')) {
            $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:01 UTC'));
        }
    });

    // When the recompute runs
    $count = app(RecomputeHotScores::class)->handle();

    // Then the clock read after the lock says the 21st, so the 20th's product is not scored
    expect($count)->toBe(0)->and($product->fresh()->hot_score)->toBe(7.0);
});

it('stops scoring a day once it is over', function () {
    // Given a product scored during its day
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 23:00:00', 5);
    $this->travelTo(new DateTimeImmutable('2026-10-20 23:59:59 UTC'));
    $first = app(RecomputeHotScores::class)->handle();
    $scored = $product->fresh()->hot_score;
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:01 UTC'));

    // When the next run happens after midnight
    $second = app(RecomputeHotScores::class)->handle();

    // Then the finished day keeps its last score
    expect($first)->toBe(1)->and($second)->toBe(0);
    expect($product->fresh()->hot_score)->toBe($scored);
});

it('uses the injected clock for the day and the hour', function () {
    // Given a product launching on the 20th and an injected instant on the 20th at 10:00
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 09:00:00', 6);

    // When the recompute runs with that instant
    app(RecomputeHotScores::class)->handle(CarbonImmutable::parse('2026-10-20 10:15:00 UTC'));

    // Then age is measured to hour 10
    expect($product->fresh()->hot_score)->toEqualWithDelta(expectedScore([9 => 6], 10), 1e-9);
});

it('picks up a changed ranking setting on the next run', function () {
    // Given a scored product, then a larger decay exponent saved by an admin
    $product = Product::factory()->live('2026-10-20')->create();
    bucket($product, '2026-10-20 00:00:00', 10);
    app(RecomputeHotScores::class)->handle();
    $before = $product->fresh()->hot_score;
    app(Settings::class)->set(SettingKey::RankingDecayExponent, '2', User::factory()->create());

    // When the recompute runs again
    $count = app(RecomputeHotScores::class)->handle();

    // Then the new exponent is used
    expect($count)->toBe(1);
    expect($product->fresh()->hot_score)->toEqualWithDelta((new HotScore(2.0, 0.35))->score([0 => 10], 5), 1e-9);
    expect($product->fresh()->hot_score)->not->toBe($before);
});

it('fires RankingUpdated once when something changed, and not when nothing did', function () {
    // Given a scoring product
    Event::fake([RankingUpdated::class]);
    $product = Product::factory()->live('2026-10-20')->create();
    Product::factory()->live('2026-10-20')->count(2)->create();
    bucket($product, '2026-10-20 05:00:00', 1);

    // When the recompute runs twice with a batch of one
    app()->make(RecomputeHotScores::class, ['batch' => 1])->handle();
    app()->make(RecomputeHotScores::class, ['batch' => 1])->handle();

    // Then exactly one event was dispatched, for the day
    Event::assertDispatchedTimes(RankingUpdated::class, 1);
    Event::assertDispatched(RankingUpdated::class, fn (RankingUpdated $e) => $e->day === '2026-10-20');
});

it('is an after-commit event that carries the day only', function () {
    // Given the event class
    // When its contract is inspected
    // Then it is dispatched after commit and has no member or product data
    expect(new RankingUpdated('2026-10-20'))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect(array_keys(get_object_vars(new RankingUpdated('2026-10-20'))))->toBe(['day']);
});

it('runs a real listener once per run and survives a throwing one', function () {
    // Given a real recording listener and a real throwing one, and three products in batches of one
    Exceptions::fake();
    $seen = [];
    Event::listen(RankingUpdated::class, function (RankingUpdated $event) use (&$seen) {
        $seen[] = $event->day;
    });
    Event::listen(RankingUpdated::class, function () {
        throw new RuntimeException('listener broke');
    });
    foreach (Product::factory()->live('2026-10-20')->count(3)->create() as $product) {
        bucket($product, '2026-10-20 05:00:00', 1);
    }

    // When the recompute runs
    $action = app()->make(RecomputeHotScores::class, ['batch' => 1]);
    $count = $action->handle();

    // Then every product was scored, the listener ran once, and the throw was reported, not raised
    expect($count)->toBe(3)->and($action->failed)->toBe(0)->and($seen)->toBe(['2026-10-20']);
    expect(Product::query()->where('hot_score', '>', 0)->count())->toBe(3);
    Exceptions::assertReported(RuntimeException::class);
});

it('prints the count and succeeds', function () {
    // Given two scoring products
    foreach (Product::factory()->live('2026-10-20')->count(2)->create() as $product) {
        bucket($product, '2026-10-20 05:00:00', 1);
    }

    // When the command runs
    // Then it reports two and exits zero
    $this->artisan('launchpad:recompute-hot-scores')->expectsOutput('Recomputed 2 score(s).')->assertSuccessful();
});

it('exits non-zero and says how many failed', function () {
    // Given two scoring products and an update that fails for one
    Exceptions::fake();
    $products = Product::factory()->live('2026-10-20')->count(2)->create()->sortBy('id')->values();
    foreach ($products as $product) {
        bucket($product, '2026-10-20 05:00:00', 1);
    }
    Product::updating(function (Product $product) use ($products) {
        if ($product->id === $products[0]->id) {
            DB::statement('select 1/0');
        }
    });

    // When the command runs
    $this->artisan('launchpad:recompute-hot-scores')
        ->expectsOutput('Recomputed 1 score(s).')
        ->expectsOutput('1 product(s) failed and keep their old score until the next run.')
        ->assertFailed();
});

it('registers the command every minute, without overlapping, on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:recompute-hot-scores'));

    // When it is inspected
    // Then there is exactly one entry, every minute, protected against overlap and duplicate servers
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
