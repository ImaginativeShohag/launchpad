<?php

use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    antiCheatFreeze();
    signedInStaff(Role::Reviewer);
});

it('resolves members, votes with fingerprints and the product from the evidence', function () {
    // Given a ring of four found by the job
    $product = antiCheatLiveProduct();
    $ring = antiCheatRing($product, 4, userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatDetect();
    $signal = RiskSignal::query()->where('kind', 'shared_device')->where('user_id', $ring[0]->id)->sole();

    // When the detail is read
    $response = antiCheatGet('risk-signals/'.$signal->id)->assertOk();

    // Then the list keys come with the stored details and the evidence
    $data = $response->json('data');
    expect(array_keys($data))->toBe(['id', 'kind', 'status', 'weight', 'detected_at', 'reviewed_at', 'reviewer', 'review_note', 'outcome', 'member', 'product', 'observed', 'threshold', 'details', 'evidence']);
    expect($data['details'])->toEqual($signal->details);
    expect($data['evidence']['members'])->toHaveCount(4)->and($data['evidence']['votes'])->toHaveCount(4);
    expect(array_keys($data['evidence']['members'][0]))->toBe(['id', 'handle', 'name', 'role', 'risk_score', 'created_at', 'suspended', 'deleted']);
    expect($data['evidence']['product'])->toBe(['id' => $product->id, 'slug' => $product->slug, 'name' => $product->name, 'launch_date' => $product->launch_date->toDateString()]);
    $fp = antiCheatHash('device-ring');
    foreach ($data['evidence']['votes'] as $vote) {
        expect($vote['device_fp'])->toBe(substr($fp, 0, 8))->and($vote['device_fp'])->toMatch('/\A[0-9a-f]{8}\z/')->and($vote['ip_fp'])->toMatch('/\A[0-9a-f]{8}\z/');
        expect(array_keys($vote))->toBe(['id', 'user_id', 'status', 'voted_at', 'removed', 'device_fp', 'ip_fp']);
    }
    expect($response->getContent())->not->toMatch('/[0-9a-f]{64}/');
});

it('shows null fingerprints once the vote hashes were purged', function () {
    // Given a ring whose hashes the retention purge already nulled
    $ring = antiCheatRing(antiCheatLiveProduct(), 3, userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatDetect();
    DB::table('votes')->update(['ip_hash' => null, 'device_hash' => null]);
    $signal = RiskSignal::query()->where('user_id', $ring[0]->id)->sole();

    // When the detail is read
    $votes = antiCheatGet('risk-signals/'.$signal->id)->assertOk()->json('data.evidence.votes');

    // Then the evidence stays, the fingerprints are null
    expect($votes)->toHaveCount(3);
    foreach ($votes as $vote) {
        expect($vote['device_fp'])->toBeNull()->and($vote['ip_fp'])->toBeNull();
    }
});

it('caps each evidence list at 50 and drops anything that is not an id', function () {
    // Given a signal whose details name 60 members, a bad entry and a nested array
    $members = User::factory()->count(60)->create();
    $ids = $members->pluck('id')->all();
    $signal = RiskSignal::factory()->create(['details' => ['rule' => 'shared_device', 'threshold' => 3, 'observed' => 60, 'user_ids' => [...$ids, 'x; drop table users', ['nested'], 7], 'vote_ids' => 'not-a-list']]);

    // When the detail is read
    $evidence = antiCheatGet('risk-signals/'.$signal->id)->assertOk()->json('data.evidence');

    // Then 50 members are resolved (the signal's subject first), no vote
    expect($evidence['members'])->toHaveCount(50)->and($evidence['votes'])->toBe([]);
});

it('shows a deleted member as the anonymised row they became', function () {
    // Given a signal naming a deleted member
    $deleted = User::factory()->create(['handle' => 'deleted-zzz', 'name' => 'Deleted member', 'deleted_at' => antiCheatNow(), 'email' => 'x@deleted.invalid']);
    $signal = RiskSignal::factory()->create(['user_id' => $deleted->id, 'details' => ['rule' => 'shared_device', 'threshold' => 3, 'observed' => 3, 'user_ids' => [$deleted->id]]]);

    // When the detail is read
    $response = antiCheatGet('risk-signals/'.$signal->id)->assertOk();

    // Then the member row is anonymised and flagged deleted, with no e-mail
    $member = $response->json('data.evidence.members.0');
    expect($member['handle'])->toBe('deleted-zzz')->and($member['name'])->toBe('Deleted member')->and($member['deleted'])->toBeTrue();
    expect($response->getContent())->not->toContain('x@deleted');
});

it('resolves a single vote id and shows a burst signal of a product without members', function () {
    // Given a fresh-account style signal naming one vote, and a product burst naming voters
    $vote = antiCheatVote(null, antiCheatLiveProduct());
    $single = RiskSignal::factory()->kind(RiskSignalKind::FreshAccount, 15)->create(['user_id' => $vote->user_id, 'details' => ['rule' => 'fresh_account', 'threshold' => 30, 'observed' => 5, 'vote_id' => $vote->id, 'account_age_minutes' => 5]]);

    // When the detail is read
    $evidence = antiCheatGet('risk-signals/'.$single->id)->assertOk()->json('data.evidence');

    // Then the vote and the member are resolved
    expect($evidence['votes'])->toHaveCount(1)->and($evidence['votes'][0]['id'])->toBe($vote->id)->and($evidence['members'][0]['id'])->toBe($vote->user_id);
});
