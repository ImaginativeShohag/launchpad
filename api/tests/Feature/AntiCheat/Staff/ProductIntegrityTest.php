<?php

use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vote;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    antiCheatFreeze();
    signedInStaff(Role::Reviewer);
});

function antiCheatIntegrityOf(Product $product): array
{
    return antiCheatGet("products/{$product->id}/vote-integrity")->assertOk()->json('data');
}

/** A product with the given number of counted and flagged active votes. */
function antiCheatIntegrityProduct(int $counted, int $flagged): Product
{
    $product = antiCheatLiveProduct();
    foreach (range(1, max($counted, 1)) as $i) {
        if ($i <= $counted) {
            antiCheatVote(null, $product);
        }
    }
    foreach (range(1, max($flagged, 1)) as $i) {
        if ($i <= $flagged) {
            antiCheatVote(null, $product, ['status' => 'flagged']);
        }
    }

    return $product;
}

it('is 1.0 and clean for a product without votes, with 24 zero-filled hours', function () {
    // Given a product with no votes
    $product = antiCheatLiveProduct();

    // When the integrity is read
    $data = antiCheatIntegrityOf($product);

    // Then the ratio is 1.0, the band clean, and the buckets are 24 zeros
    expect($data)->toMatchArray(['counted' => 0, 'flagged' => 0, 'voided' => 0, 'removed' => 0, 'active' => 0, 'integrity_ratio' => 1, 'band' => 'clean', 'open_signals' => 0]);
    expect($data['buckets'])->toHaveCount(24)->and(array_sum(array_column($data['buckets'], 'votes')))->toBe(0)->and($data['groups'])->toBe(['device' => [], 'ip' => []]);
    expect($data['product'])->toBe(['id' => $product->id, 'slug' => $product->slug, 'name' => $product->name]);
});

it('computes counted over active, with voided and removed counted apart', function () {
    // Given 6 counted, 2 flagged, 1 voided, 1 withdrawn counted and 1 withdrawn flagged vote
    $product = antiCheatIntegrityProduct(6, 2);
    Vote::factory()->voided()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'voted_at' => antiCheatNow()]);
    antiCheatVote(null, $product, ['removed_at' => antiCheatNow(), 'toggle_count' => 1]);
    antiCheatVote(null, $product, ['status' => 'flagged', 'removed_at' => antiCheatNow(), 'toggle_count' => 1]);

    // When the integrity is read
    $data = antiCheatIntegrityOf($product);

    // Then the numbers are exclusive and the ratio is 6/8
    expect($data)->toMatchArray(['counted' => 6, 'flagged' => 2, 'voided' => 1, 'removed' => 2, 'active' => 8, 'integrity_ratio' => 0.75, 'band' => 'watch']);
});

it('puts the bands at the exact boundaries and moves them with the settings', function (int $counted, int $flagged, string $band) {
    // Given a product with the votes (watch 0.9, alert 0.7)
    $product = antiCheatIntegrityProduct($counted, $flagged);

    // When the integrity is read
    // Then the ratio decides the band at the boundary itself
    expect(antiCheatIntegrityOf($product)['band'])->toBe($band);
})->with([
    'exactly watch' => [9, 1, 'clean'],
    'just under watch' => [89, 11, 'watch'],
    'exactly alert' => [7, 3, 'watch'],
    'just under alert' => [69, 31, 'alert'],
    'all flagged' => [0, 4, 'alert'],
]);

it('moves the band with the settings and falls back to the defaults for an inverted stored pair', function () {
    // Given 8 of 10 votes counted (ratio 0.8: watch by default)
    $product = antiCheatIntegrityProduct(8, 2);
    expect(antiCheatIntegrityOf($product)['band'])->toBe('watch');

    // When the watch ratio is lowered to 0.8, the band is clean; and with a pair stored inverted (direct write) it is the default again
    $admin = User::factory()->role(Role::Admin)->create();
    app(Settings::class)->set(SettingKey::AnticheatProductWatchRatio, '0.8', $admin);
    expect(antiCheatIntegrityOf($product)['band'])->toBe('clean');
    Setting::query()->updateOrCreate(['key' => 'anticheat.product_alert_ratio'], ['value' => '0.95']);
    Cache::flush();

    // Then the inverted pair is ignored: defaults 0.9 / 0.7 give watch
    expect(antiCheatIntegrityOf($product)['band'])->toBe('watch');
});

