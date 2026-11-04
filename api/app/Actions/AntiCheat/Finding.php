<?php

namespace App\Actions\AntiCheat;

use App\Enums\RiskSignalKind;

/**
 * One thing a rule found. `details` is the evidence: `rule`, `threshold`, `observed` and optional `window_minutes`, plus
 * ids and counts. Never a hash, an address, an e-mail or a user agent.
 */
final readonly class Finding
{
    /** The most ids one list in `details` carries. */
    public const MAX_IDS = 50;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public RiskSignalKind $kind,
        public ?string $userId,
        public ?string $productId,
        public int $weight,
        public array $details,
        public string $dedupeKey,
    ) {}

    /**
     * Caps an id list at 50. Sorted by default so the cap is deterministic; pass `$sort = false` when the caller's own
     * order matters (the first 50 voters of a window).
     *
     * @param  list<string>  $ids
     * @return array{0: list<string>, 1: bool} the kept ids and whether any were dropped
     */
    public static function capped(array $ids, bool $sort = true): array
    {
        $ids = array_values(array_unique($ids));

        if ($sort) {
            sort($ids);
        }

        return [array_slice($ids, 0, self::MAX_IDS), count($ids) > self::MAX_IDS];
    }

    /**
     * The common head of every `details` object.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function details(RiskSignalKind $kind, int|float|string $threshold, int|float $observed, ?int $windowMinutes = null, array $extra = [], bool $truncated = false): array
    {
        $details = ['rule' => $kind->value, 'threshold' => $threshold, 'observed' => $observed];

        if ($windowMinutes !== null) {
            $details['window_minutes'] = $windowMinutes;
        }

        $details = [...$details, ...$extra];

        if ($truncated) {
            $details['truncated'] = true;
        }

        return $details;
    }
}
