<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoidVote;
use App\Actions\Vote\VoteSignals;
use App\Actions\Vote\WithdrawVote;
use App\Events\VoteCast;
use App\Events\VoteCountUpdated;
use App\Events\VoteEvent;
use App\Events\VoteRemoved;
use App\Events\VoteVoided;
use App\Listeners\BroadcastVoteCount;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC')));

function bvcProduct(array $attributes = []): Product
{
    return Product::factory()->live(Carbon::now('UTC')->toDateString())->create($attributes);
}

/** Casts a real vote for a fresh member (events fire as in production). */
function bvcVote(Product $product): User
{
    $voter = User::factory()->create();
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    return $voter;
}

/** @return list<array<string, mixed>> the Pusher HTTP calls the stubbed Reverb client was asked to make, decoded */
function bvcSent(): array
{
    return array_map(
        fn ($request) => ['path' => $request->getUri()->getPath(), 'body' => json_decode((string) $request->getBody(), true)],
        array_values(app('test.reverb.requests')->getArrayCopy()),
    );
}

/** Forgets the calls made while the scenario was being set up. */
function bvcClear(): void
{
    app('test.reverb.requests')->exchangeArray([]);
}

function bvcBroadcaster(): Broadcaster
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

it('broadcasts exactly one vote.count with the CURRENT count after a cast', function () {
    // Given a product that already has two votes, and a final-event fake (the listener stays real)
    $product = bvcProduct();
    bvcVote($product);
    bvcVote($product);
    Event::fake([VoteCountUpdated::class]);
    signedInMember();

    // When a third member casts over HTTP
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk()->assertJsonPath('data.votes_count', 3);

    // Then one broadcast carries the stored count
    Event::assertDispatchedTimes(VoteCountUpdated::class, 1);
    Event::assertDispatched(VoteCountUpdated::class, fn (VoteCountUpdated $e) => $e->productId === $product->id && $e->votesCount === 3);
});

it('broadcasts exactly one vote.count with the CURRENT count after a withdrawal', function () {
    // Given a product with two votes, one of them the signed-in member's
    $product = bvcProduct();
    bvcVote($product);
    $member = signedInMember();
    app(CastVote::class)->handle($member, $product->id, new VoteSignals(null, null, null));
    Event::fake([VoteCountUpdated::class]);

    // When the member withdraws over HTTP
    $this->deleteJson("/api/v1/products/{$product->id}/vote")->assertOk()->assertJsonPath('data.votes_count', 1);

    // Then one broadcast carries the lowered count
    Event::assertDispatchedTimes(VoteCountUpdated::class, 1);
    Event::assertDispatched(VoteCountUpdated::class, fn (VoteCountUpdated $e) => $e->productId === $product->id && $e->votesCount === 1);
});

it('broadcasts exactly one vote.count with the CURRENT count after a vote is voided', function () {
    // Given a product with two votes
    $product = bvcProduct();
    bvcVote($product);
    $voter = bvcVote($product);
    $vote = Vote::query()->where('user_id', $voter->id)->sole();
    Event::fake([VoteCountUpdated::class]);

    // When one is voided by the system
    app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then one broadcast carries the count without it
    Event::assertDispatchedTimes(VoteCountUpdated::class, 1);
    Event::assertDispatched(VoteCountUpdated::class, fn (VoteCountUpdated $e) => $e->productId === $product->id && $e->votesCount === 1);
});

it('reads the current count again instead of trusting the event', function () {
    // Given a cast whose product moved on before the listener ran (two more votes arrived)
    $product = bvcProduct();
    $first = bvcVote($product);
    bvcVote($product);
    bvcVote($product);
    Event::fake([VoteCountUpdated::class]);

    // When the listener handles the OLD event for the first voter
    app(BroadcastVoteCount::class)->broadcast(new VoteCast($product->id, $first->id));

    // Then it announces 3, not the 1 the vote saw
    Event::assertDispatched(VoteCountUpdated::class, fn (VoteCountUpdated $e) => $e->votesCount === 3);
});

it('broadcasts nothing for a refused request', function (string $case) {
    // Given a refused vote: the maker on their own product, or a product whose launch day is over
    Event::fake([VoteCountUpdated::class]);
    $member = signedInMember();
    $product = match ($case) {
        'own product' => bvcProduct(['maker_id' => $member->id]),
        'closed day' => Product::factory()->live(Carbon::now('UTC')->subDay()->toDateString())->create(),
    };

    // When the member votes
    $response = $this->putJson("/api/v1/products/{$product->id}/vote");

    // Then it is refused and no broadcast goes out
    $response->assertStatus($case === 'own product' ? 403 : 409)->assertJsonPath('code', $case === 'own product' ? 'own_product' : 'voting_closed');
    Event::assertNotDispatched(VoteCountUpdated::class);
    expect(bvcSent())->toBe([]);
})->with(['own product', 'closed day']);

