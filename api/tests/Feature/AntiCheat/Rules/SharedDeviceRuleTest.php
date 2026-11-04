<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\SharedDeviceRule;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\SignalWriter;
use App\Enums\RiskSignalKind;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;

function antiCheatR1(Product $product, ?RuleConfig $config = null): array
{
    return antiCheatFindings(new SharedDeviceRule, antiCheatProductScan($product), $config);
}

it('fires one finding per account when three accounts share a device on one product', function () {
    // Given three accounts that voted on one product from one device
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $ring = antiCheatRing($product, 3);

    // When the rule runs
    $findings = antiCheatR1($product);

    // Then there are three findings, one per account, with the same group evidence and the frozen weight
    expect($findings)->toHaveCount(3);
    $ids = collect($ring)->pluck('id')->sort()->values()->all();
    foreach ($findings as $finding) {
        expect($finding->kind)->toBe(RiskSignalKind::SharedDevice)->and($finding->weight)->toBe(40)->and($finding->productId)->toBe($product->id);
        expect($finding->details['user_ids'])->toBe($ids)->and($finding->details['observed'])->toBe(3)->and($finding->details['threshold'])->toBe(3)->and($finding->details['rule'])->toBe('shared_device');
        expect($finding->details['vote_ids'])->toHaveCount(3)->and($finding->dedupeKey)->toBe('shared_device:'.$finding->userId.':'.$product->id);
    }
    expect(collect($findings)->pluck('userId')->sort()->values()->all())->toBe($ids);
});

it('does not fire for two accounts, and does at two when the setting says so', function () {
    // Given two accounts on one device
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 2);

    // When the rule runs with the default and with a threshold of 2
    // Then only the lower threshold fires
    expect(antiCheatR1($product))->toBe([]);
    expect(antiCheatR1($product, antiCheatConfig(['anticheat.shared_device.min_accounts' => 2])))->toHaveCount(2);
});

it('needs the same product: one device on different products does not fire', function () {
    // Given one device voting for three different products with three accounts
    antiCheatFreeze();
    $products = [antiCheatLiveProduct(), antiCheatLiveProduct(), antiCheatLiveProduct()];
    foreach ($products as $product) {
        antiCheatVote(null, $product, ['device_hash' => antiCheatHash('lab')]);
    }

    // When the rule runs over all three
    $findings = antiCheatFindings(new SharedDeviceRule, antiCheatProductScan(...$products));

    // Then nothing fires
    expect($findings)->toBe([]);
});

it('counts one account once however often it voted', function () {
    // Given one account whose vote was withdrawn and re-cast (one row, toggled) and two others on the same device
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $user = User::factory()->create();
    antiCheatVote($user, $product, ['device_hash' => antiCheatHash('device-ring'), 'toggle_count' => 4]);
    antiCheatVote(null, $product, ['device_hash' => antiCheatHash('device-ring')]);

    // When the rule runs
    // Then two distinct accounts are below three
    expect(antiCheatR1($product))->toBe([]);
});

it('ignores withdrawn and voided votes and counts flagged ones', function () {
    // Given a device with two counted votes, plus a third that is withdrawn, voided or flagged
    antiCheatFreeze();
    $device = ['device_hash' => antiCheatHash('device-ring')];
    foreach (['removed' => [['removed_at' => antiCheatNow()], 0], 'voided' => [['status' => 'voided', 'voided_at' => antiCheatNow(), 'voided_reason' => 'x'], 0], 'flagged' => [['status' => 'flagged'], 3]] as $name => [$third, $expected]) {
        $product = antiCheatLiveProduct();
        antiCheatVote(null, $product, $device);
        antiCheatVote(null, $product, $device);
        antiCheatVote(null, $product, [...$device, ...$third]);

        // When the rule runs
        // Then withdrawn and voided do not make a ring of three, a flagged vote does
        expect(antiCheatR1($product))->toHaveCount($expected, $name);
    }
});

it('does not count a deleted member', function () {
    // Given a ring of three where one member is deleted
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $ring = antiCheatRing($product, 3);
    $ring[2]->forceFill(['deleted_at' => now()])->save();

    // When the rule runs
    // Then the two live accounts are below the threshold
    expect(antiCheatR1($product))->toBe([]);
});

