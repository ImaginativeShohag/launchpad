<?php

use App\Actions\AntiCheat\Rules\DisposableEmailRule;
use App\Actions\AntiCheat\Rules\FreshAccountRule;
use App\Actions\AntiCheat\Rules\Rule;
use App\Actions\AntiCheat\Rules\SingleMakerVoterRule;
use App\Actions\AntiCheat\Rules\UserBurstRule;
use App\Actions\AntiCheat\Scan;
use App\Models\User;

/** The number of queries one user rule runs for the given voters. */
function antiCheatUserRuleQueries(Rule $rule, array $userIds): int
{
    $config = antiCheatConfig();

    return count(antiCheatQueries(fn () => antiCheatFindings($rule, Scan::users(antiCheatNow(), $userIds), $config)));
}

it('runs a fixed number of queries per rule whether the chunk holds 10 voters or 500', function () {
    // Given 500 voters, each with one vote, a fresh account and a disposable address
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $ids = [];
    for ($i = 0; $i < 500; $i++) {
        $user = User::factory()->create(['email' => "u{$i}@mailinator.com", 'created_at' => antiCheatNow()->subMinutes(5)]);
        antiCheatVote($user, $product, ['voted_at' => antiCheatNow()->subMinute()]);
        $ids[] = $user->id;
    }

    foreach ([new UserBurstRule, new FreshAccountRule, new DisposableEmailRule, new SingleMakerVoterRule] as $rule) {
        // When the rule runs for 10 voters and for 500
        $ten = antiCheatUserRuleQueries($rule, array_slice($ids, 0, 10));
        $all = antiCheatUserRuleQueries($rule, $ids);

        // Then the number of queries is the same (set-based, never one query per voter)
        expect($all)->toBe($ten, $rule::class)->and($all)->toBeLessThanOrEqual(2);
    }
});
