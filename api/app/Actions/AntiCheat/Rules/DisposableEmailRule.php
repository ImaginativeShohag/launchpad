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
 * R5: the member's e-mail domain equals an entry of the disposable list or is a subdomain of one. Only the domain leaves
 * SQL (`substring(email from '@([^@]*)$')`, the part after the last `@`), never the address; matching is plain string comparison, no pattern is built from the list; the
 * evidence names the list entry, not the member's domain. Once per account, ever.
 */
class DisposableEmailRule implements Rule
{
    public function scope(): ScanScope
    {
        return ScanScope::Users;
    }

    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightDisposableEmail <= 0 || $config->domains === [] || $scan->userIds === []) {
            return;
        }

        $rows = Sql::select(
            "SELECT id, substring(email from '@([^@]*)\$') AS domain FROM users WHERE id IN (".Sql::placeholders($scan->userIds).') AND deleted_at IS NULL ORDER BY id',
            $scan->userIds,
        );

        foreach ($rows as $row) {
            $domain = (string) $row->domain;

            foreach ($config->domains as $entry) {
                if ($domain === $entry || str_ends_with($domain, '.'.$entry)) {
                    yield new Finding(
                        RiskSignalKind::DisposableEmail, $row->id, null, $config->weightDisposableEmail,
                        Finding::details(RiskSignalKind::DisposableEmail, 1, 1, null, ['matched_domain' => $entry]),
                        RiskSignalKey::disposableEmail($row->id),
                    );

                    break;
                }
            }
        }
    }
}
