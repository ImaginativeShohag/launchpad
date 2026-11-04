<?php

use App\Actions\AntiCheat\ScoreCalculator;
use App\Actions\AntiCheat\ScoredSignal;
use App\Enums\AuthEventType;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonImmutable;

beforeEach(function () {
    antiCheatFreeze();
    signedInStaff(Role::Reviewer);
});

it('breaks the score down exactly as the calculator does and lists the last 20 signals', function () {
    // Given a member with open, confirmed and dismissed signals and 25 more
    $member = User::factory()->create(['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 7);
    antiCheatSignal($member, RiskSignalKind::DisposableEmail, 25, 30, RiskOutcome::Confirmed);
    antiCheatSignal($member, RiskSignalKind::LoginChurn, 20, 1, RiskOutcome::Dismissed);
    foreach (range(1, 25) as $i) {
        antiCheatSignal($member, RiskSignalKind::FreshAccount, 15, 0);
    }

    // When the summary is read
    $data = antiCheatGet('risk/members/'.$member->id)->assertOk()->json('data');

    // Then the breakdown equals the calculator over the same rows, and 20 signals are listed
    $rows = RiskSignal::query()->where('user_id', $member->id)->get()->map(fn ($s) => new ScoredSignal($s->kind, $s->weight, CarbonImmutable::instance($s->detected_at), $s->outcome));
    expect($data['breakdown'])->toBe((new ScoreCalculator)->breakdown($rows, antiCheatNow(), 14));
    expect($data['signals'])->toHaveCount(20)->and(array_keys($data['signals'][0]))->toContain('id', 'kind', 'member');
    expect(array_keys($data))->toBe(['id', 'handle', 'name', 'role', 'created_at', 'suspended', 'suspended_at', 'suspended_reason', 'deleted', 'risk_score', 'breakdown', 'votes', 'sign_ins', 'linked_accounts', 'signals']);
});

it('computes vote stats: a member who gave 4 of 5 votes to one maker', function () {
    // Given a member with five votes (four on one maker's products), a flagged, a voided and a withdrawn vote
    $member = User::factory()->create();
    $maker = User::factory()->create();
    foreach (range(1, 4) as $_) {
        antiCheatVote($member, antiCheatLiveProduct(null, ['maker_id' => $maker->id]));
    }
    antiCheatVote($member, antiCheatLiveProduct(), ['status' => 'flagged']);
    Vote::factory()->voided()->create(['user_id' => $member->id, 'product_id' => antiCheatLiveProduct()->id, 'voted_at' => antiCheatNow()]);
    antiCheatVote($member, antiCheatLiveProduct(), ['removed_at' => antiCheatNow(), 'toggle_count' => 1]);

    // When the summary is read
    $votes = antiCheatGet('risk/members/'.$member->id)->assertOk()->json('data.votes');

    // Then the numbers match
    expect($votes)->toBe(['active' => 5, 'flagged' => 1, 'voided' => 1, 'withdrawn' => 1, 'distinct_makers' => 2, 'top_maker_share' => 0.8]);
});

it('counts sign-in devices and IP groups in the last 24 hours and 7 days, as numbers only', function () {
    // Given sign-in events from several devices over time (failed logins and other members ignored)
    $member = User::factory()->create();
    antiCheatAuthEvent($member, antiCheatNow()->subHours(2), 'a');
    antiCheatAuthEvent($member, antiCheatNow()->subHours(3), 'b');
    antiCheatAuthEvent($member, antiCheatNow()->subDays(3), 'c');
    antiCheatAuthEvent($member, antiCheatNow()->subDays(8), 'd');
    antiCheatAuthEvent($member, antiCheatNow()->subHours(1), 'e', AuthEventType::LoginFailed);
    antiCheatAuthEvent(User::factory()->create(), antiCheatNow()->subHours(1), 'f');

    // When the summary is read
    $response = antiCheatGet('risk/members/'.$member->id)->assertOk();

    // Then counts only
    expect($response->json('data.sign_ins'))->toBe(['devices_24h' => 2, 'devices_7d' => 3, 'ip_groups_24h' => 2, 'ip_groups_7d' => 3]);
    expect($response->getContent())->not->toMatch('/[0-9a-f]{64}/');
});

it('links accounts that shared a device or an IP on a vote within 48 hours, and not beyond', function () {
    // Given a member sharing a device with one account (recent), an IP with another (recent), and a device with a third 3 days ago
    $member = User::factory()->create();
    $device = User::factory()->create();
    $ip = User::factory()->create();
    $old = User::factory()->create();
    antiCheatVote($member, antiCheatLiveProduct(), ['device_hash' => antiCheatHash('d1'), 'ip_hash' => antiCheatHash('i1')]);
    antiCheatVote($device, antiCheatLiveProduct(), ['device_hash' => antiCheatHash('d1'), 'ip_hash' => antiCheatHash('other')]);
    antiCheatVote($ip, antiCheatLiveProduct(), ['device_hash' => antiCheatHash('other2'), 'ip_hash' => antiCheatHash('i1')]);
    antiCheatVote($old, antiCheatLiveProduct(), ['device_hash' => antiCheatHash('d1'), 'ip_hash' => antiCheatHash('other3'), 'voted_at' => antiCheatNow()->subDays(3)]);

    // When the summary is read
    $linked = antiCheatGet('risk/members/'.$member->id)->assertOk()->json('data.linked_accounts');

    // Then the two recent ones appear, with the way they are linked
    expect(collect($linked)->map(fn ($l) => [$l['id'], $l['via']])->sort()->values()->all())->toBe(collect([[$device->id, 'device'], [$ip->id, 'ip']])->sort()->values()->all());
    expect(array_keys($linked[0]))->toBe(['id', 'handle', 'via']);
});

it('links nobody once the hashes were purged', function () {
    // Given the same shape of shared device, with the hashes nulled
    $member = User::factory()->create();
    antiCheatVote($member, antiCheatLiveProduct(), ['device_hash' => null, 'ip_hash' => null]);
    antiCheatVote(User::factory()->create(), antiCheatLiveProduct(), ['device_hash' => null, 'ip_hash' => null]);

    // When the summary is read
    // Then nobody is linked
    expect(antiCheatGet('risk/members/'.$member->id)->assertOk()->json('data.linked_accounts'))->toBe([]);
});

it('shows a deleted member as the anonymised row, with no e-mail', function () {
    // Given a deleted member
    $deleted = User::factory()->create(['handle' => 'deleted-q1', 'name' => 'Deleted member', 'deleted_at' => antiCheatNow(), 'email' => 'secret-addr@deleted.invalid']);

    // When the summary is read
    $response = antiCheatGet('risk/members/'.$deleted->id)->assertOk();

    // Then it is the anonymised row
    expect($response->json('data'))->toMatchArray(['handle' => 'deleted-q1', 'name' => 'Deleted member', 'deleted' => true]);
    expect($response->getContent())->not->toContain('secret-addr');
});

it('shows suspension basics', function () {
    // Given a suspended member
    $member = User::factory()->create(['suspended_at' => antiCheatNow()->subDay(), 'suspended_reason' => 'Ring of fake accounts']);

    // When the summary is read
    // Then the state is visible
    expect(antiCheatGet('risk/members/'.$member->id)->assertOk()->json('data'))->toMatchArray(['suspended' => true, 'suspended_reason' => 'Ring of fake accounts']);
});
