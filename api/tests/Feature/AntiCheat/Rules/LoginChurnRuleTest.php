<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\LoginChurnRule;
use App\Actions\AntiCheat\Scan;
use App\Enums\AuthEventType;
use App\Enums\RiskSignalKind;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

function antiCheatR7(?RuleConfig $config = null, ?LoginChurnRule $rule = null): array
{
    return antiCheatFindings($rule ?? new LoginChurnRule, Scan::run(antiCheatNow()), $config);
}

/** Members who all logged in from one device, each with `$voters` of them having an active vote in the window. */
function antiCheatChurn(string $device, int $accounts, int $voters, ?CarbonInterface $at = null, AuthEventType $event = AuthEventType::LoginSucceeded): array
{
    $users = [];
    for ($i = 0; $i < $accounts; $i++) {
        $user = User::factory()->create();
        antiCheatAuthEvent($user, $at ?? antiCheatNow()->subHour(), $device, $event);
        if ($i < $voters) {
            antiCheatVote($user, antiCheatLiveProduct());
        }
        $users[] = $user;
    }

    return $users;
}

it('fires for four accounts on one device that each voted', function () {
    // Given four accounts that logged in from one device and voted
    antiCheatFreeze();
    $users = antiCheatChurn('lab', 4, 4);

    // When the rule runs (once per run)
    $findings = antiCheatR7();

    // Then each voter gets a finding with the group evidence and the frozen weight
    expect($findings)->toHaveCount(4);
    $ids = collect($users)->pluck('id')->sort()->values()->all();
    foreach ($findings as $finding) {
        expect($finding->kind)->toBe(RiskSignalKind::LoginChurn)->and($finding->weight)->toBe(20)->and($finding->productId)->toBeNull();
        expect($finding->details['user_ids'])->toBe($ids)->and($finding->details['observed'])->toBe(4)->and($finding->details['threshold'])->toBe(4)->and($finding->details['auth_event_ids'])->toHaveCount(4)->and($finding->details['window_minutes'])->toBe(1440);
        expect($finding->dedupeKey)->toBe('login_churn:'.$finding->userId.':2026-10-21');
    }
});

it('leaves a household that never voted alone, and only touches the members who voted', function () {
    // Given a household of four with no vote, and a second device with four accounts of which one voted
    antiCheatFreeze();
    antiCheatChurn('household', 4, 0);
    $mixed = antiCheatChurn('mixed', 4, 1);

    // When the rule runs
    $findings = antiCheatR7();

    // Then only the voting member of the second group is found, and observed counts all four accounts
    expect($findings)->toHaveCount(1)->and($findings[0]->userId)->toBe($mixed[0]->id)->and($findings[0]->details['observed'])->toBe(4)->and($findings[0]->details['user_ids'])->toBe([$mixed[0]->id]);
});

it('needs the minimum number of accounts', function () {
    // Given a group of three voters (the default is four)
    antiCheatFreeze();
    antiCheatChurn('three', 3, 3);

    // When the rule runs with the default and with a minimum of 3
    // Then it fires only for the lower minimum
    expect(antiCheatR7())->toBe([]);
    expect(antiCheatR7(antiCheatConfig(['anticheat.login_churn.min_accounts' => 3])))->toHaveCount(3);
});

it('ignores failed logins, old events and a null device', function () {
    // Given four voters whose events are failed logins, four with events past the window, and four with no device
    antiCheatFreeze();
    antiCheatChurn('failed', 4, 4, event: AuthEventType::LoginFailed);
    antiCheatChurn('old', 4, 4, antiCheatNow()->subHours(24)->subSecond());
    for ($i = 0; $i < 4; $i++) {
        $user = User::factory()->create();
        antiCheatAuthEvent($user, antiCheatNow()->subHour(), 'x', attributes: ['device_hash' => null]);
        antiCheatVote($user, antiCheatLiveProduct());
    }

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR7())->toBe([]);
});

it('counts a login exactly at the window start, and signup events', function () {
    // Given four accounts: signups exactly 24 hours old
    antiCheatFreeze();
    antiCheatChurn('edge', 4, 4, antiCheatNow()->subHours(24), AuthEventType::Signup);

    // When the rule runs
    // Then the events at the window start count
    expect(antiCheatR7())->toHaveCount(4);
});

it('keeps two devices as separate groups', function () {
    // Given two devices with two accounts each (together four, but never four on one device)
    antiCheatFreeze();
    antiCheatChurn('one', 2, 2);
    antiCheatChurn('two', 2, 2);

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR7())->toBe([]);
});

it('does not count a deleted member and needs the vote inside the window', function () {
    // Given four accounts on a device: one deleted, one whose vote is older than the window
    antiCheatFreeze();
    $users = antiCheatChurn('lab', 4, 0);
    antiCheatVote($users[0], antiCheatLiveProduct());
    antiCheatVote($users[1], antiCheatLiveProduct());
    antiCheatVote($users[2], antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subHours(24)->subSecond()]);
    antiCheatVote($users[3], antiCheatLiveProduct());
    $users[3]->forceFill(['deleted_at' => antiCheatNow()])->save();

    // When the rule runs
    $findings = antiCheatR7();

    // Then only the two live voters inside the window are found (the group still has four accounts)
    expect(collect($findings)->pluck('userId')->sort()->values()->all())->toBe(collect([$users[0]->id, $users[1]->id])->sort()->values()->all());
    expect($findings[0]->details['observed'])->toBe(4);
});

it('keeps the largest groups when the cap is reached, and warns without a hash', function () {
    // Given a group of 5, one of 4, and a rule capped at one group
    antiCheatFreeze();
    $big = antiCheatChurn('big', 5, 5);
    antiCheatChurn('small', 4, 4);
    $capped = new class extends LoginChurnRule
    {
        protected const MAX_DEVICE_GROUPS = 1;
    };
    Log::spy();

    // When the rule runs
    $findings = antiCheatR7(rule: $capped);

    // Then only the larger group is found and one warning with counts only was logged
    expect(collect($findings)->pluck('userId')->sort()->values()->all())->toBe(collect($big)->pluck('id')->sort()->values()->all());
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context === ['cap' => 1])->once();
});

it('carries only ids and counts', function () {
    // Given a group
    antiCheatFreeze();
    antiCheatChurn('lab', 4, 4);

    // When the rule runs
    $json = json_encode(array_map(fn ($f) => $f->details, antiCheatR7()));

    // Then no hash-shaped value and no hash key appear
    expect($json)->not->toMatch('/[0-9a-f]{64}/')->not->toContain('device_hash')->not->toContain('ip_hash');
});

it('is switched off by a weight of 0', function () {
    // Given a group
    antiCheatFreeze();
    antiCheatChurn('lab', 4, 4);

    // When the weight is 0
    // Then the rule is silent
    expect(antiCheatR7(antiCheatConfig(['anticheat.weight.login_churn' => 0])))->toBe([]);
});