it('broadcasts nothing for a withdrawal that changed nothing', function () {
    // Given a member who never voted
    $product = bvcProduct();
    signedInMember();

    // When they withdraw
    $this->deleteJson("/api/v1/products/{$product->id}/vote")->assertOk();

    // Then nothing is broadcast
    expect(bvcSent())->toBe([]);
});

it('does not leak a hidden product count on the public channel', function (string $case) {
    // Given a product that is no longer publicly visible
    $product = bvcProduct();
    bvcVote($product);
    $voter = User::factory()->create();
    $deletedMaker = User::factory()->create(['deleted_at' => now()]);
    bvcClear();
    match ($case) {
        'taken down' => $product->forceFill(['status' => 'taken_down'])->save(),
        'soft deleted' => $product->delete(),
        'deleted maker' => $product->forceFill(['maker_id' => $deletedMaker->id])->save(),
        'launching tomorrow' => $product->forceFill(['launch_date' => Carbon::now('UTC')->addDay()->toDateString()])->save(),
        'unknown id' => null,
    };
    $id = $case === 'unknown id' ? '01ARZ3NDEKTSV4RRFFQ69G5FAV' : $product->id;
    Event::fake([VoteCountUpdated::class]);

    // When every vote event reaches the listener
    foreach ([VoteCast::class, VoteRemoved::class, VoteVoided::class] as $event) {
        app(BroadcastVoteCount::class)->broadcast(new $event($id, $voter->id));
    }

    // Then nothing is broadcast
    Event::assertNotDispatched(VoteCountUpdated::class);
    expect(bvcSent())->toBe([]);
})->with(['taken down', 'soft deleted', 'deleted maker', 'launching tomorrow', 'unknown id']);

it('never fails or rolls back a vote when the broadcaster throws, and reports the failure', function () {
    // Given a broadcaster that throws and a reporter that records
    Broadcast::extend('failing', fn () => bvcBroadcaster());
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    Exceptions::fake();
    $product = bvcProduct();
    $member = signedInMember();

    // When a vote is cast and then withdrawn
    $cast = $this->putJson("/api/v1/products/{$product->id}/vote");
    $countAfterCast = $product->fresh()->votes_count;
    $withdraw = $this->deleteJson("/api/v1/products/{$product->id}/vote");

    // Then both answered 200, the rows are committed, and each failure was reported
    $cast->assertOk()->assertJsonPath('data.votes_count', 1);
    $withdraw->assertOk()->assertJsonPath('data.votes_count', 0);
    expect($countAfterCast)->toBe(1)->and($product->fresh()->votes_count)->toBe(0);
    expect(Vote::query()->where('user_id', $member->id)->sole()->removed_at)->not->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb is down');
});

it('reports a Reverb server that cannot be reached and still answers the vote', function () {
    // Given the Pusher HTTP client cannot connect
    config(['broadcasting.connections.reverb.client_options.handler' => fn (RequestInterface $r) => Create::rejectionFor(new ConnectException('Connection refused', $r))]);
    Broadcast::purge('reverb');
    Exceptions::fake();
    $product = bvcProduct();
    signedInMember();

    // When a vote is cast
    $response = $this->putJson("/api/v1/products/{$product->id}/vote");

    // Then the vote stands and the failure is reported
    $response->assertOk()->assertJsonPath('data.votes_count', 1);
    expect($product->fresh()->votes_count)->toBe(1);
    Exceptions::assertReported(BroadcastException::class);
});

it('sends the Pusher call for the public channel with exactly product_id and votes_count and no user data', function () {
    // Given a product with one earlier vote and a signed-in voter
    $product = bvcProduct();
    bvcVote($product);
    $member = signedInMember();
    bvcClear();

    // When the member casts (real listener, real event, real driver with a stubbed HTTP client)
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk();

    // Then one event call went out for the plain channel, with only those two keys
    $sent = bvcSent();
    expect($sent)->toHaveCount(1);
    expect($sent[0]['path'])->toEndWith('/events');
    expect($sent[0]['body']['name'])->toBe('vote.count');
    expect($sent[0]['body']['channel'])->toBe('product.'.$product->id);
    $data = json_decode($sent[0]['body']['data'], true);
    expect($data)->toBe(['product_id' => $product->id, 'votes_count' => 2]);
    $raw = json_encode($sent);
    expect($raw)->not->toContain($member->id)->and($raw)->not->toContain((string) $member->email)->and($raw)->not->toContain('user');
});