it('zero-fills the last 24 hourly buckets and flags the hours with a stored burst signal', function () {
    // Given buckets 2 hours ago and now, and a product burst signal detected 3 hours ago
    $product = antiCheatLiveProduct();
    DB::table('product_hourly_stats')->insert([
        ['product_id' => $product->id, 'hour_start' => antiCheatNow()->subHours(2)->startOfHour()->toDateTimeString().'+00', 'votes' => 7, 'views' => 0],
        ['product_id' => $product->id, 'hour_start' => antiCheatNow()->startOfHour()->toDateTimeString().'+00', 'votes' => 3, 'views' => 0],
        ['product_id' => $product->id, 'hour_start' => antiCheatNow()->subHours(30)->startOfHour()->toDateTimeString().'+00', 'votes' => 99, 'views' => 0],
    ]);
    RiskSignal::factory()->forProductOnly()->kind(RiskSignalKind::VoteBurst, 0)->detectedAt(antiCheatNow()->subHours(3)->addMinutes(10))->create(['product_id' => $product->id]);
    RiskSignal::factory()->forProductOnly()->kind(RiskSignalKind::VoteBurst, 0)->detectedAt(antiCheatNow()->subHours(40))->create(['product_id' => $product->id]);

    // When the integrity is read
    $data = antiCheatIntegrityOf($product);

    // Then 24 consecutive hours ending now, the right votes, and the burst flag only on its hour
    $buckets = collect($data['buckets']);
    expect($buckets)->toHaveCount(24)->and($buckets->last()['hour'])->toBe('2026-10-21T12:00:00+00:00')->and($buckets->first()['hour'])->toBe('2026-10-20T13:00:00+00:00');
    expect($buckets->where('votes', '>', 0)->pluck('votes')->all())->toBe([7, 3]);
    expect($buckets->where('burst', true)->pluck('hour')->all())->toBe(['2026-10-21T09:00:00+00:00']);
    expect($data['open_signals'])->toBe(2);
});

it('groups devices and IPs shared by two accounts or more: top 10, deterministic, 8-character fingerprints', function () {
    // Given 12 device groups of 2 accounts, one of 3, one of 2 accounts with more votes is impossible (one vote per account), and a lone account
    $product = antiCheatLiveProduct();
    foreach (range(1, 12) as $i) {
        antiCheatRing($product, 2, 'pair'.$i);
    }
    antiCheatRing($product, 3, 'triple');
    antiCheatRing($product, 1, 'lone');

    // When the integrity is read
    $groups = antiCheatIntegrityOf($product)['groups']['device'];

    // Then the triple comes first, ten groups at most, lone devices are absent, fingerprints are 8 hex characters
    expect($groups)->toHaveCount(10)->and($groups[0])->toBe(['fingerprint' => substr(antiCheatHash('device-triple'), 0, 8), 'accounts' => 3, 'votes' => 3]);
    foreach ($groups as $group) {
        expect($group['fingerprint'])->toMatch('/\A[0-9a-f]{8}\z/')->and($group['accounts'])->toBeGreaterThanOrEqual(2);
    }
    expect($groups)->toBe(antiCheatIntegrityOf($product)['groups']['device']);
    $pairs = array_slice($groups, 1);
    $sortedByHash = $pairs;
    usort($sortedByHash, fn (array $a, array $b) => strcmp($a['fingerprint'], $b['fingerprint']));
    expect($pairs)->toBe($sortedByHash);
});

it('reads a taken-down product with votes and a soft-deleted live one', function () {
    // Given a taken-down product and a soft-deleted live one, both with votes
    $down = Product::factory()->takenDown()->create();
    antiCheatVote(null, $down);
    $deleted = antiCheatLiveProduct();
    antiCheatVote(null, $deleted);
    $deleted->delete();

    // When the integrity is read
    // Then both answer 200 with their votes
    expect(antiCheatIntegrityOf($down)['active'])->toBe(1)->and(antiCheatIntegrityOf($deleted)['active'])->toBe(1);
});

it('filters votes by product id equality only', function () {
    // Given a product with votes
    $product = antiCheatIntegrityProduct(2, 1);

    // When the integrity is read under the recorder
    $queries = antiCheatQueries(fn () => antiCheatIntegrityOf($product));

    // Then every statement that reads the votes table binds a product id (no scan by user, hash or status alone)
    $voteQueries = array_values(array_filter($queries, fn (string $q) => str_contains($q, 'from votes')));
    expect($voteQueries)->not->toBeEmpty();
    foreach ($voteQueries as $sql) {
        expect($sql)->toContain('product_id = ?');
    }
});

it('never shows a group for a malformed stored hash: no empty fingerprint', function () {
    // Given two accounts sharing a malformed device value and an IP value (a direct database edit), next to a real pair
    $product = antiCheatLiveProduct();
    foreach (range(1, 2) as $_) {
        antiCheatVote(null, $product, ['device_hash' => 'not-a-hash', 'ip_hash' => 'XYZ']);
    }
    antiCheatRing($product, 2, 'real');

    // When the integrity is read
    $groups = antiCheatIntegrityOf($product)['groups'];

    // Then only the real device pair appears, with an 8 hex fingerprint, and the malformed values form no group at all
    expect($groups['device'])->toHaveCount(1)->and($groups['device'][0]['fingerprint'])->toBe(substr(antiCheatHash('device-real'), 0, 8))->and($groups['ip'])->toBe([]);
});
