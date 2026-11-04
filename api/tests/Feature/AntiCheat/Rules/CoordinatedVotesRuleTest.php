<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\CoordinatedVotesRule;
use App\Actions\AntiCheat\Scan;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

function antiCheatR8(?RuleConfig $config = null, ?CoordinatedVotesRule $rule = null, array $touched = []): array
{
    return antiCheatFindings($rule ?? new CoordinatedVotesRule, Scan::run(antiCheatNow(), $touched), $config);
}

// Most tests build tiny candidate sets where every product is "popular"; the popularity filter has its own tests below.
beforeEach(fn () => antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 100]));

/** An open fresh_account signal about a member: the "already carries another signal" condition. */
function antiCheatCarries(User $user, array $attributes = []): RiskSignal
{
    return RiskSignal::factory()->forUserOnly()->kind(RiskSignalKind::FreshAccount, 15)->create(['user_id' => $user->id, 'detected_at' => antiCheatNow()->subHour(), ...$attributes]);
}

/** @return list<Product> live products for the members to vote on */
function antiCheatProducts(int $count): array
{
    return array_map(fn () => antiCheatLiveProduct(), range(1, $count));
}

/** Each member votes on the given products. */
function antiCheatVotesOn(User $user, array $products): void
{
    foreach ($products as $product) {
        antiCheatVote($user, $product);
    }
}

it('fires for a ring of three that shares four products and already carries signals', function () {
    // Given three members with a signal each who all voted for the same four products
    antiCheatFreeze();
    $products = antiCheatProducts(4);
    $members = User::factory()->count(3)->create();
    foreach ($members as $member) {
        antiCheatCarries($member);
        antiCheatVotesOn($member, $products);
    }

    // When the rule runs
    $findings = antiCheatR8();

    // Then each member gets a finding with the component and the shared products
    expect($findings)->toHaveCount(3);
    $ids = $members->pluck('id')->sort()->values()->all();
    $productIds = collect($products)->pluck('id')->sort()->values()->all();
    foreach ($findings as $finding) {
        expect($finding->kind)->toBe(RiskSignalKind::CoordinatedVotes)->and($finding->weight)->toBe(35)->and($finding->productId)->toBeNull();
        expect($finding->details['user_ids'])->toBe($ids)->and($finding->details['shared_product_ids'])->toBe($productIds)->and($finding->details['observed'])->toBe(3)->and($finding->details['threshold'])->toBe(3);
        expect($finding->dedupeKey)->toBe('coordinated_votes:'.$finding->userId.':2026-10-21');
    }
});

it('considers only candidates: a ring with no other signal yields nothing', function () {
    // Given the same ring but without any signal
    antiCheatFreeze();
    $products = antiCheatProducts(4);
    foreach (User::factory()->count(3)->create() as $member) {
        antiCheatVotesOn($member, $products);
    }

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR8())->toBe([]);
});

