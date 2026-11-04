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
 * R4: a member's active vote was cast less than N minutes after the account was created. Once per account, ever (the
 * dedupe key has no date). A vote exactly N minutes after creation is not fresh.
 */
class FreshAccountRule implements Rule
{
    public function scope(): ScanScope
    {
        return ScanScope::Users;
    }

    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightFreshAccount <= 0 || $scan->userIds === []) {
            return;
        }

        // The earliest qualifying vote per member; if any vote qualifies, the earliest one does.
        $rows = Sql::select(
            'SELECT DISTINCT ON (v.user_id) v.user_id, v.id AS vote_id, GREATEST(floor(extract(epoch FROM v.voted_at - u.created_at) / 60), 0)::int AS age_minutes'
            .' FROM votes v JOIN users u ON u.id = v.user_id'
            .' WHERE v.user_id IN ('.Sql::placeholders($scan->userIds).') AND '.Sql::activeVote('v')
            ." AND u.deleted_at IS NULL AND v.voted_at < u.created_at + (?::int * interval '1 minute')"
            .' ORDER BY v.user_id, v.voted_at, v.id',
            [...$scan->userIds, $config->freshAccountMinutes],
        );

        foreach ($rows as $row) {
            yield new Finding(
                RiskSignalKind::FreshAccount, $row->user_id, null, $config->weightFreshAccount,
                Finding::details(RiskSignalKind::FreshAccount, $config->freshAccountMinutes, (int) $row->age_minutes, null, ['vote_id' => $row->vote_id, 'account_age_minutes' => (int) $row->age_minutes]),
                RiskSignalKey::freshAccount($row->user_id),
            );
        }
    }
}
