<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoidVote;
use App\Actions\Vote\VoteSignals;
use App\Actions\Vote\WithdrawVote;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('lets VoidVote take a flagged vote off the counter and its original hour bucket exactly once', function () {
    // Given a member who voted, was flagged, and a counter and bucket that include the vote
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $member = antiCheatSetScore(User::factory()->create(), 90);
    app(CastVote::class)->handle($member, $product->id, new VoteSignals(null, null, null));
    antiCheatFlag();
    $voteId = DB::table('votes')->where('product_id', $product->id)->value('id');
    expect(antiCheatVoteStatus($voteId))->toBe('flagged')->and($product->fresh()->votes_count)->toBe(1);

    // When staff void it (twice)
    $first = app(VoidVote::class)->handle($voteId, 'ring of fake accounts');
    $second = app(VoidVote::class)->handle($voteId, 'ring of fake accounts');

    // Then the counter and the bucket went down once
    expect($first)->toBeTrue()->and($second)->toBeFalse();
    expect($product->fresh()->votes_count)->toBe(0)->and((int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('votes'))->toBe(0);
});

it('keeps a withdrawn flagged vote flagged when it is cast again, and starts a new vote counted', function () {
    // Given a flagged member who withdrew a flagged vote
    antiCheatFreeze();
    $first = antiCheatLiveProduct();
    $second = antiCheatLiveProduct();
    $member = antiCheatSetScore(User::factory()->create(), 90);
    app(CastVote::class)->handle($member, $first->id, new VoteSignals(null, null, null));
    antiCheatFlag();
    app(WithdrawVote::class)->handle($member, $first->id);

    // When the member casts it again and also casts on another product
    app(CastVote::class)->handle($member, $first->id, new VoteSignals(null, null, null));
    app(CastVote::class)->handle($member, $second->id, new VoteSignals(null, null, null));

    // Then the re-cast stays flagged, the brand-new vote starts counted, and the next run flags it
    $statuses = fn () => DB::table('votes')->whereIn('product_id', [$first->id, $second->id])->pluck('status', 'product_id')->all();
    expect($statuses())->toBe([$first->id => 'flagged', $second->id => 'counted']);
    antiCheatFlag();
    expect($statuses())->toBe([$first->id => 'flagged', $second->id => 'flagged']);
});

it('shows the voter the same thing for a flagged vote as for a counted one', function () {
    // Given a member with a counted vote, whose responses are recorded
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $member = antiCheatSetScore(User::factory()->create(), 90);
    Sanctum::actingAs($member);
    $vote = $this->putJson("/api/v1/products/{$product->id}/vote");
    $counted = [$this->getJson('/api/v1/products/'.$product->slug)->getContent(), $this->getJson('/api/v1/today')->getContent(), $vote->getContent()];

    // When the pass flags the vote
    expect(antiCheatFlag()->flagged)->toBe(1);
    $flagged = [$this->getJson('/api/v1/products/'.$product->slug)->getContent(), $this->getJson('/api/v1/today')->getContent(), $this->putJson("/api/v1/products/{$product->id}/vote")->getContent()];

    // Then the product page and the list are byte-identical and has_voted is still true; no payload names the flag
    expect($flagged[0])->toBe($counted[0])->and($flagged[1])->toBe($counted[1]);
    expect(json_decode($flagged[0], true)['data']['has_voted'])->toBeTrue();
    expect($flagged[2])->toBe($counted[2])->and(implode($flagged))->not->toContain('flagged')->not->toContain('risk');
});