it('does the overlap maths: 4 shared of sets 5 and 9 is 0.8, 3 shared is not enough', function () {
    // Given three members: A and B share 4 products (A has 5, B has 9), C shares only 3 with A and B
    antiCheatFreeze();
    $shared = antiCheatProducts(4);
    $extraA = antiCheatProducts(1);
    $extraB = antiCheatProducts(5);
    $three = antiCheatProducts(3);
    [$a, $b, $c] = User::factory()->count(3)->create()->all();
    foreach ([$a, $b, $c] as $member) {
        antiCheatCarries($member);
    }
    antiCheatVotesOn($a, [...$shared, ...$extraA]);
    antiCheatVotesOn($b, [...$shared, ...$extraB]);
    antiCheatVotesOn($c, $three);

    // When the rule runs with a component size of 2
    $findings = antiCheatR8(antiCheatConfig(['anticheat.coordinated.min_accounts' => 2]));

    // Then A and B are linked (4 of 5 = 0.8), C is not (3 shared is below the 4-product minimum)
    expect(collect($findings)->pluck('userId')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('does not link a pair whose overlap is below the setting even with enough shared products', function () {
    // Given A and B sharing 4 products, but B has 10 more (overlap 4/4 for the smaller set A is 1.0) and a third pair 4/6
    antiCheatFreeze();
    $shared = antiCheatProducts(4);
    [$a, $b] = User::factory()->count(2)->create()->all();
    foreach ([$a, $b] as $member) {
        antiCheatCarries($member);
    }
    antiCheatVotesOn($a, [...$shared, ...antiCheatProducts(2)]);
    antiCheatVotesOn($b, [...$shared, ...antiCheatProducts(2)]);

    // When the rule runs at overlap 0.8 and at overlap 0.6
    $strict = antiCheatR8(antiCheatConfig(['anticheat.coordinated.min_accounts' => 2]));
    $loose = antiCheatR8(antiCheatConfig(['anticheat.coordinated.min_accounts' => 2, 'anticheat.coordinated.overlap' => '0.6']));

    // Then 4/6 = 0.67 fails 0.8 and passes 0.6
    expect($strict)->toBe([])->and($loose)->toHaveCount(2);
});

it('builds components: A-B and B-C make a component of three even if A-C does not qualify', function () {
    // Given A shares 4 with B, B shares 4 other products with C, and A and C share none
    antiCheatFreeze();
    $ab = antiCheatProducts(4);
    $bc = antiCheatProducts(4);
    [$a, $b, $c] = User::factory()->count(3)->create()->all();
    foreach ([$a, $b, $c] as $member) {
        antiCheatCarries($member);
    }
    antiCheatVotesOn($a, $ab);
    antiCheatVotesOn($b, [...$ab, ...$bc]);
    antiCheatVotesOn($c, $bc);
    // B's set is 8, so 4/min(4, 8) = 1.0 for both pairs

    // When the rule runs
    $findings = antiCheatR8();

    // Then all three are in one component
    expect($findings)->toHaveCount(3)->and($findings[0]->details['user_ids'])->toHaveCount(3);
});

it('ignores a component of two when three are required', function () {
    // Given two linked members and a third candidate who votes for other products
    antiCheatFreeze();
    $products = antiCheatProducts(4);
    foreach (User::factory()->count(2)->create() as $member) {
        antiCheatCarries($member);
        antiCheatVotesOn($member, $products);
    }
    $outsider = User::factory()->create();
    antiCheatCarries($outsider);
    antiCheatVotesOn($outsider, antiCheatProducts(4));

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR8())->toBe([]);
});

it('keeps the strongest and newest candidates when the cap is reached', function () {
    // Given a ring with old weak signals and a ring with newer, stronger ones, and a rule capped at three candidates
    antiCheatFreeze();
    $oldProducts = antiCheatProducts(4);
    $newProducts = antiCheatProducts(4);
    $old = User::factory()->count(3)->create();
    $new = User::factory()->count(3)->create();
    foreach ($old as $member) {
        antiCheatCarries($member, ['detected_at' => antiCheatNow()->subDays(5), 'weight' => 10]);
        antiCheatVotesOn($member, $oldProducts);
    }
    foreach ($new as $member) {
        antiCheatCarries($member, ['detected_at' => antiCheatNow()->subDay(), 'weight' => 40]);
        antiCheatVotesOn($member, $newProducts);
    }
    $capped = new class extends CoordinatedVotesRule
    {
        protected const MAX_CANDIDATES = 3;
    };
    Log::spy();

    // When the rule runs capped
    $findings = antiCheatR8(rule: $capped);

    // Then the strong, newer ring is considered, and a warning with the cap only was logged
    expect(collect($findings)->pluck('userId')->sort()->values()->all())->toBe($new->pluck('id')->sort()->values()->all());
    Log::shouldHaveReceived('warning')->with('Coordinated votes candidate list was truncated.', ['cap' => 3])->once();
});

it('does not let 501 old weak candidates hide a ring this run just touched', function () {
    // Given 501 members with old, weak signals and no votes, and a new ring that voted for four products
    antiCheatFreeze();
    $now = antiCheatNow();
    $ids = [];
    for ($i = 0; $i < 501; $i++) {
        $ids[] = User::factory()->create()->id;
    }
    DB::table('risk_signals')->insert(array_map(fn (string $id) => [
        'id' => (string) Str::ulid(), 'kind' => 'fresh_account', 'user_id' => $id, 'product_id' => null, 'weight' => 1,
        'details' => '{}', 'dedupe_key' => 'old:'.$id, 'detected_at' => $now->subDays(10),
    ], $ids));
    $products = antiCheatProducts(4);
    $ring = User::factory()->count(3)->create();
    foreach ($ring as $member) {
        antiCheatCarries($member, ['detected_at' => $now, 'weight' => 15]);
        antiCheatVotesOn($member, $products);
    }
    Log::spy();

    // When the rule runs, once told which members this run touched and once without
    $withTouched = antiCheatR8(touched: $ring->pluck('id')->all());
    $without = antiCheatR8();

    // Then the ring is found either way (weight and recency rank it first), the list was cut, and the warning names a count
    expect($withTouched)->toHaveCount(3)->and($without)->toHaveCount(3);
    Log::shouldHaveReceived('warning')->with('Coordinated votes candidate list was truncated.', ['cap' => 500]);
});

it('always includes a touched member even when the cap would drop a stronger stranger', function () {
    // Given a stronger old ring and a weaker ring touched by this run, capped at three
    antiCheatFreeze();
    $strongProducts = antiCheatProducts(4);
    $touchedProducts = antiCheatProducts(4);
    $strong = User::factory()->count(3)->create();
    $touched = User::factory()->count(3)->create();
    foreach ($strong as $member) {
        antiCheatCarries($member, ['weight' => 90]);
        antiCheatVotesOn($member, $strongProducts);
    }
    foreach ($touched as $member) {
        antiCheatCarries($member, ['weight' => 5]);
        antiCheatVotesOn($member, $touchedProducts);
    }
    $capped = new class extends CoordinatedVotesRule
    {
        protected const MAX_CANDIDATES = 3;
    };

    // When the rule runs with the weak ring touched
    $findings = antiCheatR8(rule: $capped, touched: $touched->pluck('id')->all());

    // Then the touched ring is found (they fill the cap) and the stronger stranger ring is the one left out
    expect(collect($findings)->pluck('userId')->sort()->values()->all())->toBe($touched->pluck('id')->sort()->values()->all());
});

it('ignores products more than max_popularity_pct of the candidates voted for', function () {
    // Given a ring of three that shares 3 niche products and 1 popular product, among 10 candidates who all voted the popular one
    antiCheatFreeze();
    $niche = antiCheatProducts(3);
    $popular = antiCheatProducts(1);
    $ring = User::factory()->count(3)->create();
    foreach ($ring as $member) {
        antiCheatCarries($member);
        antiCheatVotesOn($member, [...$niche, ...$popular]);
    }
    foreach (User::factory()->count(7)->create() as $filler) {
        antiCheatCarries($filler);
        antiCheatVotesOn($filler, $popular);
    }

    // When the rule runs with the filter off (100) and with the default 40 percent
    $off = antiCheatR8(antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 100]));
    $on = antiCheatR8(antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 40]));

    // Then four shared products link the ring when the popular one counts; with it ignored only three remain (below 4)
    expect($off)->toHaveCount(3)->and($on)->toBe([]);
});

