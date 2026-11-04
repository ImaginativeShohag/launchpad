<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\RiskSignalKey;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\Sql;
use App\Enums\RiskSignalKind;
use App\Enums\ScanScope;

/**
 * R3b (the member form of `vote_burst`): one member casts at least N votes within any M-minute stretch of the last M+5 minutes. The vote limiter (30 a minute)
 * lets a script through; a person rarely does this. `(user_id, product_id)` is unique, so the count is distinct products and
 * a re-vote (same row, new `voted_at`) counts once. One signal per member per UTC day.
 */
class UserBurstRule implements Rule
{
    /** The scheduler's interval; the scan reaches back this far beyond the window. */
    private const RUN_INTERVAL_MINUTES = 5;

    public function scope(): ScanScope
    {
        return ScanScope::Users;
    }

    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightVoteBurst <= 0 || $scan->userIds === []) {
            return;
        }

        // A burst can straddle two five-minute runs, so look back a window plus the run interval and slide the window over
        // those votes: the densest M-minute stretch decides. A repeat of the same burst in the next run hits the dedupe key.
        $scanFrom = $scan->now->subMinutes($config->userBurstMinutes + self::RUN_INTERVAL_MINUTES);
        $date = $scan->now->utc()->toDateString();

        $rows = Sql::select(
            'SELECT DISTINCT ON (t.user_id) t.user_id, t.c, t.vote_ids FROM ('
            .'SELECT v.user_id, COUNT(*) OVER w AS c, json_agg(v.id) OVER w AS vote_ids FROM votes v JOIN users u ON u.id = v.user_id'
            .' WHERE v.user_id IN ('.Sql::placeholders($scan->userIds).') AND '.Sql::activeVote('v')
            .' AND v.voted_at >= ?::timestamptz AND u.deleted_at IS NULL'
            ." WINDOW w AS (PARTITION BY v.user_id ORDER BY v.voted_at RANGE BETWEEN (?::int * interval '1 minute') PRECEDING AND CURRENT ROW)"
            .') t WHERE t.c >= ? ORDER BY t.user_id, t.c DESC',
            [...$scan->userIds, Sql::ts($scanFrom), $config->userBurstMinutes, $config->userBurstVotes],
        );

        foreach ($rows as $row) {
            /** @var list<string> $voteIds */
            $voteIds = json_decode($row->vote_ids, true, flags: JSON_THROW_ON_ERROR);
            [$ids, $capped] = Finding::capped($voteIds, sort: false);

            yield new Finding(
                RiskSignalKind::VoteBurst, $row->user_id, null, $config->weightVoteBurst,
                Finding::details(RiskSignalKind::VoteBurst, $config->userBurstVotes, (int) $row->c, $config->userBurstMinutes, ['scope' => 'user', 'vote_ids' => $ids], $capped),
                RiskSignalKey::burstUser($row->user_id, $date),
            );
        }
    }
}
