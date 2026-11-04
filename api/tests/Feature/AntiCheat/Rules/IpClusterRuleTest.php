<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\IpClusterRule;
use App\Enums\RiskSignalKind;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;

function antiCheatR2(Product $product, ?RuleConfig $config = null): array
{
    return antiCheatFindings(new IpClusterRule, antiCheatProductScan($product), $config);
}

/** Accounts from one IP, all created at the given instant. */
function antiCheatIpRing(Product $product, int $size, CarbonInterface $createdAt): array
{
    return antiCheatRing($product, $size, ip: 'cluster', userAttributes: ['created_at' => $createdAt]);
}

it('fires at five accounts with the full weight when an account is young, and not at four', function () {
    // Given five fresh accounts on one IP, and on another product four
    antiCheatFreeze();
    $five = antiCheatLiveProduct();
    $four = antiCheatLiveProduct();
    antiCheatRing($five, 5, ip: 'cluster');
    antiCheatRing($four, 4, ip: 'cluster');

    // When the rule runs
    $fired = antiCheatR2($five);

    // Then five fire at 15 (no discount for new accounts), four do not
    expect($fired)->toHaveCount(5)->and($fired[0]->kind)->toBe(RiskSignalKind::IpCluster)->and($fired[0]->weight)->toBe(15)->and($fired[0]->details['observed'])->toBe(5)->and($fired[0]->details['threshold'])->toBe(5);
    expect($fired[0]->dedupeKey)->toBe('ip_cluster:'.$fired[0]->userId.':'.$five->id);
    expect(antiCheatR2($four))->toBe([]);
});

it('halves the weight, rounded, when every account is older than the established days', function () {
    // Given five accounts created 31 days ago
    $now = antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatIpRing($product, 5, $now->subDays(31));

    // When the rule runs
    // Then 15 x 0.5 rounds half away from zero to 8
    expect(collect(antiCheatR2($product))->pluck('weight')->unique()->values()->all())->toBe([8]);
});

it('gives no discount when one account is young', function () {
    // Given four old accounts and one young one on the same IP
    $now = antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatIpRing($product, 4, $now->subDays(90));
    antiCheatVote(User::factory()->create(['created_at' => $now->subDays(2)]), $product, ['ip_hash' => antiCheatHash('ip-cluster')]);

    // When the rule runs
    // Then the full weight stands
    expect(collect(antiCheatR2($product))->pluck('weight')->unique()->values()->all())->toBe([15]);
});

it('treats exactly the established age as not older, and one second more as older', function () {
    // Given a ring created exactly 30 days ago and another 30 days and a second ago
    $now = antiCheatFreeze();
    $exact = antiCheatLiveProduct();
    $older = antiCheatLiveProduct();
    antiCheatIpRing($exact, 5, $now->subDays(30));
    antiCheatIpRing($older, 5, $now->subDays(30)->subSecond());

    // When the rule runs
    // Then only the older ring is discounted
    expect(antiCheatR2($exact)[0]->weight)->toBe(15);
    expect(antiCheatR2($older)[0]->weight)->toBe(8);
});

it('honours the discount setting: 1 keeps the weight and 0 gives a weight of 0 that is still a finding', function () {
    // Given an established ring
    $now = antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatIpRing($product, 5, $now->subDays(60));

    // When the discount is 1 and then 0
    $kept = antiCheatR2($product, antiCheatConfig(['anticheat.ip_cluster.discount' => '1']));
    $zero = antiCheatR2($product, antiCheatConfig(['anticheat.ip_cluster.discount' => '0']));

    // Then the weight stays 15, or goes to 0 and the findings still exist
    expect($kept[0]->weight)->toBe(15)->and($zero)->toHaveCount(5)->and($zero[0]->weight)->toBe(0);
});

it('measures the window to the second and ignores withdrawn, voided and other products', function () {
    // Given a fourth-plus-one account exactly at the window start on one product and a second older on another
    antiCheatFreeze();
    $edge = antiCheatLiveProduct();
    $outside = antiCheatLiveProduct();
    antiCheatRing($edge, 4, ip: 'cluster');
    antiCheatRing($outside, 4, ip: 'cluster');
    antiCheatVote(null, $edge, ['ip_hash' => antiCheatHash('ip-cluster'), 'voted_at' => antiCheatNow()->subHours(24)]);
    antiCheatVote(null, $outside, ['ip_hash' => antiCheatHash('ip-cluster'), 'voted_at' => antiCheatNow()->subHours(24)->subSecond()]);
    $withdrawn = antiCheatLiveProduct();
    antiCheatRing($withdrawn, 4, ip: 'cluster');
    antiCheatVote(null, $withdrawn, ['ip_hash' => antiCheatHash('ip-cluster'), 'removed_at' => antiCheatNow()]);

    // When the rule runs
    // Then only the edge product reaches five
    expect(antiCheatR2($edge))->toHaveCount(5);
    expect(antiCheatR2($outside))->toBe([]);
    expect(antiCheatR2($withdrawn))->toBe([]);
});

it('is switched off by a weight of 0 and never groups a null address', function () {
    // Given five votes without an IP and a ring of five with one
    antiCheatFreeze();
    $none = antiCheatLiveProduct();
    for ($i = 0; $i < 5; $i++) {
        antiCheatVote(null, $none, ['ip_hash' => null]);
    }
    $ring = antiCheatLiveProduct();
    antiCheatRing($ring, 5, ip: 'cluster');

    // When the rule runs
    // Then nothing fires for nulls, and a weight of 0 switches the ring off
    expect(antiCheatR2($none))->toBe([]);
    expect(antiCheatR2($ring))->toHaveCount(5);
    expect(antiCheatR2($ring, antiCheatConfig(['anticheat.weight.ip_cluster' => 0])))->toBe([]);
});

it('carries no address in the evidence', function () {
    // Given a ring
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 5, ip: 'cluster');

    // When the rule runs
    $json = json_encode(array_map(fn ($f) => $f->details, antiCheatR2($product)));

    // Then no hash-shaped string or hash key appears
    expect($json)->not->toMatch('/[0-9a-f]{64}/')->not->toContain('ip_hash');
});
