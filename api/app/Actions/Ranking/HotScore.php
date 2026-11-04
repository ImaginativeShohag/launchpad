<?php

namespace App\Actions\Ranking;

use App\Enums\SettingKey;
use App\Support\Settings;

/**
 * The velocity score of a launch: every hour's net votes decay with their age, and the first two hours of the
 * launch day earn a bonus. Pure (no database, no clock): the caller passes the buckets and the current hour index.
 */
final class HotScore
{
    public function __construct(private readonly float $decay, private readonly float $earlyBonus) {}

    public static function fromSettings(Settings $settings): self
    {
        return new self(
            $settings->decimal(SettingKey::RankingDecayExponent),
            $settings->decimal(SettingKey::RankingEarlyBonus),
        );
    }

    /**
     * @param  array<int, int>  $votesByHour  hour index since 00:00 UTC of the launch day (0..23) => net votes
     * @param  int  $currentHour  hour index of "now" (0..23; for a finished day pass 23)
     */
    public function score(array $votesByHour, int $currentHour): float
    {
        $score = 0.0;

        foreach ($votesByHour as $hour => $votes) {
            // Hours after "now" do not exist yet (and never feed the bonus either).
            if ($hour > $currentHour) {
                continue;
            }

            $score += $votes / (($currentHour - $hour) + 2) ** $this->decay;

            if ($hour === 0 || $hour === 1) {
                $score += $this->earlyBonus * $votes;
            }
        }

        return max(0.0, $score);
    }
}
