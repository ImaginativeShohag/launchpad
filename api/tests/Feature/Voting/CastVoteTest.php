<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Enums\VoteStatus;
use App\Events\VoteCast;
use App\Exceptions\ProductRuleViolation;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC')));

function voteDay(): string
{
    return Carbon::now('UTC')->toDateString();
}

/** A live product launching today, made by somebody else. */
function launchingToday(array $attributes = []): Product
{
    return Product::factory()->live(voteDay())->create($attributes);
}

function castVote(string $productId)
{
    return test()->putJson("/api/v1/products/{$productId}/vote");
}

function bucketVotes(Product $product): array
{
    return DB::table('product_hourly_stats')->where('product_id', $product->id)->orderBy('hour_start')->pluck('votes', 'hour_start')->all();
}

it('casts a first vote: row with hashes, counters, bucket, one event and no audit row', function () {
    // Given a member and a product launching today
    Event::fake([VoteCast::class]);
    $member = signedInMember();
    $product = launchingToday();

    // When the member votes, with a user agent longer than 255 characters
    $response = $this->withHeader('User-Agent', str_repeat('a', 300))->putJson("/api/v1/products/{$product->id}/vote");

    // Then the answer is the new state and every counter agrees
    $response->assertOk()->assertExactJson(['data' => ['voted' => true, 'votes_count' => 1]]);
    expect($product->fresh()->votes_count)->toBe(1);
    $vote = Vote::query()->where('user_id', $member->id)->where('product_id', $product->id)->sole();
    expect($vote->status)->toBe(VoteStatus::Counted)->and($vote->toggle_count)->toBe(0)->and($vote->removed_at)->toBeNull()
        ->and($vote->ip_hash)->toHaveLength(64)->and($vote->device_hash)->toHaveLength(64)->and($vote->user_agent)->toBe(str_repeat('a', 255));
    expect(bucketVotes($product))->toBe(['2026-10-19 10:00:00+00' => 1]);
    Event::assertDispatchedTimes(VoteCast::class, 1);
    Event::assertDispatched(VoteCast::class, fn (VoteCast $e) => $e->productId === $product->id && $e->userId === $member->id);
    expect(AuditLog::query()->count())->toBe(0);
    $response->assertHeader('Cache-Control', 'no-store, private');
});

it('stores a null user agent when the request has none', function () {
    // Given a member
    $member = signedInMember();
    $product = launchingToday();

    // When the signals are built from a request without a user agent
    $signals = VoteSignals::fromRequest(Request::create('/x', 'PUT', server: ['HTTP_USER_AGENT' => '']));
    app(CastVote::class)->handle($member, $product->id, $signals);

    // Then the column is null
    expect(Vote::query()->sole()->user_agent)->toBeNull();
});

it('is idempotent: a second cast changes nothing and fires nothing', function () {
    // Given a member who already voted
    Event::fake([VoteCast::class]);
    signedInMember();
    $product = launchingToday();
    castVote($product->id)->assertOk();

    // When they vote again
    $again = castVote($product->id);

    // Then the count is still 1, the toggle count 0, and only the first cast fired an event
    $again->assertOk()->assertExactJson(['data' => ['voted' => true, 'votes_count' => 1]]);
    expect($product->fresh()->votes_count)->toBe(1)->and(Vote::query()->sole()->toggle_count)->toBe(0)->and(bucketVotes($product))->toBe(['2026-10-19 10:00:00+00' => 1]);
    Event::assertDispatchedTimes(VoteCast::class, 1);
});

it('lets several members vote and counts each once', function () {
    // Given three members
    $product = launchingToday();
    foreach (range(1, 3) as $_) {
        $this->actingAs(User::factory()->create(), 'sanctum');

        // When each votes twice
        castVote($product->id)->assertOk();
        castVote($product->id)->assertOk();
    }

    // Then the product has three votes
    expect($product->fresh()->votes_count)->toBe(3)->and(Vote::query()->count())->toBe(3);
});

