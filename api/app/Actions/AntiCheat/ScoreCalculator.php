<?php

namespace App\Actions\AntiCheat;

use App\Enums\RiskOutcome;
use Carbon\CarbonImmutable;

/**
 * The member risk score, a pure function over signal rows (no database, no clock of its own).
 *
 * A dismissed signal counts 0, a confirmed one its full weight for ever, an open one its weight faded linearly to 0 over
 * `decayDays`. Per kind only the strongest contribution counts (ten hits of one weak kind never add up to a ban), the
 * kinds are summed and the sum is capped at 100. Everything is an integer: the one rounding happens per signal.
 */
final class ScoreCalculator
{
    public function contribution(ScoredSignal $signal, CarbonImmutable $now, int $decayDays): int
    {
        if ($signal->outcome === RiskOutcome::Dismissed) {
            return 0;
        }

        if ($signal->outcome === RiskOutcome::Confirmed) {
            return $signal->weight;
        }

        // A signal "from the future" (clock skew between servers) is as fresh as it gets.
        $ageDays = max(0, $now->getTimestamp() - $signal->detectedAt->getTimestamp()) / 86400;

        return (int) round($signal->weight * max(0.0, 1 - $ageDays / max(1, $decayDays)));
    }

    /**
     * @param  iterable<ScoredSignal>  $signals
     */
    public function score(iterable $signals, CarbonImmutable $now, int $decayDays): int
    {
        $sum = 0;

        foreach ($this->breakdown($signals, $now, $decayDays) as $row) {
            $sum += $row['contribution'];
        }

        return min(100, $sum);
    }

    /**
     * @param  iterable<ScoredSignal>  $signals
     * @return array<string, array{contribution: int, open: int, confirmed: int, dismissed: int}> kind value => numbers
     */
    public function breakdown(iterable $signals, CarbonImmutable $now, int $decayDays): array
    {
        $rows = [];

        foreach ($signals as $signal) {
            $kind = $signal->kind->value;
            $rows[$kind] ??= ['contribution' => 0, 'open' => 0, 'confirmed' => 0, 'dismissed' => 0];
            $rows[$kind]['contribution'] = max($rows[$kind]['contribution'], $this->contribution($signal, $now, $decayDays));
            $rows[$kind][$signal->outcome === null ? 'open' : $signal->outcome->value]++;
        }

        ksort($rows);

        return $rows;
    }
}
