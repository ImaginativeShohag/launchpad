<?php

use App\Actions\AntiCheat\Rules\FreshAccountRule;
use App\Actions\AntiCheat\SignalWriter;
use App\Enums\RiskSignalKind;
use App\Models\User;

function antiCheatR4(User ...$users): array
{
    return antiCheatFindings(new FreshAccountRule, antiCheatUserScan(...$users));
}

/** A member created at the given instant who votes `$minutes` minutes and `$seconds` seconds after it. */
function antiCheatMemberVotingAfter(int $minutes, int $seconds = 0, array $userAttributes = []): User
{
    $created = antiCheatNow()->subDays(3);
    $user = User::factory()->create(['created_at' => $created, ...$userAttributes]);
    antiCheatVote($user, antiCheatLiveProduct(), ['voted_at' => $created->addMinutes($minutes)->addSeconds($seconds)]);

    return $user;
}

it('fires for a vote less than 30 minutes after signup, not at exactly 30', function () {
    // Given members who voted 29:59, 30:00 and 0 minutes after signup
    antiCheatFreeze();
    $inside = antiCheatMemberVotingAfter(29, 59);
    $exact = antiCheatMemberVotingAfter(30);
    $instant = antiCheatMemberVotingAfter(0);

    // When the rule runs
    $findings = antiCheatR4($inside, $exact, $instant);

    // Then the first and the third fire with their ages in whole minutes, the exact boundary does not
    expect(collect($findings)->pluck('userId')->sort()->values()->all())->toBe(collect([$inside->id, $instant->id])->sort()->values()->all());
    $byUser = collect($findings)->keyBy('userId');
    expect($byUser[$inside->id]->details['account_age_minutes'])->toBe(29)->and($byUser[$instant->id]->details['account_age_minutes'])->toBe(0);
    expect($byUser[$inside->id]->kind)->toBe(RiskSignalKind::FreshAccount)->and($byUser[$inside->id]->weight)->toBe(15)->and($byUser[$inside->id]->dedupeKey)->toBe('fresh_account:'.$inside->id);
    expect($byUser[$inside->id]->details)->toMatchArray(['rule' => 'fresh_account', 'threshold' => 30])->and($byUser[$inside->id]->details['vote_id'])->toBeString();
});

it('does not fire for an old account whose vote is recent', function () {
    // Given a member created long ago who votes now
    antiCheatFreeze();
    $user = User::factory()->create(['created_at' => antiCheatNow()->subYear()]);
    antiCheatVote($user, antiCheatLiveProduct());

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR4($user))->toBe([]);
});

it('fires once per account: a second vote and a later run add nothing', function () {
    // Given a fresh voter whose finding was written, then a second fresh vote
    antiCheatFreeze();
    $user = antiCheatMemberVotingAfter(5);
    antiCheatVote($user, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subDays(3)->addMinutes(8)]);
    $findings = antiCheatR4($user);
    $writer = app(SignalWriter::class);
    $first = $writer->write($findings, antiCheatNow());
    $second = $writer->write(antiCheatR4($user), antiCheatNow()->addMinutes(5));

    // Then there is one finding per run (the earliest vote), one row in all
    expect($findings)->toHaveCount(1)->and($first)->toBe(['fresh_account' => 1])->and($second)->toBe([]);
});

it('ignores a deleted account, a withdrawn vote and a voided vote', function () {
    // Given three fresh voters: deleted, withdrawn vote, voided vote
    antiCheatFreeze();
    $deleted = antiCheatMemberVotingAfter(1, userAttributes: ['deleted_at' => antiCheatNow()]);
    $withdrawn = User::factory()->create(['created_at' => antiCheatNow()->subDays(3)]);
    antiCheatVote($withdrawn, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subDays(3)->addMinute(), 'removed_at' => antiCheatNow()]);
    $voided = User::factory()->create(['created_at' => antiCheatNow()->subDays(3)]);
    antiCheatVote($voided, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subDays(3)->addMinute(), 'status' => 'voided', 'voided_at' => antiCheatNow(), 'voided_reason' => 'x']);

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR4($deleted, $withdrawn, $voided))->toBe([]);
});

it('obeys the minutes setting and the weight 0 switch', function () {
    // Given a member who voted 40 minutes after signup
    antiCheatFreeze();
    $user = antiCheatMemberVotingAfter(40);

    // When the window is widened to 60 minutes, then the weight is set to 0
    $fires = antiCheatFindings(new FreshAccountRule, antiCheatUserScan($user), antiCheatConfig(['anticheat.fresh_account.minutes' => 60]));
    $off = antiCheatFindings(new FreshAccountRule, antiCheatUserScan($user), antiCheatConfig(['anticheat.weight.fresh_account' => 0]));

    // Then it fires and then it is silent
    expect($fires)->toHaveCount(1)->and($off)->toBe([]);
});
