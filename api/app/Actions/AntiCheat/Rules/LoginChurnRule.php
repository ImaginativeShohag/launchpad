<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\RiskSignalKey;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\Sql;
use App\Enums\RiskSignalKind;
use App\Enums\ScanScope;
use Illuminate\Support\Facades\Log;

/**
 * R7 (once per run): at least N distinct accounts signed up or logged in from one device inside the window, and the member
 * has an active vote in the window. A household that never votes the same day is not touched; failed logins are not in the
 * event list. One query over `auth_events` finds the device groups (largest first, capped), a second restricts the members
 * to voters. The device hash lives only in this loop; the evidence holds ids and counts.
 */
class LoginChurnRule implements Rule
{
    /** The most device groups one run looks at. A subclass may lower it for a test. */
    protected const MAX_DEVICE_GROUPS = 2000;

    private const VOTER_CHUNK = 1000;

    public function scope(): ScanScope
    {
        return ScanScope::Run;
    }

    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightLoginChurn <= 0) {
            return;
        }

        $windowStart = Sql::ts($scan->now->subHours($config->groupWindowHours));
        $filter = "event IN ('signup', 'login_succeeded') AND created_at >= ?::timestamptz AND device_hash IS NOT NULL AND user_id IS NOT NULL";

        $rows = Sql::select(
            "SELECT e.device_hash, e.user_id, e.id FROM auth_events e WHERE e.{$filter} AND e.device_hash IN ("
            ."SELECT device_hash FROM auth_events WHERE {$filter} GROUP BY device_hash HAVING COUNT(DISTINCT user_id) >= ?"
            .' ORDER BY COUNT(DISTINCT user_id) DESC, device_hash LIMIT '.static::MAX_DEVICE_GROUPS.') ORDER BY e.device_hash, e.user_id, e.id',
            [$windowStart, $windowStart, $config->loginChurnMinAccounts],
        );

        /** @var array<string, array{users: array<string, true>, events: list<string>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->device_hash]['users'][$row->user_id] = true;
            $groups[$row->device_hash]['events'][] = $row->id;
        }

        if ($groups === []) {
            return;
        }

        if (count($groups) >= static::MAX_DEVICE_GROUPS) {
            // Counts only, never a hash.
            Log::warning('Login churn scan reached its device group cap; the smaller groups were skipped.', ['cap' => static::MAX_DEVICE_GROUPS]);
        }

        // Largest group first, so a member in two groups keeps the stronger evidence.
        uasort($groups, fn (array $a, array $b) => count($b['users']) <=> count($a['users']));

        $voters = $this->voters($scan, array_keys(array_merge(...array_map(fn (array $g) => $g['users'], array_values($groups)))), $windowStart);
        $date = $scan->now->utc()->toDateString();
        $seen = [];

        foreach ($groups as $group) {
            $members = array_keys($group['users']);
            $voting = array_values(array_filter($members, fn (string $id) => isset($voters[$id])));

            if ($voting === []) {
                continue;
            }

            [$userIds, $usersCapped] = Finding::capped($voting);
            [$eventIds, $eventsCapped] = Finding::capped($group['events']);
            $details = Finding::details(RiskSignalKind::LoginChurn, $config->loginChurnMinAccounts, count($members), $config->groupWindowHours * 60, ['user_ids' => $userIds, 'auth_event_ids' => $eventIds], $usersCapped || $eventsCapped);

            foreach ($voting as $userId) {
                if (isset($seen[$userId])) {
                    continue;
                }
                $seen[$userId] = true;

                yield new Finding(RiskSignalKind::LoginChurn, $userId, null, $config->weightLoginChurn, $details, RiskSignalKey::loginChurn($userId, $date));
            }
        }
    }

    /**
     * The members with an active vote in the window.
     *
     * @param  list<string>  $userIds
     * @return array<string, true>
     */
    private function voters(Scan $scan, array $userIds, string $windowStart): array
    {
        $voters = [];

        foreach (array_chunk($userIds, self::VOTER_CHUNK) as $chunk) {
            foreach (Sql::select(
                'SELECT DISTINCT v.user_id FROM votes v JOIN users u ON u.id = v.user_id WHERE v.user_id IN ('.Sql::placeholders($chunk).') AND '.Sql::activeVote('v')
                .' AND v.voted_at >= ?::timestamptz AND u.deleted_at IS NULL',
                [...$chunk, $windowStart],
            ) as $row) {
                $voters[$row->user_id] = true;
            }
        }

        return $voters;
    }
}
