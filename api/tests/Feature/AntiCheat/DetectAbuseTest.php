<?php

use App\Actions\AntiCheat\DetectAbuse;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('writes the expected signals per kind with frozen weights, the run instant and no hashes', function () {
    // Given a ring of four on one device, a script, a fresh account and a disposable address
    antiCheatFreeze();
    $case = antiCheatCases();

    // When the job runs
    $result = antiCheatDetect();

    // Then each client case produced its signals, nothing failed, and the touched members are the flagged ones
    expect($result->signals)->toEqual(['disposable_email' => 1, 'fresh_account' => 1, 'shared_device' => 4, 'vote_burst' => 1]);
    expect($result->failedChunks)->toBe(0);
    expect($result->touchedUserIds())->toBe(collect([...array_map(fn ($u) => $u->id, $case['ring']), $case['burster']->id, $case['fresh']->id, $case['throwaway']->id])->sort()->values()->all());
    $byKind = RiskSignal::query()->get()->groupBy(fn (RiskSignal $s) => $s->kind->value);
    expect($byKind['shared_device']->pluck('weight')->unique()->all())->toBe([40])->and($byKind['vote_burst'][0]->weight)->toBe(25)->and($byKind['fresh_account'][0]->weight)->toBe(15)->and($byKind['disposable_email'][0]->weight)->toBe(25);
    expect(RiskSignal::query()->get()->every(fn (RiskSignal $s) => $s->detected_at->toDateTimeString() === '2026-10-21 12:00:00' && $s->reviewed_at === null))->toBeTrue();
    expect(json_encode(DB::table('risk_signals')->pluck('details')->all()))->not->toMatch('/[0-9a-f]{64}/')->not->toContain('mailinator.com@')->not->toContain('device_hash')->not->toContain('ip_hash');
});

it('scans yesterday and today but ignores a launch two days ago, and scans a soft-deleted product with votes', function () {
    // Given three rings: on a product launched two days ago, yesterday and today, plus a soft-deleted product and a draft without votes
    antiCheatFreeze();
    $old = ['created_at' => antiCheatNow()->subDays(10)];
    $twoDays = antiCheatLiveProduct(antiCheatNow()->subDays(2)->toDateString());
    $yesterday = antiCheatLiveProduct(antiCheatNow()->subDay()->toDateString());
    $today = antiCheatLiveProduct();
    $deleted = antiCheatLiveProduct();
    $draft = Product::factory()->create();
    foreach ([$twoDays, $yesterday, $today, $deleted] as $i => $product) {
        antiCheatRing($product, 3, 'ring'.$i, userAttributes: $old);
    }
    $deleted->delete();

    // When the job runs
    $result = antiCheatDetect();

    // Then every scanned product has its three shared_device rows, the old one has none, and the draft never appears
    expect($result->signals)->toBe(['shared_device' => 9]);
    $scanned = RiskSignal::query()->pluck('product_id')->unique()->sort()->values()->all();
    expect($scanned)->toBe(collect([$yesterday->id, $today->id, $deleted->id])->sort()->values()->all());
    expect($scanned)->not->toContain($twoDays->id)->not->toContain($draft->id);
});

it('gives the same signals with chunks of one as with the default chunks', function () {
    // Given the full fixture
    antiCheatFreeze();
    antiCheatCases();

    // When the job runs with one product and one voter per chunk, the rows are cleared, and it runs with the defaults
    $small = antiCheatDetect(['productChunk' => 1, 'userChunk' => 1]);
    $smallKeys = antiCheatKeys();
    RiskSignal::query()->delete();
    $default = antiCheatDetect();

    // Then both runs found exactly the same signals
    expect($smallKeys)->not->toBe([])->and(antiCheatKeys())->toBe($smallKeys)->and($small->signals)->toBe($default->signals);
});

it('does nothing and reads no further when no product has a vote', function () {
    // Given a live product without votes
    antiCheatFreeze();
    antiCheatLiveProduct();

    // When the job runs
    $result = antiCheatDetect();

    // Then nothing was written and nothing failed
    expect($result->signals)->toBe([])->and($result->failedChunks)->toBe(0)->and(RiskSignal::query()->count())->toBe(0);
});

it('reads the clock once and truncates it to the second when none is given', function () {
    // Given a fixed clock with a fraction of a second
    test()->travelTo(antiCheatNow()->addMicroseconds(123456));
    $case = antiCheatCases();

    // When the job runs without an explicit instant
    app(DetectAbuse::class)->handle();

    // Then every row carries the same whole-second instant
    expect(RiskSignal::query()->get()->map(fn (RiskSignal $s) => $s->detected_at->toDateTimeString())->unique()->all())->toBe(['2026-10-21 12:00:00']);
    expect(RiskSignal::query()->count())->toBe(7)->and(RiskSignal::query()->where('product_id', $case['product']->id)->count())->toBe(4);
});

it('runs the coordinated rule last, so it sees the login churn signals of the same run', function () {
    // Given three accounts that logged in from one device (the only signal they carry) and voted for the same four products from different devices
    antiCheatFreeze();
    antiCheatConfig(['anticheat.login_churn.min_accounts' => 3, 'anticheat.coordinated.max_popularity_pct' => 100]);
    $users = User::factory()->count(3)->create(['created_at' => antiCheatNow()->subDays(10)]);
    foreach ($users as $i => $user) {
        antiCheatAuthEvent($user, antiCheatNow()->subHour(), 'shared-login');
    }
    for ($p = 0; $p < 4; $p++) {
        $product = antiCheatLiveProduct();
        foreach ($users as $user) {
            antiCheatVote($user, $product, ['device_hash' => antiCheatHash('own-'.$user->id), 'ip_hash' => antiCheatHash('ip-'.$user->id)]);
        }
    }

    // When the job runs
    $result = antiCheatDetect();

    // Then login churn was written first and the coordinated rule linked the three in the same run
    expect($result->signals)->toEqual(['login_churn' => 3, 'coordinated_votes' => 3]);
});
