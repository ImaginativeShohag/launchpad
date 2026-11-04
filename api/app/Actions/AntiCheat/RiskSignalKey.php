<?php

namespace App\Actions\AntiCheat;

use Carbon\CarbonImmutable;

/**
 * Every dedupe key of `risk_signals`, built in one place (`kind:subject:scope`). The unique index on the key plus
 * `ON CONFLICT DO NOTHING` is what makes a rerun free. Retention note for the purge (phase C): the "once per account, ever"
 * kinds (`fresh_account`, `disposable_email`) must NOT lose their dismissed rows to a purge, or the next run would re-detect the same
 * thing and resurrect a signal staff already dismissed; `PurgeRiskSignals` keeps dismissed rows of those kinds; the longest key (`single_maker_voter:{ulid}:{ulid}`) is 72 characters.
 */
final class RiskSignalKey
{
    public static function sharedDevice(string $userId, string $productId): string
    {
        return "shared_device:{$userId}:{$productId}";
    }

    public static function ipCluster(string $userId, string $productId): string
    {
        return "ip_cluster:{$userId}:{$productId}";
    }

    /** One per member per UTC day. */
    public static function burstUser(string $userId, string $utcDate): string
    {
        return "vote_burst:user:{$userId}:{$utcDate}";
    }

    /** At most one per product per hour (`yyyymmddHH` of the run's instant, UTC). */
    public static function burstProduct(string $productId, CarbonImmutable $now): string
    {
        return "vote_burst:product:{$productId}:".$now->utc()->format('YmdH');
    }

    /** Once per account, ever. */
    public static function freshAccount(string $userId): string
    {
        return "fresh_account:{$userId}";
    }

    /** Once per account, ever. */
    public static function disposableEmail(string $userId): string
    {
        return "disposable_email:{$userId}";
    }

    public static function singleMakerVoter(string $userId, string $makerId): string
    {
        return "single_maker_voter:{$userId}:{$makerId}";
    }

    public static function loginChurn(string $userId, string $utcDate): string
    {
        return "login_churn:{$userId}:{$utcDate}";
    }

    public static function coordinatedVotes(string $userId, string $utcDate): string
    {
        return "coordinated_votes:{$userId}:{$utcDate}";
    }
}