it('answers every hidden case with the identical 404 body and writes nothing', function (string $case) {
    // Given a product in some hidden state, made today where the status allows it
    signedInMember();
    $deletedMaker = User::factory()->create(['deleted_at' => now()]);
    $product = match ($case) {
        'draft' => Product::factory()->complete()->create(),
        'pending' => Product::factory()->pending()->create(),
        'approved' => Product::factory()->approved()->create(),
        'scheduled' => Product::factory()->scheduled(voteDay())->create(),
        'rejected' => Product::factory()->rejected()->create(),
        'changes requested' => Product::factory()->changesRequested()->create(),
        'taken down' => Product::factory()->takenDown()->create(['launch_date' => voteDay()]),
        'live but launching tomorrow' => Product::factory()->live(now('UTC')->addDay()->toDateString())->create(),
        'soft deleted' => launchingToday(['deleted_at' => now()]),
        'deleted maker' => launchingToday(['maker_id' => $deletedMaker->id]),
        'unknown' => (new Product)->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']),
    };

    // When the member votes on it
    $response = castVote($product->id);

    // Then the answer cannot be told apart from "no such product", and nothing was written
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(Vote::query()->count())->toBe(0)->and(DB::table('product_hourly_stats')->count())->toBe(0);
})->with(['draft', 'pending', 'approved', 'scheduled', 'rejected', 'changes requested', 'taken down', 'live but launching tomorrow', 'soft deleted', 'deleted maker', 'unknown']);

it('refuses a vote after the launch day with 409 voting_closed and changes nothing', function () {
    // Given a live product whose launch day was yesterday
    signedInMember();
    $product = Product::factory()->live(Carbon::now('UTC')->subDay()->toDateString())->create();

    // When the member votes
    // Then it is closed
    castVote($product->id)->assertStatus(409)->assertJsonPath('code', 'voting_closed');
    expect(Vote::query()->count())->toBe(0)->and($product->fresh()->votes_count)->toBe(0);
});

it('closes the day at midnight UTC', function () {
    // Given a product launching today and the clock one second before midnight
    signedInMember();
    $product = launchingToday();
    $this->travelTo(new DateTimeImmutable('2026-10-19 23:59:59 UTC'));

    // When the member votes before and after midnight
    $before = castVote($product->id);
    $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:00 UTC'));
    $after = castVote($product->id);

    // Then the first counts and the second is closed
    $before->assertOk();
    $after->assertStatus(409)->assertJsonPath('code', 'voting_closed');
});

it('refuses the maker voting on their own product with 403 own_product', function () {
    // Given a maker with a product launching today
    $maker = signedInMember();
    $product = launchingToday(['maker_id' => $maker->id]);

    // When they vote for it
    // Then it is refused and nothing is written
    castVote($product->id)->assertForbidden()->assertJsonPath('code', 'own_product');
    expect(Vote::query()->count())->toBe(0)->and($product->fresh()->votes_count)->toBe(0);
});

it('refuses unverified and suspended members and guests', function () {
    // Given a product
    $product = launchingToday();

    // When a guest, an unverified member and a suspended member vote
    $guest = castVote($product->id);
    $member = signedInMember(['email_verified_at' => null]);
    $unverified = castVote($product->id);
    $member->forceFill(['email_verified_at' => now(), 'suspended_at' => now()])->save();
    $suspended = castVote($product->id);

    // Then each is refused with its own code and nothing is written
    $guest->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect(Vote::query()->count())->toBe(0);
});

it('refuses a voter suspended or deleted after the request was authorised', function (string $column) {
    // Given a stale voter model and a product
    $voter = User::factory()->create();
    $product = launchingToday();
    DB::table('users')->where('id', $voter->id)->update([$column => now()]);

    // When the action runs with the stale model
    $attempt = fn () => app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    // Then it is refused and nothing is written
    expect($attempt)->toThrow(AuthorizationException::class);
    expect(Vote::query()->count())->toBe(0)->and($product->fresh()->votes_count)->toBe(0);
})->with(['suspended_at', 'deleted_at']);