it('measures the window to the second', function () {
    // Given three accounts, one vote exactly 24 hours old and then one second older
    antiCheatFreeze();
    $edge = antiCheatLiveProduct();
    $outside = antiCheatLiveProduct();
    antiCheatRing($edge, 2);
    antiCheatRing($outside, 2);
    antiCheatVote(null, $edge, ['device_hash' => antiCheatHash('device-ring'), 'voted_at' => antiCheatNow()->subHours(24)]);
    antiCheatVote(null, $outside, ['device_hash' => antiCheatHash('device-ring'), 'voted_at' => antiCheatNow()->subHours(24)->subSecond()]);

    // When the rule runs
    // Then the vote exactly at the window start counts and the one a second older does not
    expect(antiCheatR1($edge))->toHaveCount(3);
    expect(antiCheatR1($outside))->toBe([]);
});

it('never groups a null device', function () {
    // Given five votes without a device hash (purged or never set)
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    for ($i = 0; $i < 5; $i++) {
        antiCheatVote(null, $product, ['device_hash' => null]);
    }

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR1($product))->toBe([]);
});

it('is switched off by a weight of 0', function () {
    // Given a ring and a weight of 0
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 3);

    // When the rule runs
    // Then it yields nothing
    expect(antiCheatR1($product, antiCheatConfig(['anticheat.weight.shared_device' => 0])))->toBe([]);
});

it('freezes the weight at detection', function () {
    // Given a ring written at weight 40, then the setting is changed and the rule reruns
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 3);
    app(SignalWriter::class)->write(antiCheatR1($product), antiCheatNow());
    $second = antiCheatR1($product, antiCheatConfig(['anticheat.weight.shared_device' => 90]));
    $counts = app(SignalWriter::class)->write($second, antiCheatNow());

    // Then the second run found weight 90 but inserted nothing, so the stored rows keep 40
    expect($second[0]->weight)->toBe(90)->and($counts)->toBe([])->and(RiskSignal::query()->pluck('weight')->unique()->values()->all())->toBe([40]);
});

it('carries no hash in the evidence', function () {
    // Given a ring
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    $ring = antiCheatRing($product, 3);

    // When the rule runs
    $json = json_encode(array_map(fn ($f) => $f->details, antiCheatR1($product)));

    // Then no 64-hex string, no hash key, and no address appears
    expect($json)->not->toMatch('/[0-9a-f]{64}/')->not->toContain('device_hash')->not->toContain('ip_hash')->not->toContain($ring[0]->email);
});

it('caps the id lists at 50 and says so', function () {
    // Given a group of 51 accounts
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 51);

    // When the rule runs
    $findings = antiCheatR1($product);

    // Then there is a finding per account, each list is cut at 50 and flagged truncated, and observed is the real size
    expect($findings)->toHaveCount(51);
    expect($findings[0]->details['user_ids'])->toHaveCount(50)->and($findings[0]->details['vote_ids'])->toHaveCount(50)->and($findings[0]->details['truncated'])->toBeTrue()->and($findings[0]->details['observed'])->toBe(51);
});

it('keeps the group on one product independent of the group on another', function () {
    // Given a ring of three on product A and a ring of two on product B using the same device
    antiCheatFreeze();
    $a = antiCheatLiveProduct();
    $b = antiCheatLiveProduct();
    antiCheatRing($a, 3);
    antiCheatRing($b, 2);

    // When the rule runs over both
    $findings = antiCheatFindings(new SharedDeviceRule, antiCheatProductScan($a, $b));

    // Then only A fires
    expect($findings)->toHaveCount(3)->and(collect($findings)->pluck('productId')->unique()->all())->toBe([$a->id]);
});

it('reads nothing for an empty product chunk', function () {
    // Given a scan with no products
    $config = antiCheatConfig();

    // When the rule runs
    $queries = antiCheatQueries(fn () => expect(antiCheatFindings(new SharedDeviceRule, Scan::products(antiCheatNow(), []), $config))->toBe([]));

    // Then it ran no query
    expect($queries)->toBe([]);
});
