<?php

use App\Actions\AntiCheat\Rules\UserBurstRule;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\SignalWriter;
use App\Enums\RiskSignalKind;
use App\Models\User;
use Illuminate\Database\QueryException;

function antiCheatR3b(User ...$users): array
{
    return antiCheatFindings(new UserBurstRule, antiCheatUserScan(...$users));
}

/** `$count` votes by one member on different products, `$secondsAgo` before the fixed clock. */
function antiCheatBurstByUser(User $user, int $count, int $secondsAgo = 30): array
{
    $votes = [];
    for ($i = 0; $i < $count; $i++) {
        $votes[] = antiCheatVote($user, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subSeconds($secondsAgo + $i)]);
    }

    return $votes;
}

it('fires on eight distinct products in five minutes and not on seven', function () {
    // Given one member with 8 votes and another with 7, all inside five minutes
    antiCheatFreeze();
    $eight = User::factory()->create();
    $seven = User::factory()->create();
    $votes = antiCheatBurstByUser($eight, 8);
    antiCheatBurstByUser($seven, 7);

    // When the rule runs
    $findings = antiCheatR3b($eight, $seven);

    // Then only the member with eight fires, with the user scope and the weight
    expect($findings)->toHaveCount(1);
    expect($findings[0]->kind)->toBe(RiskSignalKind::VoteBurst)->and($findings[0]->userId)->toBe($eight->id)->and($findings[0]->productId)->toBeNull()->and($findings[0]->weight)->toBe(25);
    expect($findings[0]->details)->toMatchArray(['scope' => 'user', 'observed' => 8, 'threshold' => 8, 'window_minutes' => 5, 'rule' => 'vote_burst']);
    expect($findings[0]->details['vote_ids'])->toBe(array_map(fn ($v) => $v->id, array_reverse($votes)));
    expect($findings[0]->dedupeKey)->toBe('vote_burst:user:'.$eight->id.':2026-10-21');
});

it('measures the sliding window to the second', function () {
    // Given 7 votes at one instant a minute ago plus an 8th exactly five minutes before them, and for another member one a second older
    antiCheatFreeze();
    $edge = User::factory()->create();
    $outside = User::factory()->create();
    $cluster = antiCheatNow()->subSeconds(60);
    foreach ([$edge, $outside] as $user) {
        for ($i = 0; $i < 7; $i++) {
            antiCheatVote($user, antiCheatLiveProduct(), ['voted_at' => $cluster]);
        }
    }
    antiCheatVote($edge, antiCheatLiveProduct(), ['voted_at' => $cluster->subMinutes(5)]);
    antiCheatVote($outside, antiCheatLiveProduct(), ['voted_at' => $cluster->subMinutes(5)->subSecond()]);

    // When the rule runs
    // Then the vote exactly one window before the last counts and the older one does not
    expect(antiCheatR3b($edge))->toHaveCount(1);
    expect(antiCheatR3b($outside))->toBe([]);
});

it('catches a burst split across two five-minute runs, and only once', function () {
    // Given 4 votes six minutes ago and 4 votes two minutes ago: no single run window of the last 5 minutes holds all 8
    antiCheatFreeze();
    $user = User::factory()->create();
    foreach ([6, 6, 6, 6, 2, 2, 2, 2] as $minutesAgo) {
        antiCheatVote($user, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subMinutes($minutesAgo)]);
    }
    $writer = app(SignalWriter::class);

    // When the run at "now" sees them and the next run, five minutes later, sees the same votes again
    $first = $writer->write(antiCheatR3b($user), antiCheatNow());
    $laterNow = antiCheatNow()->addMinutes(5);
    $later = $writer->write(antiCheatFindings(new UserBurstRule, Scan::users($laterNow, [$user->id])), $laterNow);

    // Then the burst is found by the first run (eight votes within five minutes of each other) and the second adds nothing
    expect($first)->toBe(['vote_burst' => 1])->and($later)->toBe([]);
});

it('cannot count one product twice because the row is unique', function () {
    // Given a member with 7 products and a second vote row on one of them
    antiCheatFreeze();
    $user = User::factory()->create();
    $votes = antiCheatBurstByUser($user, 7);

    // When a second row for the same product is attempted
    // Then the database refuses it, so a re-vote can only reuse the row and counts once
    expect(fn () => antiCheatVote($user, $votes[0]->product_id))->toThrow(QueryException::class);
});

it('ignores withdrawn and voided votes and keeps members apart', function () {
    // Given a member with 6 good votes, one withdrawn and one voided, and another member with 8 good ones
    antiCheatFreeze();
    $mixed = User::factory()->create();
    $clean = User::factory()->create();
    antiCheatBurstByUser($mixed, 6);
    antiCheatVote($mixed, antiCheatLiveProduct(), ['removed_at' => antiCheatNow(), 'voted_at' => antiCheatNow()->subSeconds(5)]);
    antiCheatVote($mixed, antiCheatLiveProduct(), ['status' => 'voided', 'voided_at' => antiCheatNow(), 'voided_reason' => 'x', 'voted_at' => antiCheatNow()->subSeconds(6)]);
    antiCheatBurstByUser($clean, 8);

    // When the rule runs
    $findings = antiCheatR3b($mixed, $clean);

    // Then only the clean burst fires
    expect($findings)->toHaveCount(1)->and($findings[0]->userId)->toBe($clean->id);
});

it('gives a new key on the next UTC day', function () {
    // Given a burst written today
    antiCheatFreeze();
    $user = User::factory()->create();
    antiCheatBurstByUser($user, 8);
    $writer = app(SignalWriter::class);
    $today = $writer->write(antiCheatR3b($user), antiCheatNow());

    // When the same member bursts again the next day
    $tomorrow = antiCheatNow()->addDay();
    for ($i = 0; $i < 8; $i++) {
        antiCheatVote($user, antiCheatLiveProduct($tomorrow->toDateString()), ['voted_at' => $tomorrow->subSeconds(10 + $i)]);
    }
    $second = $writer->write(antiCheatFindings(new UserBurstRule, Scan::users($tomorrow, [$user->id])), $tomorrow);

    // Then a second row exists
    expect($today)->toBe(['vote_burst' => 1])->and($second)->toBe(['vote_burst' => 1]);
});

it('is switched off by a weight of 0 and obeys the settings', function () {
    // Given a member with 5 votes in two minutes
    antiCheatFreeze();
    $user = User::factory()->create();
    antiCheatBurstByUser($user, 5, 30);

    // When the threshold is lowered to 5, then the weight set to 0
    $fires = antiCheatFindings(new UserBurstRule, antiCheatUserScan($user), antiCheatConfig(['anticheat.user_burst.votes' => 5]));
    $off = antiCheatFindings(new UserBurstRule, antiCheatUserScan($user), antiCheatConfig(['anticheat.weight.vote_burst' => 0]));

    // Then it fires and then it is silent
    expect($fires)->toHaveCount(1)->and($off)->toBe([]);
});
