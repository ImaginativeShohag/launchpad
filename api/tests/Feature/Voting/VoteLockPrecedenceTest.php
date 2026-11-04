<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC')));

it('judges the day before the voter state: a suspended voter on a closed day still gets voting_closed', function () {
    // Given a suspended voter (stale model) and a product whose day is over
    $voter = User::factory()->suspended()->create();
    $closed = Product::factory()->live(Carbon::now('UTC')->subDay()->toDateString())->create();

    // When the action runs
    $attempt = fn () => app(CastVote::class)->handle($voter, $closed->id, new VoteSignals(null, null, null));

    // Then the day check came first
    expect($attempt)->toThrow(ProductRuleViolation::class, 'Voting is only open on the launch day.');
});

it('judges the own-product rule before the voter state, and the voter state last', function () {
    // Given a suspended maker voting on their own product launching today, and a suspended stranger on someone's product
    $maker = User::factory()->suspended()->create();
    $own = Product::factory()->live(Carbon::now('UTC')->toDateString())->create(['maker_id' => $maker->id]);
    $stranger = User::factory()->suspended()->create();
    $other = Product::factory()->live(Carbon::now('UTC')->toDateString())->create();

    // When both actions run
    // Then the first is own_product, the second is the authorisation failure
    expect(fn () => app(CastVote::class)->handle($maker, $own->id, new VoteSignals(null, null, null)))->toThrow(ProductRuleViolation::class, 'You cannot vote for your own product.');
    expect(fn () => app(CastVote::class)->handle($stranger, $other->id, new VoteSignals(null, null, null)))->toThrow(AuthorizationException::class);
});

it('takes exactly the product row lock then the voter share lock, nothing else', function () {
    // Given a voter and a product launching today
    $voter = User::factory()->create();
    $product = Product::factory()->live(Carbon::now('UTC')->toDateString())->create();
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'for key share') || str_contains($sql, 'for no key update')) {
            $locks[] = $sql;
        }
    });

    // When the vote is cast
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    // Then the locking statements are, in order: the product FOR UPDATE, the voter FOR SHARE, then the vote row FOR UPDATE (none exists yet, still a lock read)
    $shape = array_map(fn (string $sql) => (str_contains($sql, 'from "products"') ? 'products' : (str_contains($sql, 'from "votes"') ? 'votes' : (str_contains($sql, 'from "users"') ? 'users' : 'other'))).(str_contains($sql, 'for share') ? ':share' : ':update'), $locks);
    expect($shape)->toBe(['products:update', 'users:share', 'votes:update']);
});
