<?php

use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\CoordinatedVotesRule;
use App\Actions\AntiCheat\Rules\DisposableEmailRule;
use App\Actions\AntiCheat\Rules\LoginChurnRule;
use App\Actions\AntiCheat\Rules\SharedDeviceRule;
use App\Actions\AntiCheat\Scan;
use App\Enums\RiskSignalKind;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/** Three products, each with its own ring of three (every ring on a distinct device), returned in id order. */
function antiCheatThreeRings(): array
{
    $products = [];
    foreach ([1, 2, 3] as $i) {
        $product = antiCheatLiveProduct();
        antiCheatRing($product, 3, 'ring'.$i, userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
        $products[] = $product;
    }
    usort($products, fn ($a, $b) => strcmp($a->id, $b->id));

    return $products;
}

it('counts a chunk that throws, saves the other chunks, reports the error and keeps the connection usable', function () {
    // Given three rings in one-product chunks and a shared-device rule that divides by zero for the middle product
    antiCheatFreeze();
    $products = antiCheatThreeRings();
    $bad = $products[1]->id;
    app()->bind(SharedDeviceRule::class, fn () => new class($bad) extends SharedDeviceRule
    {
        public function __construct(private readonly string $bad) {}

        public function detect(RuleConfig $config, Scan $scan): iterable
        {
            if (in_array($this->bad, $scan->productIds, true)) {
                DB::select('select 1/0');
            }

            yield from parent::detect($config, $scan);
        }
    });
    Exceptions::fake();

    // When the job runs
    $result = antiCheatDetect(['productChunk' => 1]);

    // Then one chunk failed, the two good rings were saved, the error was reported and the next query works
    expect($result->failedChunks)->toBe(1)->and($result->signals)->toBe(['shared_device' => 6]);
    expect(RiskSignal::query()->pluck('product_id')->unique()->sort()->values()->all())->toBe([$products[0]->id, $products[2]->id]);
    Exceptions::assertReported(QueryException::class);
    expect(DB::selectOne('select 1 as one')->one)->toBe(1);
});

it('counts a chunk whose write throws and does not poison the chunks after it', function () {
    // Given three rings and a rule that adds a finding with an over-long key for the first product only
    antiCheatFreeze();
    $products = antiCheatThreeRings();
    $bad = $products[0]->id;
    app()->bind(SharedDeviceRule::class, fn () => new class($bad) extends SharedDeviceRule
    {
        public function __construct(private readonly string $bad) {}

        public function detect(RuleConfig $config, Scan $scan): iterable
        {
            yield from parent::detect($config, $scan);

            if (in_array($this->bad, $scan->productIds, true)) {
                yield new Finding(RiskSignalKind::SharedDevice, null, $this->bad, 0, ['rule' => 'shared_device', 'threshold' => 1, 'observed' => 1], str_repeat('k', 161));
            }
        }
    });
    Exceptions::fake();

    // When the job runs in one-product chunks
    $result = antiCheatDetect(['productChunk' => 1]);

    // Then the failing chunk wrote nothing (its good findings rolled back with it), the later chunks did write
    expect($result->failedChunks)->toBe(1)->and($result->signals)->toBe(['shared_device' => 6]);
    expect(RiskSignal::query()->where('product_id', $bad)->count())->toBe(0)->and(RiskSignal::query()->count())->toBe(6);
    Exceptions::assertReported(QueryException::class);
});

it('still runs the user rules for the voters of a chunk whose write failed', function () {
    // Given three rings, one ring member on a throwaway address, and a write that fails for that member's product only
    antiCheatFreeze();
    $products = antiCheatThreeRings();
    $bad = $products[0]->id;
    $member = User::query()->whereIn('id', Vote::query()->where('product_id', $bad)->pluck('user_id'))->firstOrFail();
    $member->forceFill(['email' => 'lost@mailinator.com'])->save();
    app()->bind(SharedDeviceRule::class, fn () => new class($bad) extends SharedDeviceRule
    {
        public function __construct(private readonly string $bad) {}

        public function detect(RuleConfig $config, Scan $scan): iterable
        {
            yield from parent::detect($config, $scan);

            if (in_array($this->bad, $scan->productIds, true)) {
                yield new Finding(RiskSignalKind::SharedDevice, null, $this->bad, 0, ['rule' => 'shared_device', 'threshold' => 1, 'observed' => 1], str_repeat('k', 161));
            }
        }
    });
    Exceptions::fake();

    // When the job runs in one-product chunks
    $result = antiCheatDetect(['productChunk' => 1]);

    // Then the failed chunk wrote no shared_device rows, but its voters still reached the user rules (the throwaway address was found)
    expect($result->failedChunks)->toBe(1);
    expect(RiskSignal::query()->where('product_id', $bad)->count())->toBe(0);
    expect(RiskSignal::query()->where('kind', 'disposable_email')->where('user_id', $member->id)->count())->toBe(1);
});

it('counts a failing user chunk and a failing run rule, and still runs the coordinated rule', function () {
    // Given a voter set and rules that throw for the user phase and for login churn
    antiCheatFreeze();
    antiCheatThreeRings();
    app()->bind(DisposableEmailRule::class, fn () => new class extends DisposableEmailRule
    {
        public function detect(RuleConfig $config, Scan $scan): iterable
        {
            throw new RuntimeException('boom');
        }
    });
    app()->bind(LoginChurnRule::class, fn () => new class extends LoginChurnRule
    {
        public function detect(RuleConfig $config, Scan $scan): iterable
        {
            throw new RuntimeException('boom');
        }
    });
    $ran = new ArrayObject;
    app()->bind(CoordinatedVotesRule::class, fn () => new class($ran) extends CoordinatedVotesRule
    {
        public function __construct(private readonly ArrayObject $ran) {}

        public function detect(RuleConfig $config, Scan $scan): iterable
        {
            $this->ran['ran'] = true;

            yield from parent::detect($config, $scan);
        }
    });
    Exceptions::fake();

    // When the job runs with one voter per chunk (nine voters)
    $result = antiCheatDetect(['userChunk' => 1]);

    // Then nine user chunks and one run rule failed, the product signals are saved, and R8 still ran
    expect($result->failedChunks)->toBe(10)->and($result->signals)->toBe(['shared_device' => 9])->and($ran['ran'] ?? false)->toBeTrue();
    Exceptions::assertReportedCount(10);
});

it('reports a clean run as zero failures', function () {
    // Given rings and no failing rule
    antiCheatFreeze();
    antiCheatThreeRings();
    Exceptions::fake();

    // When the job runs
    $result = antiCheatDetect();

    // Then nothing failed and nothing was reported
    expect($result->failedChunks)->toBe(0);
    Exceptions::assertNothingReported();
});
