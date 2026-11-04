<?php

use App\Enums\RiskOutcome;
use App\Enums\Role;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    antiCheatFreeze();
    signedInStaff(Role::Reviewer);
});

function antiCheatRiskMember(int $score, array $attributes = []): User
{
    return antiCheatSetScore(User::factory()->create($attributes), $score);
}

it('defaults min_score to the flag score and follows the setting', function () {
    // Given members at 69, 70 and 90
    $low = antiCheatRiskMember(69);
    $edge = antiCheatRiskMember(70);
    $high = antiCheatRiskMember(90);

    // When the list is read with the default, and after the flag score is lowered to 50
    $default = antiCheatIds(antiCheatGet('risk/members'));
    antiCheatConfig(['anticheat.flag_risk_score' => 50]);
    $lowered = antiCheatIds(antiCheatGet('risk/members'));

    // Then the default is 70 and then 50; ordered by score
    expect($default)->toBe([$high->id, $edge->id])->and($lowered)->toBe([$high->id, $edge->id, $low->id]);
    $mid = antiCheatRiskMember(55);
    expect(antiCheatIds(antiCheatGet('risk/members')))->toBe([$high->id, $edge->id, $low->id, $mid->id]);
    expect(antiCheatIds(antiCheatGet('risk/members', ['min_score' => 1])))->toContain($low->id);
});

it('lists low scores with min_score 1, walks equal scores by id, and skips deleted members', function () {
    // Given five members at 30 (ties), one at 10, a deleted one at 99 and a member at 0
    $tied = array_map(fn () => antiCheatRiskMember(30), range(1, 5));
    $ten = antiCheatRiskMember(10);
    antiCheatRiskMember(99, ['deleted_at' => antiCheatNow()->subDay()]);
    antiCheatRiskMember(0);

    // When walked with two per page
    $walk = antiCheatWalk('risk/members', ['min_score' => 1]);

    // Then score desc, id desc, each once
    $expected = collect($tied)->pluck('id')->sort()->reverse()->values()->all();
    expect($walk)->toBe([...$expected, $ten->id]);
});

it('filters by suspension', function () {
    // Given a suspended and an active high-score member
    $suspended = antiCheatRiskMember(80, ['suspended_at' => antiCheatNow()->subHour()]);
    $active = antiCheatRiskMember(80);

    // When the filter is applied
    // Then each side comes back alone
    expect(antiCheatIds(antiCheatGet('risk/members', ['suspended' => 'true'])))->toBe([$suspended->id]);
    expect(antiCheatIds(antiCheatGet('risk/members', ['suspended' => 'false'])))->toBe([$active->id]);
});

it('pins the item keys and counts open signals and flagged votes correctly', function () {
    // Given a member with two open signals, one reviewed, one flagged and one counted vote, aged ten days
    $member = antiCheatRiskMember(75, ['created_at' => antiCheatNow()->subDays(10)->subHours(5)]);
    RiskSignal::factory()->count(2)->create(['user_id' => $member->id]);
    RiskSignal::factory()->reviewed(RiskOutcome::Confirmed)->create(['user_id' => $member->id]);
    antiCheatVote($member, null, ['status' => 'flagged']);
    antiCheatVote($member, null);
    antiCheatVote($member, null, ['status' => 'flagged', 'removed_at' => antiCheatNow(), 'toggle_count' => 1]);

    // When the list is read
    $item = antiCheatGet('risk/members')->assertOk()->json('data.0');

    // Then the keys and counts match
    expect(array_keys($item))->toBe(['id', 'handle', 'name', 'risk_score', 'suspended', 'account_age_days', 'open_signals', 'flagged_votes']);
    expect($item)->toMatchArray(['id' => $member->id, 'risk_score' => 75, 'suspended' => false, 'account_age_days' => 10, 'open_signals' => 2, 'flagged_votes' => 1]);
});

it('runs the same number of queries for 5 and for 50 members', function () {
    // Given five members, counted, then fifty
    foreach (range(1, 5) as $_) {
        antiCheatRiskMember(80);
    }
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        antiCheatGet('risk/members', ['per_page' => 100])->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count(); // warms the settings cache, which the first read would otherwise add to the tally
    $five = $count();
    foreach (range(1, 45) as $_) {
        $m = antiCheatRiskMember(80);
        RiskSignal::factory()->create(['user_id' => $m->id]);
        antiCheatVote($m, null, ['status' => 'flagged']);
    }

    // When the list is read again
    $fifty = $count();

    // Then the count of queries is unchanged
    expect($fifty)->toBe($five);
});

it('answers 422 for every malformed parameter and tampered cursor', function (string $query) {
    // Given a reviewer
    antiCheatRiskMember(80);

    // When the list is read with the bad parameter
    // Then it is a 422
    antiCheatGet('risk/members', $query)->assertStatus(422);
})->with([
    'min_score zero' => 'min_score=0',
    'min_score over' => 'min_score=101',
    'min_score float' => 'min_score=1.5',
    'min_score array' => 'min_score[]=5',
    'min_score object' => 'min_score[a]=5',
    'min_score true' => 'min_score=true',
    'min_score null' => 'min_score=',
    'min_score garbage' => 'min_score=abc',
    'suspended maybe' => 'suspended=maybe',
    'suspended 1' => 'suspended=1',
    'suspended array' => 'suspended[]=true',
    'suspended null' => 'suspended=',
    'per_page zero' => 'per_page=0',
    'per_page over' => 'per_page=101',
    'per_page object' => 'per_page[a]=1',
    'cursor garbage' => 'cursor=!!!',
    'cursor array' => 'cursor[]=x',
    'cursor null json' => 'cursor=bnVsbA',
]);

it('rejects cursors of the wrong shape and accepts an empty one', function () {
    // Given a member
    antiCheatRiskMember(80);
    $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When wrong-shaped cursors are sent
    // Then each is a 422
    foreach ([
        ['risk_score' => 80, 'id' => $ulid],
        ['risk_score' => '80', 'id' => $ulid, '_pointsToNextItems' => true],
        ['risk_score' => 80, 'id' => 'nope', '_pointsToNextItems' => true],
        ['weight' => 80, 'id' => $ulid, '_pointsToNextItems' => true],
    ] as $payload) {
        antiCheatGet('risk/members', ['cursor' => antiCheatCursor($payload)])->assertStatus(422);
    }

    // And an empty cursor is page one
    antiCheatGet('risk/members', 'cursor=&min_score=1')->assertOk();
});