it('keeps a product voted by exactly the popularity share', function () {
    // Given 10 candidates of whom exactly 4 (40 percent) share four products
    antiCheatFreeze();
    $products = antiCheatProducts(4);
    $ring = User::factory()->count(4)->create();
    foreach ($ring as $member) {
        antiCheatCarries($member);
        antiCheatVotesOn($member, $products);
    }
    foreach (User::factory()->count(6)->create() as $filler) {
        antiCheatCarries($filler);
    }

    // When the rule runs with 40 percent ("more than 40" ignores a product)
    // Then 40 percent exactly is kept and the ring is found; at 39 it would be ignored
    expect(antiCheatR8(antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 40])))->toHaveCount(4);
    expect(antiCheatR8(antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 39])))->toBe([]);
});

it('caps the pair self-join with a transaction-local statement timeout and restores the previous value', function () {
    // Given a ring and a loaded config
    antiCheatFreeze();
    $products = antiCheatProducts(4);
    foreach (User::factory()->count(3)->create() as $member) {
        antiCheatCarries($member);
        antiCheatVotesOn($member, $products);
    }
    $config = antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 100]);

    // When the rule runs under the query recorder
    $queries = antiCheatQueries(fn () => antiCheatR8($config));

    // Then the timeout is set (locally) before the pair join and set back to the previous value right after it
    $set = array_keys(array_filter($queries, fn (string $sql) => str_contains($sql, 'set_config')));
    $join = array_keys(array_filter($queries, fn (string $sql) => str_contains($sql, 'json_agg(a.product_id')));
    expect($set)->toHaveCount(2)->and($join)->toHaveCount(1)->and($set[0])->toBeLessThan($join[0])->and($set[1])->toBeGreaterThan($join[0]);
});

