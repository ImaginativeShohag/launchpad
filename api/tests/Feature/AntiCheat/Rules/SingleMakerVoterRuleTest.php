<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\SingleMakerVoterRule;
use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Enums\RiskSignalKind;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonInterface;

function antiCheatR6(User $user, ?RuleConfig $config = null): array
{
    return antiCheatFindings(new SingleMakerVoterRule, antiCheatUserScan($user), $config);
}

/** A live product of the given maker. */
function antiCheatProductOf(User $maker): Product
{
    return antiCheatLiveProduct(attributes: ['maker_id' => $maker->id]);
}

/** Votes by `$voter`: `$forMaker` on products of `$maker`, `$others` on products of fresh makers. */
function antiCheatVoterSplit(User $voter, User $maker, int $forMaker, int $others, ?CarbonInterface $at = null): void
{
    for ($i = 0; $i < $forMaker; $i++) {
        antiCheatVote($voter, antiCheatProductOf($maker), ['voted_at' => $at ?? antiCheatNow()->subDays(1)]);
    }
    for ($i = 0; $i < $others; $i++) {
        antiCheatVote($voter, antiCheatLiveProduct(), ['voted_at' => $at ?? antiCheatNow()->subDays(1)]);
    }
}

it('fires at 4 of 5 votes (0.8) and not at 4 of 6 (0.67)', function () {
    // Given two voters, one with 4 of 5 votes to a maker and one with 4 of 6
    antiCheatFreeze();
    $maker = User::factory()->create();
    $eighty = User::factory()->create();
    $sixtySeven = User::factory()->create();
    antiCheatVoterSplit($eighty, $maker, 4, 1);
    antiCheatVoterSplit($sixtySeven, $maker, 4, 2);

    // When the rule runs for each
    $fires = antiCheatR6($eighty);
    $quiet = antiCheatR6($sixtySeven);

    // Then only the first fires, with the maker, the counts and the share in the evidence
    expect($quiet)->toBe([])->and($fires)->toHaveCount(1);
    expect($fires[0]->kind)->toBe(RiskSignalKind::SingleMakerVoter)->and($fires[0]->weight)->toBe(20)->and($fires[0]->userId)->toBe($eighty->id)->and($fires[0]->dedupeKey)->toBe('single_maker_voter:'.$eighty->id.':'.$maker->id);
    expect($fires[0]->details)->toMatchArray(['rule' => 'single_maker_voter', 'maker_id' => $maker->id, 'votes' => 4, 'total_votes' => 5, 'share' => 0.8, 'observed' => 4]);
});

it('needs the minimum number of votes in total', function () {
    // Given a voter with 3 votes, all to one maker (below the default minimum of 5)
    antiCheatFreeze();
    $maker = User::factory()->create();
    $voter = User::factory()->create();
    antiCheatVoterSplit($voter, $maker, 3, 0);

    // When the rule runs
    // Then nothing fires; with the minimum lowered to 3 it does
    expect(antiCheatR6($voter))->toBe([]);
    expect(antiCheatR6($voter, antiCheatConfig(['anticheat.single_maker.min_votes' => 3])))->toHaveCount(1);
});

it('counts a soft-deleted product, and a maker can never be the voter', function () {
    // Given five votes to one maker, one of the products soft-deleted
    antiCheatFreeze();
    $maker = User::factory()->create();
    $voter = User::factory()->create();
    antiCheatVoterSplit($voter, $maker, 5, 0);
    Product::query()->where('maker_id', $maker->id)->first()->delete();

    // When the rule runs
    // Then the deleted product still counts
    expect(antiCheatR6($voter))->toHaveCount(1);
});

it('cannot build the case from a vote for the voter\'s own product, so fixtures use other makers', function () {
    // Given a live product and its maker
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $maker = User::query()->findOrFail($product->maker_id);

    // When the maker votes for it through the real action
    // Then it is refused as own_product, so the rule's "votes to one maker" can never include the voter's own maker row
    $attempt = fn () => app(CastVote::class)->handle($maker, $product->id, new VoteSignals(null, null, null));
    expect($attempt)->toThrow(ProductRuleViolation::class);
    expect(Vote::query()->count())->toBe(0);
});

it('ignores votes older than the window, measured to the second', function () {
    // Given five votes to one maker: exactly 30 days old, and one second older
    antiCheatFreeze();
    $maker = User::factory()->create();
    $edge = User::factory()->create();
    $outside = User::factory()->create();
    antiCheatVoterSplit($edge, $maker, 5, 0, antiCheatNow()->subDays(30));
    antiCheatVoterSplit($outside, $maker, 5, 0, antiCheatNow()->subDays(30)->subSecond());

    // When the rule runs
    // Then the votes exactly at the window start count, the older ones do not
    expect(antiCheatR6($edge))->toHaveCount(1);
    expect(antiCheatR6($outside))->toBe([]);
});

it('follows the share, minimum and weight settings', function () {
    // Given 3 of 5 votes to one maker (0.6)
    antiCheatFreeze();
    $maker = User::factory()->create();
    $voter = User::factory()->create();
    antiCheatVoterSplit($voter, $maker, 3, 2);

    // When the share is lowered to 0.6, then the weight to 0
    $fires = antiCheatR6($voter, antiCheatConfig(['anticheat.single_maker.share' => '0.6']));
    $off = antiCheatR6($voter, antiCheatConfig(['anticheat.weight.single_maker_voter' => 0]));

    // Then it fires at exactly the share and is then silent
    expect($fires)->toHaveCount(1)->and($off)->toBe([]);
});

it('ignores withdrawn and voided votes and a deleted voter', function () {
    // Given a voter whose 5 votes to one maker are 2 good, 1 withdrawn, 1 voided and 1 good by a deleted voter's twin
    antiCheatFreeze();
    $maker = User::factory()->create();
    $voter = User::factory()->create();
    antiCheatVote($voter, antiCheatProductOf($maker));
    antiCheatVote($voter, antiCheatProductOf($maker));
    antiCheatVote($voter, antiCheatProductOf($maker), ['removed_at' => antiCheatNow()]);
    antiCheatVote($voter, antiCheatProductOf($maker), ['status' => 'voided', 'voided_at' => antiCheatNow(), 'voided_reason' => 'x']);
    $gone = User::factory()->create(['deleted_at' => antiCheatNow()]);
    antiCheatVoterSplit($gone, $maker, 5, 0);

    // When the rule runs for both
    // Then neither fires
    expect(antiCheatR6($voter))->toBe([])->and(antiCheatR6($gone))->toBe([]);
});