it('keeps a voided vote voided, answers 200 and does not reveal it', function () {
    // Given a member whose vote was voided (it no longer counts)
    $member = signedInMember();
    $product = launchingToday();
    Vote::factory()->voided()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    Event::fake([VoteCast::class]);

    // When they cast
    $response = castVote($product->id);

    // Then the answer looks like a plain no-op, the vote stays voided and nothing moved
    $response->assertOk()->assertExactJson(['data' => ['voted' => false, 'votes_count' => 0]]);
    expect(Vote::query()->sole()->status)->toBe(VoteStatus::Voided)->and($product->fresh()->votes_count)->toBe(0)->and(DB::table('product_hourly_stats')->count())->toBe(0);
    Event::assertNotDispatched(VoteCast::class);
});

it('answers odd product ids with the uniform 404 on both verbs', function (string $id) {
    // Given a member
    signedInMember();

    // When the id cannot be a product id
    $put = $this->putJson('/api/v1/products/'.$id.'/vote');
    $delete = $this->deleteJson('/api/v1/products/'.$id.'/vote');

    // Then both verbs answer the plain 404 and no database error occurs
    $put->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $delete->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with(['not-an-id', 'x%00y', 'a%20b', '01ARZ3NDEKTSV4RRFFQ69G5FAV0', 'ZZZZZZZZZZZZZZZZZZZZZZZZZZ']);

it('answers a 200-character id with the uniform 404', function () {
    // Given a member and a very long id
    signedInMember();

    // When they vote and withdraw with it
    // Then both are the plain 404
    $this->putJson('/api/v1/products/'.str_repeat('a', 200).'/vote')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->deleteJson('/api/v1/products/'.str_repeat('a', 200).'/vote')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('ignores a stray body and never answers 500', function (mixed $body) {
    // Given a member and a product
    signedInMember();
    $product = launchingToday();

    // When the request carries an array, an object, null or scalar JSON body
    $response = $this->call('PUT', "/api/v1/products/{$product->id}/vote", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body));

    // Then the body is ignored (documented: 200), never a 500
    $response->assertOk()->assertJsonPath('data.voted', true);
})->with([[['a' => ['b']]], [[['x']]], [[null]], ['text'], [[]]]);

it('answers 405 to GET and HEAD on the vote url', function () {
    // Given a member and a product
    signedInMember();
    $product = launchingToday();

    // When the url is read
    // Then reads are not allowed
    $this->getJson("/api/v1/products/{$product->id}/vote")->assertStatus(405);
    $this->call('HEAD', "/api/v1/products/{$product->id}/vote")->assertStatus(405);
});

it('runs a real listener exactly once per successful cast and never on a refused one', function () {
    // Given a real (unfaked) listener
    $calls = 0;
    Event::listen(VoteCast::class, function () use (&$calls) {
        $calls++;
    });
    $member = signedInMember();
    $product = launchingToday();
    $own = launchingToday(['maker_id' => $member->id]);

    // When one cast succeeds, is repeated, and two are refused
    castVote($product->id)->assertOk();
    castVote($product->id)->assertOk();
    castVote($own->id)->assertForbidden();
    castVote('01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();

    // Then the listener ran once
    expect($calls)->toBe(1);
});

it('throws the documented exceptions when the action is called directly', function () {
    // Given a voter and a closed product
    $voter = User::factory()->create();
    $closed = Product::factory()->live(Carbon::now('UTC')->subDay()->toDateString())->create();
    $signals = new VoteSignals(null, null, null);

    // When the action runs
    // Then the refusals are typed
    expect(fn () => app(CastVote::class)->handle($voter, $closed->id, $signals))->toThrow(ProductRuleViolation::class);
    expect(fn () => app(CastVote::class)->handle($voter, 'nope', $signals))->toThrow(ModelNotFoundException::class);
});

it('throttles the vote route at 30 per minute per member with a Retry-After header', function () {
    // Given a member who has cast 30 times in a minute (repeats are idempotent no-ops)
    signedInMember();
    $product = launchingToday();
    foreach (range(1, 30) as $_) {
        castVote($product->id)->assertOk();
    }

    // When the 31st arrives
    // Then the vote limiter stops it (the api bucket would allow 60)
    castVote($product->id)->assertStatus(429)->assertHeader('Retry-After');
});