it('does not take reviewed, coordinated, expired, weight-0 or deleted-member signals as candidates', function () {
    // Given rings whose members carry one kind of unusable signal each
    antiCheatFreeze();
    $products = antiCheatProducts(4);
    $cases = [
        fn (User $u) => antiCheatCarries($u, ['reviewed_by' => User::factory()->create()->id, 'reviewed_at' => antiCheatNow(), 'outcome' => RiskOutcome::Dismissed]),
        fn (User $u) => antiCheatCarries($u, ['kind' => RiskSignalKind::CoordinatedVotes]),
        fn (User $u) => antiCheatCarries($u, ['detected_at' => antiCheatNow()->subDays(14)->subSecond()]),
        fn (User $u) => antiCheatCarries($u, ['weight' => 0]),
    ];
    foreach ($cases as $makeSignal) {
        foreach (User::factory()->count(3)->create() as $member) {
            $makeSignal($member);
            antiCheatVotesOn($member, $products);
        }
    }

    // When the rule runs
    // Then no ring qualifies
    expect(antiCheatR8())->toBe([]);
});

it('counts a signal exactly decay_days old as a candidate', function () {
    // Given a ring whose signals are exactly 14 days old
    antiCheatFreeze();
    $products = antiCheatProducts(4);
    foreach (User::factory()->count(3)->create() as $member) {
        antiCheatCarries($member, ['detected_at' => antiCheatNow()->subDays(14)]);
        antiCheatVotesOn($member, $products);
    }

    // When the rule runs
    // Then it fires
    expect(antiCheatR8())->toHaveCount(3);
});

it('is switched off by a weight of 0 and caps shared_product_ids at 50', function () {
    // Given a ring that shares 55 products
    antiCheatFreeze();
    $products = antiCheatProducts(55);
    foreach (User::factory()->count(3)->create() as $member) {
        antiCheatCarries($member);
        antiCheatVotesOn($member, $products);
    }

    // When the rule runs, then with the weight at 0
    $findings = antiCheatR8();
    $off = antiCheatR8(antiCheatConfig(['anticheat.weight.coordinated_votes' => 0]));

    // Then the list is cut at 50 with the truncated flag, and the weight 0 silences it
    expect($findings[0]->details['shared_product_ids'])->toHaveCount(50)->and($findings[0]->details['truncated'])->toBeTrue()->and($off)->toBe([]);
});
