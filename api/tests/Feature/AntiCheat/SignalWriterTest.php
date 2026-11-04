<?php

use App\Actions\AntiCheat\SignalWriter;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('inserts the findings and returns the per-kind counts, with the run instant and the frozen weight', function () {
    // Given findings of two kinds for existing subjects
    $now = antiCheatFreeze();
    $users = User::factory()->count(2)->create();
    $product = antiCheatLiveProduct();
    $findings = [
        antiCheatFinding('k:1', $users[0]->id, $product->id),
        antiCheatFinding('k:2', $users[1]->id, $product->id),
        antiCheatFinding('k:3', $users[0]->id, null, ['kind' => RiskSignalKind::FreshAccount, 'weight' => 15]),
    ];

    // When they are written
    $counts = app(SignalWriter::class)->write($findings, $now);

    // Then the counts per kind are returned and the rows carry the instant and the weight
    expect($counts)->toBe(['shared_device' => 2, 'fresh_account' => 1]);
    $row = RiskSignal::query()->where('dedupe_key', 'k:3')->firstOrFail();
    expect($row->weight)->toBe(15)->and($row->detected_at->toDateTimeString())->toBe('2026-10-21 12:00:00')->and($row->reviewed_at)->toBeNull();
});

it('inserts nothing the second time and leaves the first row untouched', function () {
    // Given a written finding
    $now = antiCheatFreeze();
    $user = User::factory()->create();
    $product = antiCheatLiveProduct();
    app(SignalWriter::class)->write([antiCheatFinding('k:dup', $user->id, $product->id, ['weight' => 40, 'details' => ['rule' => 'shared_device', 'threshold' => 3, 'observed' => 3]])], $now);

    // When the same key arrives with another weight and other details, later
    $counts = app(SignalWriter::class)->write([antiCheatFinding('k:dup', $user->id, $product->id, ['weight' => 5, 'details' => ['rule' => 'shared_device', 'threshold' => 9, 'observed' => 9]])], $now->addMinutes(5));

    // Then no row was inserted and the first evidence, weight and instant survive
    $row = RiskSignal::query()->where('dedupe_key', 'k:dup')->firstOrFail();
    expect($counts)->toBe([])->and(RiskSignal::query()->count())->toBe(1);
    expect($row->weight)->toBe(40)->and($row->details['observed'])->toBe(3)->and($row->detected_at->toDateTimeString())->toBe('2026-10-21 12:00:00');
});

it('never resurrects or changes a reviewed signal', function () {
    // Given a dismissed signal
    $now = antiCheatFreeze();
    $signal = RiskSignal::factory()->reviewed(RiskOutcome::Dismissed)->create(['dedupe_key' => 'k:reviewed']);

    // When the rule finds the same thing again
    $counts = app(SignalWriter::class)->write([antiCheatFinding('k:reviewed', $signal->user_id, $signal->product_id)], $now);

    // Then it stays dismissed and nothing is inserted
    expect($counts)->toBe([]);
    $fresh = RiskSignal::query()->findOrFail($signal->id);
    expect($fresh->outcome)->toBe(RiskOutcome::Dismissed)->and($fresh->reviewed_at)->not->toBeNull()->and(RiskSignal::query()->count())->toBe(1);
});

it('runs no query for an empty list', function () {
    // Given no findings
    // When the writer runs
    $queries = antiCheatQueries(fn () => expect(app(SignalWriter::class)->write([], antiCheatNow()))->toBe([]));

    // Then the database was not touched
    expect($queries)->toBe([]);
});

it('writes a product-only finding with no member', function () {
    // Given a finding about a product alone
    $product = antiCheatLiveProduct();
    $finding = antiCheatFinding('k:product', null, $product->id, ['kind' => RiskSignalKind::VoteBurst, 'weight' => 0]);

    // When it is written
    $counts = app(SignalWriter::class)->write([$finding], antiCheatNow());

    // Then the row has no user and no weight
    $row = RiskSignal::query()->where('dedupe_key', 'k:product')->firstOrFail();
    expect($counts)->toBe(['vote_burst' => 1])->and($row->user_id)->toBeNull()->and($row->weight)->toBe(0);
});

