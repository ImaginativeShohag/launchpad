<?php

use App\Enums\Role;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('leaks no hash, address, e-mail or user agent through any staff anti-cheat response', function () {
    // Given a world with hashes, IPs, e-mails, user agents, a deleted and a suspended member, flagged votes, and the job's signals
    antiCheatFreeze();
    $case = antiCheatCases();
    DB::table('votes')->update(['user_agent' => 'Mozilla/5.0 PrivacyUA 10.0.0.1']);
    $deleted = User::factory()->create(['handle' => 'deleted-privacy', 'name' => 'Deleted member', 'deleted_at' => antiCheatNow(), 'email' => 'deleted-privacy@deleted.invalid']);
    $suspended = antiCheatSetScore(User::factory()->create(['suspended_at' => antiCheatNow()->subDay(), 'suspended_reason' => 'Ring', 'email' => 'suspended-privacy@example.test']), 85);
    antiCheatVote($suspended, $case['product'], ['status' => 'flagged', 'device_hash' => antiCheatHash('device-ring')]);
    RiskSignal::factory()->create(['user_id' => $deleted->id, 'product_id' => $case['product']->id]);
    antiCheatAuthEvent($case['ring'][0], antiCheatNow()->subHour(), 'ring');
    antiCheatDetect();
    foreach ($case['ring'] as $member) {
        antiCheatSetScore($member, 80);
    }
    $signal = RiskSignal::query()->where('kind', 'shared_device')->firstOrFail();
    signedInStaff(Role::Admin);

    // When every staff read is requested (lists, details, evidence, integrity, votes)
    $paths = [
        'risk-signals', 'risk-signals?status=all&per_page=100', 'risk-signals?status=reviewed', "risk-signals/{$signal->id}",
        'risk/members?min_score=1&per_page=100', "risk/members/{$case['ring'][0]->id}", "risk/members/{$deleted->id}", "risk/members/{$suspended->id}",
        "products/{$case['product']->id}/vote-integrity", "products/{$case['product']->id}/votes?per_page=100",
    ];

    // Then none contains a hash, an address, an e-mail or a user agent
    foreach ($paths as $path) {
        $response = $this->getJson('/api/v1/staff/'.$path)->assertOk();
        expect(antiCheatPrivacyLeaks($response->getContent()))->toBe([], $path);
    }
});

it('shows a member and the public nothing of the score, the flags or the integrity', function () {
    // Given a flagged member with a vote on a live product, and a public viewer
    antiCheatFreeze();
    $member = antiCheatSetScore(User::factory()->create(), 90);
    $product = antiCheatLiveProduct();
    antiCheatVote($member, $product, ['status' => 'flagged']);
    DB::table('products')->where('id', $product->id)->update(['votes_count' => 1]);
    RiskSignal::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    $guest = [$this->getJson('/api/v1/today')->getContent(), $this->getJson('/api/v1/products/'.$product->slug)->getContent(), $this->getJson('/api/v1/users/'.$member->handle)->getContent()];
    Sanctum::actingAs($member);

    // When the member reads their own account and the product, the same ways
    $own = [$this->getJson('/api/v1/me')->getContent(), $this->getJson('/api/v1/products/'.$product->slug)->getContent(), $this->getJson('/api/v1/today')->getContent(), $this->getJson('/api/v1/users/'.$member->handle)->getContent()];

    // Then no payload carries a risk, flag or integrity key
    foreach ([...$guest, ...$own] as $body) {
        expect($body)->not->toMatch('/risk|flagged|integrity|signal/i');
    }
});
