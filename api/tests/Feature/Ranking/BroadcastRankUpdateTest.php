<?php

use App\Actions\Ranking\TodayRanking;
use App\Events\RankingUpdated;
use App\Events\RankUpdated;
use App\Listeners\BroadcastRankUpdate;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;

/** A generous estimate of the request line and headers the Pusher client sends in front of the body (about 500 bytes measured; 8.8 KB body + headers is about 9.3 KB). */
const BRK_HEADER_ESTIMATE = 700;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 10:30:00 UTC')));

function brkProduct(float $score, int $votes = 0, array $attributes = []): Product
{
    return Product::factory()->live('2026-10-21')->create(['hot_score' => $score, 'votes_count' => $votes] + $attributes);
}

/** Gives a product a vote bucket for the current hour, so the recompute really changes its score. */
function brkBucket(Product $product, int $votes): void
{
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => '2026-10-21 10:00:00+00', 'votes' => $votes, 'views' => 0]);
}

/** @return list<array{path: string, body: array<string, mixed>, raw: string}> */
function brkSent(): array
{
    return array_map(
        fn ($request) => ['path' => $request->getUri()->getPath(), 'body' => json_decode((string) $request->getBody(), true), 'raw' => (string) $request->getBody()],
        array_values(app('test.reverb.requests')->getArrayCopy()),
    );
}

function brkClear(): void
{
    app('test.reverb.requests')->exchangeArray([]);
}

function brkFailingBroadcaster(): Broadcaster
{
    return new class implements Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('Reverb is down');
        }
    };
}

it('broadcasts exactly one rank.updated in the current order after a recompute that changed scores', function () {
    // Given three live products today whose scores are stale, with different vote counts
    // (vote counts run against the scores, so sorting by votes or by id would give another order)
    $low = brkProduct(0.0, 7);
    $high = brkProduct(0.0, 1);
    $mid = brkProduct(0.0, 3);
    brkBucket($low, 1);
    brkBucket($high, 9);
    brkBucket($mid, 4);
    brkClear();

    // When the real recompute command runs (real queued listener on the sync queue, stubbed Pusher client)
    $this->artisan('launchpad:recompute-hot-scores')->assertSuccessful();

    // Then one event call went out, ordered by the new scores, ranks 1..3 and the current counts
    $sent = brkSent();
    expect($sent)->toHaveCount(1);
    expect($sent[0]['path'])->toEndWith('/events')->and($sent[0]['body']['name'])->toBe('rank.updated')->and($sent[0]['body']['channel'])->toBe('today');
    $data = json_decode($sent[0]['body']['data'], true);
    expect($data)->toBe(['date' => '2026-10-21', 'items' => [
        ['product_id' => $high->id, 'rank' => 1, 'votes_count' => 1],
        ['product_id' => $mid->id, 'rank' => 2, 'votes_count' => 3],
        ['product_id' => $low->id, 'rank' => 3, 'votes_count' => 7],
    ]]);
});

it('broadcasts nothing when the recompute changed no score', function () {
    // Given a product whose stored score already matches its (empty) buckets, and a reporter that records
    Exceptions::fake();
    brkProduct(0.0, 4);
    brkClear();

    // When the command runs
    $this->artisan('launchpad:recompute-hot-scores')->assertSuccessful();

    // Then no RankingUpdated fired, nothing was sent and no swallowed error hides behind that
    expect(brkSent())->toBe([]);
    Exceptions::assertNothingReported();
});

it('sends only date, product_id, rank and votes_count and no scores, names or member data', function () {
    // Given a ranked product with a maker
    $product = brkProduct(5.5, 2);
    brkClear();

    // When the listener handles the event
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then the HTTP body carries exactly those keys on both levels
    $sent = brkSent();
    expect($sent)->toHaveCount(1);
    $data = json_decode($sent[0]['body']['data'], true);
    expect(array_keys($data))->toBe(['date', 'items'])->and(array_keys($data['items'][0]))->toBe(['product_id', 'rank', 'votes_count']);
    $raw = $sent[0]['body']['data'];
    foreach (['hot_score', 'name', 'tagline', 'slug', 'maker', 'user', 'email', $product->maker_id, (string) $product->name] as $needle) {
        expect($raw)->not->toContain($needle);
    }
});

it('ranks at most 100 products', function () {
    // Given 103 visible products
    foreach (range(1, 103) as $i) {
        brkProduct($i / 10, 104 - $i); // the better the score, the fewer the votes
    }
    brkClear();

    // When the listener runs
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then exactly 100 items, ranks 1..100, best first
    $items = json_decode(brkSent()[0]['body']['data'], true)['items'];
    expect($items)->toHaveCount(100)->and(array_column($items, 'rank'))->toBe(range(1, 100))
        ->and($items[0]['votes_count'])->toBe(1)->and($items[99]['votes_count'])->toBe(100);
});