it('locks the products then the users for key share, in id order, before it inserts', function () {
    // Given findings whose ids are deliberately handed over in descending order
    $users = User::factory()->count(3)->create()->sortByDesc('id')->values();
    $products = collect([antiCheatLiveProduct(), antiCheatLiveProduct(), antiCheatLiveProduct()])->sortByDesc('id')->values();
    $findings = [];
    foreach ([0, 1, 2] as $i) {
        $findings[] = antiCheatFinding("k:order:{$i}", $users[$i]->id, $products[$i]->id);
    }

    // When the chunk is written
    $queries = antiCheatQueries(fn () => app(SignalWriter::class)->write($findings, antiCheatNow()));

    // Then the first row locks are products and then users, both ordered by id, and the insert comes after them
    $locks = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'for key share') || str_contains($sql, 'insert into "risk_signals"') || str_contains($sql, 'insert into risk_signals')));
    expect($locks)->toHaveCount(3);
    expect($locks[0])->toContain('from products')->toContain('order by id for key share');
    expect($locks[1])->toContain('from users')->toContain('order by id for key share');
    expect($locks[2])->toContain('insert into risk_signals');
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'for update')))->toBeFalse();
});

it('rolls the whole chunk back on a failing insert and leaves the connection usable', function () {
    // Given a good finding and one whose key is over-long
    $user = User::factory()->create();
    $product = antiCheatLiveProduct();
    $findings = [antiCheatFinding('k:good', $user->id, $product->id), antiCheatFinding(str_repeat('x', 161), $user->id, $product->id)];

    // When the chunk is written
    // Then it throws, nothing of it is stored, and the next query on the same connection works (a savepoint, not a poisoned transaction)
    expect(fn () => app(SignalWriter::class)->write($findings, antiCheatNow()))->toThrow(QueryException::class);
    expect(RiskSignal::query()->count())->toBe(0);
    expect(DB::selectOne('select 1 as one')->one)->toBe(1);
    expect(app(SignalWriter::class)->write([antiCheatFinding('k:after', $user->id, $product->id)], antiCheatNow()))->toBe(['shared_device' => 1]);
});

it('locks and references a soft-deleted product', function () {
    // Given a soft-deleted product
    $user = User::factory()->create();
    $product = antiCheatLiveProduct();
    $product->delete();

    // When a finding about it is written
    $queries = antiCheatQueries(fn () => expect(app(SignalWriter::class)->write([antiCheatFinding('k:deleted', $user->id, $product->id)], antiCheatNow()))->toBe(['shared_device' => 1]));

    // Then the lock query has no deleted_at filter and the row exists
    expect(collect($queries)->first(fn (string $sql) => str_contains($sql, 'from products')))->not->toContain('deleted_at');
    expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue()->and(RiskSignal::query()->count())->toBe(1);
});

it('stores the details as JSON without any hash, address, e-mail or agent key', function () {
    // Given a finding with ids and counts
    $user = User::factory()->create();
    $product = antiCheatLiveProduct();

    // When it is written and read back
    app(SignalWriter::class)->write([antiCheatFinding('k:json', $user->id, $product->id, ['details' => ['rule' => 'shared_device', 'threshold' => 3, 'observed' => 4, 'user_ids' => [$user->id]]])], antiCheatNow());
    $details = RiskSignal::query()->where('dedupe_key', 'k:json')->firstOrFail()->details;

    // Then it round-trips as an array and holds no privacy-bearing key
    // (jsonb reorders object keys, so compare without order)
    expect($details)->toEqual(['rule' => 'shared_device', 'threshold' => 3, 'observed' => 4, 'user_ids' => [$user->id]]);
    expect(array_keys($details))->not->toContain('ip_hash', 'device_hash', 'email', 'user_agent');
});

it('handles more rows than one batch', function () {
    // Given 450 findings (three batches of 200)
    $user = User::factory()->create();
    $findings = array_map(fn (int $i) => antiCheatFinding("k:many:{$i}", $user->id, null), range(1, 450));

    // When they are written
    $counts = app(SignalWriter::class)->write($findings, antiCheatNow());

    // Then all are inserted
    expect($counts)->toBe(['shared_device' => 450])->and(RiskSignal::query()->count())->toBe(450);
});
