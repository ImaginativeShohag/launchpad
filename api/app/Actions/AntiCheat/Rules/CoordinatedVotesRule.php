<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\RiskSignalKey;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\Sql;
use App\Enums\RiskSignalKind;
use App\Enums\ScanScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * R8 (once per run, last): among members who already carry another open signal (including the ones this run just wrote),
 * accounts that vote for the same products. Two members are linked when they share at least `min_products` active votes on
 * distinct products in the last 30 days and the overlap (shared / the smaller vote set) is at least `overlap`. "At least N
 * accounts" is the connected component of that pair graph having N members; every member of a qualifying component gets a
 * signal. The candidate list is capped (members touched by this run first, then strongest and newest signals), which also bounds
 * the self-join, and the pair query runs under a transaction-local statement timeout.
 *
 * Knob `anticheat.coordinated.max_popularity_pct` (default 40, 5-100): a product voted by MORE than this share of the
 * candidate set is ignored when counting vote sets, shared products and overlap, so everyone piling onto the day's
 * biggest launch does not link them. 100 switches the filter off.
 */
class CoordinatedVotesRule implements Rule
{
    /** The most candidates one run considers. A subclass may lower it for a test. */
    protected const MAX_CANDIDATES = 500;

    /** The most milliseconds the pair self-join may run. A subclass may lower it for a test. */
    protected const STATEMENT_TIMEOUT_MS = 10000;

    private const VOTE_WINDOW_DAYS = 30;

    public function scope(): ScanScope
    {
        return ScanScope::Run;
    }

    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightCoordinatedVotes <= 0) {
            return;
        }

        $candidates = $this->candidates($config, $scan);

        if (count($candidates) < $config->coordinatedMinAccounts) {
            return;
        }

        // Products too many candidates voted for (the popular launch of the day) say nothing about coordination: they are
        // left out of both the vote sets and the shared products. "More than" max_popularity_pct percent of the candidate set.
        $windowStart = Sql::ts($scan->now->subDays(self::VOTE_WINDOW_DAYS));
        $votes = 'WITH base AS (SELECT v.user_id, v.product_id FROM votes v JOIN users u ON u.id = v.user_id WHERE v.user_id IN ('.Sql::placeholders($candidates).') AND '.Sql::activeVote('v')
            .' AND v.voted_at >= ?::timestamptz AND u.deleted_at IS NULL),'
            .' v AS (SELECT user_id, product_id FROM base WHERE product_id NOT IN (SELECT product_id FROM base GROUP BY product_id HAVING COUNT(*) * 100 > ?::int * ?::int)) ';
        $voteBindings = [...$candidates, $windowStart, $config->coordinatedMaxPopularityPct, count($candidates)];

        $sizes = [];
        foreach (Sql::select($votes.'SELECT user_id, COUNT(*) AS c FROM v GROUP BY user_id', $voteBindings) as $row) {
            $sizes[$row->user_id] = (int) $row->c;
        }

        // The pair self-join is the one expensive statement of the detection: cap it, transaction-locally, and put the
        // previous value back afterwards (the surrounding chunk transaction may run more reads).
        $pairs = DB::transaction(function () use ($votes, $voteBindings, $config): array {
            $previous = (string) (Sql::select("SELECT current_setting('statement_timeout') AS v")[0]->v ?? '0');
            Sql::select('SELECT set_config(?, ?, true)', ['statement_timeout', (string) static::STATEMENT_TIMEOUT_MS]);
            $rows = Sql::select(
                $votes.'SELECT a.user_id AS a, b.user_id AS b, json_agg(a.product_id ORDER BY a.product_id) AS shared FROM v a JOIN v b ON a.product_id = b.product_id AND a.user_id < b.user_id'
                .' GROUP BY a.user_id, b.user_id HAVING COUNT(*) >= ?',
                [...$voteBindings, $config->coordinatedMinProducts],
            );
            Sql::select('SELECT set_config(?, ?, true)', ['statement_timeout', $previous]);

            return $rows;
        });

        // Thousandths, as in the single-maker rule: no float edge at exactly the overlap.
        $overlapMilli = (int) round($config->coordinatedOverlap * 1000);
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $linked = [];

        foreach ($pairs as $pair) {
            /** @var list<string> $shared */
            $shared = json_decode($pair->shared, true, flags: JSON_THROW_ON_ERROR);
            $smaller = min($sizes[$pair->a] ?? 0, $sizes[$pair->b] ?? 0);

            if ($smaller === 0 || count($shared) * 1000 < $overlapMilli * $smaller) {
                continue;
            }

            $rootA = $find($pair->a);
            $rootB = $find($pair->b);
            if ($rootA !== $rootB) {
                $parent[$rootA] = $rootB;
            }
            $linked[] = [$pair->a, $pair->b, $shared];
        }

        /** @var array<string, list<string>> $memberLists root => members of the component */
        $memberLists = [];
        foreach (array_keys($parent) as $member) {
            $memberLists[$find($member)][] = $member;
        }
        /** @var array<string, list<string>> $productLists root => products shared inside the component */
        $productLists = [];
        foreach ($linked as [$a, $b, $shared]) {
            $productLists[$find($a)] = [...($productLists[$find($a)] ?? []), ...$shared];
        }

        $date = $scan->now->utc()->toDateString();

        foreach ($memberLists as $root => $members) {
            if (count($members) < $config->coordinatedMinAccounts) {
                continue;
            }

            [$userIds, $usersCapped] = Finding::capped($members);
            [$productIds, $productsCapped] = Finding::capped($productLists[$root] ?? []);
            $details = Finding::details(RiskSignalKind::CoordinatedVotes, $config->coordinatedMinAccounts, count($members), null, ['user_ids' => $userIds, 'shared_product_ids' => $productIds], $usersCapped || $productsCapped);

            sort($members);
            foreach ($members as $userId) {
                yield new Finding(RiskSignalKind::CoordinatedVotes, $userId, null, $config->weightCoordinatedVotes, $details, RiskSignalKey::coordinatedVotes($userId, $date));
            }
        }
    }

    /**
     * The candidate members: those named by a finding earlier in this run are always in; the rest fill the cap, strongest
     * signal first and newest first, so a backlog of old weak signals cannot hide a ring that just appeared. Read after the
     * chunk transactions committed, so the signals this run wrote are visible. A cut list logs a warning (a count only).
     *
     * @return list<string>
     */
    private function candidates(RuleConfig $config, Scan $scan): array
    {
        $touched = $scan->touchedUserIds;
        $cap = static::MAX_CANDIDATES;

        $rows = Sql::select(
            'SELECT user_id, (user_id = ANY(string_to_array(?, \',\'))) AS touched FROM risk_signals'
            ." WHERE reviewed_at IS NULL AND user_id IS NOT NULL AND weight > 0 AND kind <> 'coordinated_votes' AND detected_at >= ?::timestamptz"
            .' GROUP BY user_id ORDER BY touched DESC, MAX(weight) DESC, MAX(detected_at) DESC, user_id LIMIT '.($cap + count($touched) + 1),
            [implode(',', $touched), Sql::ts($scan->now->subDays($config->decayDays))],
        );

        $always = [];
        $others = [];
        foreach ($rows as $row) {
            if ($row->touched) {
                $always[] = $row->user_id;
            } else {
                $others[] = $row->user_id;
            }
        }

        $room = (int) max(0, $cap - count($always));

        if (count($others) > $room) {
            Log::warning('Coordinated votes candidate list was truncated.', ['cap' => $cap]);
        }

        return [...$always, ...array_slice($others, 0, $room)];
    }
}
