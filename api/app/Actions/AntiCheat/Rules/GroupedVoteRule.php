<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\Sql;
use App\Enums\RiskSignalKind;
use App\Enums\ScanScope;

/**
 * The shared query of R1 and R2: groups of at least N distinct accounts that voted on one product from one hash (device or
 * IP) inside the window. The hash lives only in this loop and in the SQL; findings carry ids and counts.
 */
abstract class GroupedVoteRule implements Rule
{
    public function scope(): ScanScope
    {
        return ScanScope::Products;
    }

    /**
     * @param  'device_hash'|'ip_hash'  $column
     * @return iterable<array{product_id: string, users: list<array{user_id: string, vote_id: string, established: bool}>}>
     */
    protected function groups(RuleConfig $config, Scan $scan, string $column, int $minAccounts): iterable
    {
        if ($scan->productIds === []) {
            return;
        }

        $windowStart = $scan->now->subHours($config->groupWindowHours);
        $establishedBefore = $scan->now->subDays($config->ipClusterEstablishedDays);

        // A deleted member's vote never counts, in the group size either (the inner CTE already excludes it).
        $rows = Sql::select(
            'WITH a AS (SELECT v.id AS vote_id, v.product_id, v.user_id, v.'.$column.' AS h, (u.created_at < ?::timestamptz) AS established'
            .' FROM votes v JOIN users u ON u.id = v.user_id'
            .' WHERE v.product_id IN ('.Sql::placeholders($scan->productIds).') AND '.Sql::activeVote('v')
            .' AND v.voted_at >= ?::timestamptz AND v.'.$column.' IS NOT NULL AND u.deleted_at IS NULL),'
            .' g AS (SELECT product_id, h FROM a GROUP BY product_id, h HAVING COUNT(DISTINCT user_id) >= ?)'
            .' SELECT a.product_id, a.h, a.user_id, a.vote_id, a.established FROM a JOIN g ON g.product_id = a.product_id AND g.h = a.h'
            .' ORDER BY a.product_id, a.h, a.user_id',
            [Sql::ts($establishedBefore), ...$scan->productIds, Sql::ts($windowStart), $minAccounts],
        );

        $group = [];
        $currentKey = null;

        foreach ($rows as $row) {
            $key = $row->product_id.'|'.$row->h;

            if ($key !== $currentKey && $group !== []) {
                yield $group;
                $group = [];
            }

            $currentKey = $key;
            $group['product_id'] = $row->product_id;
            $group['users'][] = ['user_id' => $row->user_id, 'vote_id' => $row->vote_id, 'established' => (bool) $row->established];
        }

        if ($group !== []) {
            yield $group;
        }
    }

    /**
     * One finding per account of a group.
     *
     * @param  array{product_id: string, users: list<array{user_id: string, vote_id: string, established: bool}>}  $group
     * @return iterable<Finding>
     */
    protected function findingsFor(array $group, RiskSignalKind $kind, int $weight, int $threshold, int $windowMinutes, callable $key): iterable
    {
        [$userIds, $usersCapped] = Finding::capped(array_column($group['users'], 'user_id'));
        [$voteIds, $votesCapped] = Finding::capped(array_column($group['users'], 'vote_id'));
        $details = Finding::details($kind, $threshold, count($group['users']), $windowMinutes, ['user_ids' => $userIds, 'vote_ids' => $voteIds], $usersCapped || $votesCapped);

        foreach ($group['users'] as $member) {
            yield new Finding($kind, $member['user_id'], $group['product_id'], $weight, $details, $key($member['user_id'], $group['product_id']));
        }
    }
}