it('keeps a full top-100 payload with huge counts well below the 10,000 byte Reverb limit', function () {
    // Given 100 products with the largest possible counts
    foreach (range(1, 100) as $i) {
        brkProduct($i, 2_147_483_647);
    }
    brkClear();

    // When the listener runs
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then the whole HTTP body (envelope, escaped data) plus the request line and headers fits Reverb's max_request_size
    $sent = brkSent();
    expect($sent)->toHaveCount(1);
    expect(strlen($sent[0]['raw']))->toBeLessThan(config('reverb.servers.reverb.max_request_size') - BRK_HEADER_ESTIMATE);
});

it('declares a public today channel, publishes now, the rank.updated name and exactly date and items', function () {
    // Given an event
    $event = new RankUpdated('2026-10-21', [['product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'rank' => 1, 'votes_count' => 7]]);

    // When asked where, what and with which data it goes out
    $channels = $event->broadcastOn();

    // Then the channel is a plain public one named today
    expect($channels)->toHaveCount(1)->and($channels[0])->toBeInstanceOf(Channel::class)
        ->not->toBeInstanceOf(PrivateChannel::class)->not->toBeInstanceOf(PresenceChannel::class);
    expect($channels[0]->name)->toBe('today')->and($event->broadcastAs())->toBe('rank.updated');
    expect(array_keys($event->broadcastWith()))->toBe(['date', 'items']);
    expect(array_keys(get_object_vars($event)))->toBe(['date', 'items']);
    // And it is published inside the listener job (no second queued hop)
    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class);
});

it('is a queued listener registered exactly once for RankingUpdated and has no handle method', function () {
    // Given the listener class
    $listener = new ReflectionClass(BroadcastRankUpdate::class);

    // When its contract and registrations are inspected
    // Then it is queued with one try, has no discoverable method, and is registered once
    expect($listener->implementsInterface(ShouldQueue::class))->toBeTrue()->and((new BroadcastRankUpdate)->tries)->toBe(1);
    expect($listener->implementsInterface(ShouldBeUniqueUntilProcessing::class))->toBeTrue()
        ->and((new BroadcastRankUpdate)->timeout)->toBe(10)->and((new BroadcastRankUpdate)->uniqueFor)->toBe(30)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan(10);
    expect($listener->hasMethod('handle'))->toBeFalse()->and($listener->hasMethod('__invoke'))->toBeFalse();
    $ours = array_filter(app('events')->getRawListeners()[RankingUpdated::class] ?? [], fn ($l) => $l === [BroadcastRankUpdate::class, 'broadcast'] || (is_string($l) && str_contains($l, 'BroadcastRankUpdate')));
    expect($ours)->toHaveCount(1);
    expect(app('events')->getListeners(RankingUpdated::class))->toHaveCount(1);
});

it('does not fail the recompute when the broadcaster throws, and reports the failure (the recompute action has its own guard too)', function () {
    // Given a throwing broadcaster, a reporter that records and a product to re-score
    Broadcast::extend('failing', fn () => brkFailingBroadcaster());
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    Exceptions::fake();
    $product = brkProduct(0.0, 1);
    brkBucket($product, 5);

    // When the command runs
    $this->artisan('launchpad:recompute-hot-scores')->assertExitCode(0);

    // Then the new score is stored and the failure was reported
    expect($product->fresh()->hot_score)->toBeGreaterThan(0.0);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb is down');
});

it('reports a Reverb server that cannot be reached and still succeeds', function () {
    // Given the Pusher HTTP client cannot connect
    config(['broadcasting.connections.reverb.client_options.handler' => fn (RequestInterface $r) => Create::rejectionFor(new ConnectException('Connection refused', $r))]);
    Broadcast::purge('reverb');
    Exceptions::fake();
    $product = brkProduct(0.0, 1);
    brkBucket($product, 5);

    // When the command runs
    $this->artisan('launchpad:recompute-hot-scores')->assertSuccessful();

    // Then the failure is reported
    Exceptions::assertReported(BroadcastException::class);
});

it('broadcasts nothing for a stale event whose day is no longer today', function (string $day) {
    // Given a ranked product today and a reporter that records
    Exceptions::fake();
    brkProduct(3.0, 1);
    Event::fake([RankUpdated::class]);
    brkClear();

    // When the listener handles an event of another day
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated($day));

    // Then nothing goes out and no swallowed error hides behind that
    Event::assertNotDispatched(RankUpdated::class);
    expect(brkSent())->toBe([]);
    Exceptions::assertNothingReported();
})->with(['yesterday' => '2026-10-20', 'tomorrow' => '2026-10-22']);

it('broadcasts nothing for a day without visible products', function () {
    // Given no product at all and a reporter that records
    Exceptions::fake();
    Event::fake([RankUpdated::class]);

    // When the listener runs
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then nothing is dispatched and nothing was reported
    Event::assertNotDispatched(RankUpdated::class);
    Exceptions::assertNothingReported();
});

it('never lists hidden products', function () {
    // Given one visible product and hidden ones of every kind
    $visible = brkProduct(1.0, 1);
    brkProduct(9.0, 9, ['status' => 'taken_down']);
    brkProduct(9.0, 9)->delete();
    brkProduct(9.0, 9, ['maker_id' => User::factory()->create(['deleted_at' => now()])->id]);
    Product::factory()->live('2026-10-20')->create(['hot_score' => 9.0]);
    Product::factory()->scheduled('2026-10-22')->create(['hot_score' => 9.0]);
    brkClear();

    // When the listener runs
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then only the visible product is listed
    $items = json_decode(brkSent()[0]['body']['data'], true)['items'];
    expect(array_column($items, 'product_id'))->toBe([$visible->id]);
});

it('reports and swallows a failed read without poisoning the surrounding transaction', function () {
    // Given a ranking whose read runs a bad query (no DDL lock), and a reporter that records
    Exceptions::fake();
    $this->app->instance(TodayRanking::class, new class extends TodayRanking
    {
        public function ranking(CarbonImmutable $now, int $limit = self::LIMIT): Collection
        {
            DB::select('select * from table_that_does_not_exist');

            return new Collection;
        }
    });
    brkClear();

    DB::transaction(function () {
        // When the listener handles the event inside that outer transaction
        app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

        // Then the same transaction still works (the failure stayed in a savepoint)
        expect(DB::selectOne('select 1 as one')->one)->toBe(1);
    });

    // And the failure was reported and nothing was broadcast
    Exceptions::assertReported(QueryException::class);
    expect(brkSent())->toBe([]);
});

it('swallows and reports a failing broadcaster inside the LISTENER itself (the command tests above are also covered by the action\'s guard)', function () {
    // Given a throwing broadcaster and a ranked product
    Broadcast::extend('failing', fn () => brkFailingBroadcaster());
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    Exceptions::fake();
    brkProduct(1.0, 1);

    // When the listener handles the event directly (not behind the action's own guard)
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then it returned normally and the failure was reported
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb is down');
});

it('orders equal scores by votes, and a full tie by id', function () {
    // Given four products with the same score: one with few votes, three with the same many votes
    $few = brkProduct(5.0, 2);
    $tied = [brkProduct(5.0, 9), brkProduct(5.0, 9), brkProduct(5.0, 9)];
    $tiedIds = collect($tied)->pluck('id')->sort()->values()->all();
    brkClear();

    // When the listener runs
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then more votes come first, the full tie falls back to id, and the few-votes product is last
    $items = json_decode(brkSent()[0]['body']['data'], true)['items'];
    expect(array_column($items, 'product_id'))->toBe([...$tiedIds, $few->id]);
    expect(array_column($items, 'votes_count'))->toBe([9, 9, 9, 2]);
});

it('broadcasts an event of day D at 23:59:59 of D and nothing at 00:00:30 of D+1', function () {
    // Given a product launching on D
    Exceptions::fake();
    $product = brkProduct(1.0, 1);
    brkClear();

    // When the event is processed in the last second of D
    $this->travelTo(new DateTimeImmutable('2026-10-21 23:59:59 UTC'));
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then it is broadcast
    expect(array_column(json_decode(brkSent()[0]['body']['data'], true)['items'], 'product_id'))->toBe([$product->id]);

    // And when the same event is processed 31 seconds into D+1
    brkClear();
    $this->travelTo(new DateTimeImmutable('2026-10-22 00:00:30 UTC'));
    app(BroadcastRankUpdate::class)->broadcast(new RankingUpdated('2026-10-21'));

    // Then nothing goes out
    expect(brkSent())->toBe([]);
    Exceptions::assertNothingReported();
});

it('keeps one pending listener job per day while the backlog waits, and one per different day', function () {
    // Given a fake queue (nothing runs) and a ranked product
    Queue::fake();
    brkProduct(1.0, 1);

    // When the scheduler fires the event three times for D and once for D+1
    RankingUpdated::dispatch('2026-10-21');
    RankingUpdated::dispatch('2026-10-21');
    RankingUpdated::dispatch('2026-10-21');
    RankingUpdated::dispatch('2026-10-22');

    // Then one job per day is pending, with the lock lifetime and timeout set on the job
    Queue::assertPushed(CallQueuedListener::class, 2);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === BroadcastRankUpdate::class
        && $job->method === 'broadcast' && $job->shouldBeUniqueUntilProcessing() && $job->uniqueFor() === 30 && $job->timeout === 10);
});

it('lets the next event queue a new job once the first one is processed', function () {
    // Given a ranked product and the sync queue (each job runs, and releases its lock, when pushed)
    brkProduct(1.0, 1);
    brkClear();

    // When two events arrive one after the other
    RankingUpdated::dispatch('2026-10-21');
    RankingUpdated::dispatch('2026-10-21');

    // Then both were broadcast (the lock is held only while a job is pending)
    expect(brkSent())->toHaveCount(2);
});
