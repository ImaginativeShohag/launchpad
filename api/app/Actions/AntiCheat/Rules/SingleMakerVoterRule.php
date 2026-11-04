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
 * R6: a member with at least N active votes in the last D days gave at least `share` of them to products of one maker. It
 * reads ids and `voted_at` only, so it survives the hash purge. Soft-deleted products still count.
 */
class SingleMakerVoterRule implements Rule
{
    public function scope(): ScanScope
    {
        return ScanScope::Users;
    }

    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightSingleMakerVoter <= 0 || $scan->userIds === []) {
            return;
        }

        $windowStart = $scan->now->subDays($config->singleMakerDays);

        $rows = Sql::select(
            'SELECT v.user_id, p.maker_id, COUNT(*) AS c FROM votes v JOIN products p ON p.id = v.product_id JOIN users u ON u.id = v.user_id'
            .' WHERE v.user_id IN ('.Sql::placeholders($scan->userIds).') AND '.Sql::activeVote('v')
            .' AND v.voted_at >= ?::timestamptz AND u.deleted_at IS NULL GROUP BY v.user_id, p.maker_id ORDER BY v.user_id, p.maker_id',
            [...$scan->userIds, Sql::ts($windowStart)],
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->user_id] = ($totals[$row->user_id] ?? 0) + (int) $row->c;
        }

        // The share has at most three decimals, so compare in thousandths with integers: no float edge at exactly 0.8.
        $shareMilli = (int) round($config->singleMakerShare * 1000);

        foreach ($rows as $row) {
            $total = $totals[$row->user_id];
            $votes = (int) $row->c;

            if ($total < $config->singleMakerMinVotes || $votes * 1000 < $shareMilli * $total) {
                continue;
            }

            yield new Finding(
                RiskSignalKind::SingleMakerVoter, $row->user_id, null, $config->weightSingleMakerVoter,
                Finding::details(RiskSignalKind::SingleMakerVoter, $config->singleMakerMinVotes, $votes, null, [
                    'maker_id' => $row->maker_id, 'votes' => $votes, 'total_votes' => $total, 'share' => round($votes / $total, 3),
                ]),
                RiskSignalKey::singleMakerVoter($row->user_id, $row->maker_id),
            );
        }
    }
}
