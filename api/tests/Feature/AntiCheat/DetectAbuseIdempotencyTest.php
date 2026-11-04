<?php

use App\Actions\AntiCheat\DetectAbuse;
use App\Enums\RiskOutcome;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('inserts nothing on a second run at the same state', function () {
    // Given a full fixture that was detected once
    antiCheatFreeze();
    antiCheatCases();
    $first = antiCheatDetect();
    $rows = DB::table('risk_signals')->orderBy('dedupe_key')->get()->map(fn ($r) => (array) $r)->all();

    // When the job runs again
    $second = antiCheatDetect();

    // Then no row was inserted and the stored rows are byte-identical
    expect($first->signals)->not->toBe([])->and($second->signals)->toBe([]);
    expect(DB::table('risk_signals')->orderBy('dedupe_key')->get()->map(fn ($r) => (array) $r)->all())->toBe($rows);
});

it('adds only the new member when the ring grows, and keeps the first evidence of the old rows', function () {
    // Given a detected ring of four
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 4, userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatDetect();

    // When a fifth account joins the device and the job runs again
    $fifth = User::factory()->create(['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatVote($fifth, $product, ['device_hash' => antiCheatHash('device-ring')]);
    $result = antiCheatDetect();

    // Then one new row exists (for the newcomer, with the grown group), and the four old rows still say 4
    expect($result->signals)->toBe(['shared_device' => 1]);
    $rows = RiskSignal::query()->get()->keyBy('user_id');
    expect($rows)->toHaveCount(5)->and($rows[$fifth->id]->details['observed'])->toBe(5);
    expect($rows->reject(fn (RiskSignal $s) => $s->user_id === $fifth->id)->every(fn (RiskSignal $s) => $s->details['observed'] === 4))->toBeTrue();
});

it('leaves a dismissed signal dismissed and inserts no duplicate', function () {
    // Given a detected ring where staff dismissed one signal
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 3, userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatDetect();
    $signal = RiskSignal::query()->firstOrFail();
    $reviewer = User::factory()->create();
    DB::table('risk_signals')->where('id', $signal->id)->update(['reviewed_by' => $reviewer->id, 'reviewed_at' => antiCheatNow(), 'outcome' => 'dismissed']);

    // When the job runs again
    $result = antiCheatDetect();

    // Then it stays dismissed and nothing new appears
    expect($result->signals)->toBe([])->and(RiskSignal::query()->count())->toBe(3);
    expect(RiskSignal::query()->findOrFail($signal->id)->outcome)->toBe(RiskOutcome::Dismissed);
});

it('does not rewrite an old row when a weight setting changes between runs', function () {
    // Given a detected ring at weight 40
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 3, userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatDetect();

    // When the weight is raised to 90 and the job runs again
    antiCheatConfig(['anticheat.weight.shared_device' => 90]);
    $result = antiCheatDetect();

    // Then the stored rows keep 40
    expect($result->signals)->toBe([])->and(RiskSignal::query()->pluck('weight')->unique()->all())->toBe([40]);
});

it('gives the same signal set from repeated runs with the same state and a later clock inside the hour', function () {
    // Given a detected fixture
    antiCheatFreeze();
    antiCheatCases();
    antiCheatDetect();
    $keys = antiCheatKeys();

    // When the job runs five minutes later
    $later = app(DetectAbuse::class)->handle(antiCheatNow()->addMinutes(5));

    // Then the signal set is unchanged
    expect($later->signals)->toBe([])->and(antiCheatKeys())->toBe($keys);
});
