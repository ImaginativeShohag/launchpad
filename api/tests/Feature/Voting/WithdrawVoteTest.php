<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Actions\Vote\WithdrawVote;
use App\Enums\VoteStatus;
use App\Events\VoteCast;
use App\Events\VoteRemoved;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC')));

function withdrawVote(string $productId)
{
    return test()->deleteJson("/api/v1/products/{$productId}/vote");
}

function dayProduct(array $attributes = []): Product
{
    return Product::factory()->live(Carbon::now('UTC')->toDateString())->create($attributes);
}

function hourBuckets(Product $product): array
{
    return DB::table('product_hourly_stats')->where('product_id', $product->id)->orderBy('hour_start')->pluck('votes', 'hour_start')->all();
}

it('withdraws then re-casts, decrementing the ORIGINAL hour bucket', function () {
    // Given a member who voted at 10:30
    Event::fake([VoteCast::class, VoteRemoved::class]);
    signedInMember();
    $product = dayProduct();
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk();

    // When they withdraw an hour later (11:45)
    $this->travelTo(new DateTimeImmutable('2026-10-19 11:45:00 UTC'));
    $withdrawn = withdrawVote($product->id);

    // Then the count is 0, the 10:00 bucket went back to 0, the 11:00 bucket was not touched, one event fired
    $withdrawn->assertOk()->assertExactJson(['data' => ['voted' => false, 'votes_count' => 0]]);
    expect($product->fresh()->votes_count)->toBe(0)->and(hourBuckets($product))->toBe(['2026-10-19 10:00:00+00' => 0]);
    expect(Vote::query()->sole()->removed_at)->not->toBeNull();
    Event::assertDispatchedTimes(VoteRemoved::class, 1);

    // When they cast again at 12:15
    $this->travelTo(new DateTimeImmutable('2026-10-19 12:15:00 UTC'));
    $again = $this->putJson("/api/v1/products/{$product->id}/vote");

    // Then the count is 1, the toggle count 1, voted_at moved, and the new hour got the vote
    $again->assertOk()->assertExactJson(['data' => ['voted' => true, 'votes_count' => 1]]);
    $vote = Vote::query()->sole();
    expect($vote->toggle_count)->toBe(1)->and($vote->removed_at)->toBeNull()->and($vote->voted_at->toIso8601String())->toBe('2026-10-19T12:15:00+00:00');
    expect(hourBuckets($product))->toBe(['2026-10-19 10:00:00+00' => 0, '2026-10-19 12:00:00+00' => 1]);
    Event::assertDispatchedTimes(VoteCast::class, 2);
});

it('answers a second withdrawal, and a withdrawal with no vote, 200 with no event and no change', function () {
    // Given a withdrawn vote on one product and no vote on another
    Event::fake([VoteRemoved::class]);
    signedInMember();
    $voted = dayProduct();
    $never = dayProduct();
    $this->putJson("/api/v1/products/{$voted->id}/vote")->assertOk();
    withdrawVote($voted->id)->assertOk();

    // When both are withdrawn again
    $twice = withdrawVote($voted->id);
    $none = withdrawVote($never->id);

    // Then both are 200 no-ops and only the first withdrawal fired
    $twice->assertOk()->assertExactJson(['data' => ['voted' => false, 'votes_count' => 0]]);
    $none->assertOk()->assertExactJson(['data' => ['voted' => false, 'votes_count' => 0]]);
    expect($voted->fresh()->votes_count)->toBe(0)->and(hourBuckets($voted))->toBe(['2026-10-19 10:00:00+00' => 0])->and(hourBuckets($never))->toBe([]);
    Event::assertDispatchedTimes(VoteRemoved::class, 1);
});

it('keeps the other members votes when one withdraws', function () {
    // Given two voters
    $product = dayProduct();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $this->actingAs($first, 'sanctum')->putJson("/api/v1/products/{$product->id}/vote")->assertOk();
    $this->actingAs($second, 'sanctum')->putJson("/api/v1/products/{$product->id}/vote")->assertOk();

    // When the first withdraws
    $this->actingAs($first, 'sanctum');
    $response = withdrawVote($product->id);

    // Then one vote is left
    $response->assertOk()->assertJsonPath('data.votes_count', 1);
    expect(Vote::query()->active()->count())->toBe(1);
});

it('answers a voided vote with a 200 no-op and leaves it voided', function () {
    // Given a voided vote
    $member = signedInMember();
    $product = dayProduct();
    Vote::factory()->voided()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    Event::fake([VoteRemoved::class]);

    // When the member withdraws
    $response = withdrawVote($product->id);

    // Then nothing moves and the void is not revealed
    $response->assertOk()->assertExactJson(['data' => ['voted' => false, 'votes_count' => 0]]);
    expect(Vote::query()->sole()->status)->toBe(VoteStatus::Voided)->and(Vote::query()->sole()->removed_at)->toBeNull();
    Event::assertNotDispatched(VoteRemoved::class);
});

it('never lets votes_count go below zero', function () {
    // Given an active vote on a product whose counter is already (wrongly) zero
    $member = signedInMember();
    $product = dayProduct();
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);

    // When the member withdraws
    $response = withdrawVote($product->id);

    // Then the counter stays at zero
    $response->assertOk()->assertJsonPath('data.votes_count', 0);
    expect($product->fresh()->votes_count)->toBe(0);
});

it('refuses a withdrawal after the launch day with 409 voting_closed and changes nothing', function () {
    // Given a vote cast today and the day over
    signedInMember();
    $product = dayProduct();
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk();
    $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:01 UTC'));

    // When the member withdraws
    $response = withdrawVote($product->id);

    // Then it is refused and the vote still counts
    $response->assertStatus(409)->assertJsonPath('code', 'voting_closed');
    expect($product->fresh()->votes_count)->toBe(1)->and(Vote::query()->active()->count())->toBe(1);
});

it('answers hidden products the uniform 404, the maker 403 and guests 401', function () {
    // Given a member, a hidden product, an own product
    $member = signedInMember();
    $hidden = Product::factory()->pending()->create();
    $own = dayProduct(['maker_id' => $member->id]);
    $deletedMaker = dayProduct(['maker_id' => User::factory()->create(['deleted_at' => now()])->id]);

    // When they withdraw from each
    // Then the refusals match the cast side
    withdrawVote($hidden->id)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    withdrawVote($deletedMaker->id)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    withdrawVote($own->id)->assertForbidden()->assertJsonPath('code', 'own_product');
    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', '')->deleteJson("/api/v1/products/{$own->id}/vote")->assertUnauthorized();
});

it('refuses a stale suspended voter in the action', function () {
    // Given a voter who voted and was then suspended
    $voter = User::factory()->create();
    $product = dayProduct();
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));
    DB::table('users')->where('id', $voter->id)->update(['suspended_at' => now()]);

    // When the stale model withdraws
    // Then it is refused and the vote still counts
    expect(fn () => app(WithdrawVote::class)->handle($voter, $product->id))->toThrow(AuthorizationException::class);
    expect($product->fresh()->votes_count)->toBe(1);
});

it('fires a real listener once per withdrawal and not on a no-op', function () {
    // Given a real listener
    $calls = 0;
    Event::listen(VoteRemoved::class, function () use (&$calls) {
        $calls++;
    });
    signedInMember();
    $product = dayProduct();
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk();

    // When the member withdraws twice
    withdrawVote($product->id)->assertOk();
    withdrawVote($product->id)->assertOk();

    // Then it ran once
    expect($calls)->toBe(1);
});