it('declares a public channel, the vote.count name and exactly two payload keys', function () {
    // Given an event
    $event = new VoteCountUpdated('01ARZ3NDEKTSV4RRFFQ69G5FAV', 7);

    // When asked where, what and with which data it goes out
    $channels = $event->broadcastOn();

    // Then the channel is a plain public one (not private, not presence), named product.{id}
    expect($channels)->toHaveCount(1)->and($channels[0])->toBeInstanceOf(Channel::class)
        ->not->toBeInstanceOf(PrivateChannel::class)->not->toBeInstanceOf(PresenceChannel::class);
    expect($channels[0]->name)->toBe('product.01ARZ3NDEKTSV4RRFFQ69G5FAV');
    expect($event->broadcastAs())->toBe('vote.count');
    expect($event->broadcastWith())->toBe(['product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'votes_count' => 7]);
    expect(array_keys(get_object_vars($event)))->toBe(['afterCommit', 'tries', 'productId', 'votesCount']);
    expect($event->afterCommit)->toBeTrue();
});

it('is a queued listener of after-commit events, registered exactly once per vote event', function () {
    // Given the listener class
    $listener = new ReflectionClass(BroadcastVoteCount::class);

    // When its contracts and registrations are inspected
    // Then it is queued with one try, the events it listens to are after-commit, and each event reaches it once (no discovery double)
    expect($listener->implementsInterface(ShouldQueue::class))->toBeTrue()
        ->and((new BroadcastVoteCount)->tries)->toBe(1);
    foreach ([VoteCast::class, VoteRemoved::class, VoteVoided::class] as $event) {
        expect(is_subclass_of($event, VoteEvent::class))->toBeTrue()
            ->and((new ReflectionClass($event))->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue();
    }
    foreach ([VoteCast::class, VoteRemoved::class, VoteVoided::class] as $event) {
        expect(app('events')->hasListeners($event))->toBeTrue();
        $ours = array_filter(app('events')->getRawListeners()[$event] ?? [], fn ($l) => $l === [BroadcastVoteCount::class, 'broadcast'] || (is_string($l) && str_contains($l, 'BroadcastVoteCount')));
        expect($ours)->toHaveCount(1);
    }
});

it('queues the broadcast instead of running it inside the request', function () {
    // Given a fake queue and a member
    Queue::fake();
    $product = bvcProduct();
    signedInMember();

    // When a vote is cast
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk();

    // Then the listener sits on the queue and nothing was sent yet
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === BroadcastVoteCount::class && $job->method === 'broadcast');
    Queue::assertPushed(CallQueuedListener::class, 1);
    expect(bvcSent())->toBe([]);
});

it('broadcasts once per event, not once per registration', function () {
    // Given a product and a voter
    $product = bvcProduct();

    // When one vote is cast, withdrawn and cast again through the actions
    $voter = User::factory()->create();
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));
    app(WithdrawVote::class)->handle($voter, $product->id);
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    // Then exactly three calls went out, carrying 1, 0, 1
    $counts = array_map(fn (array $c) => json_decode($c['body']['data'], true)['votes_count'], bvcSent());
    expect($counts)->toBe([1, 0, 1]);
    expect(DB::table('products')->where('id', $product->id)->value('votes_count'))->toBe(1);
});

it('reports and swallows a failed count read without poisoning the surrounding transaction', function () {
    // Given a product, a reporter that records, and a read that will fail (the column is renamed inside the transaction)
    $product = bvcProduct();
    $voter = User::factory()->create();
    Exceptions::fake();
    bvcClear();

    DB::transaction(function () use ($product, $voter) {
        DB::statement('ALTER TABLE products RENAME COLUMN votes_count TO votes_count_gone');

        // When the listener handles the event inside that outer transaction
        app(BroadcastVoteCount::class)->broadcast(new VoteCast($product->id, $voter->id));

        // Then the same transaction still works (the failure stayed in a savepoint)
        expect(DB::selectOne('select 1 as one')->one)->toBe(1);
        DB::statement('ALTER TABLE products RENAME COLUMN votes_count_gone TO votes_count');
    });

    // And the failure was reported and nothing was broadcast
    Exceptions::assertReported(QueryException::class);
    expect(bvcSent())->toBe([]);
});
