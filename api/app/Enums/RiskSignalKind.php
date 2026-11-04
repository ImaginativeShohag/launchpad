<?php

namespace App\Enums;

/** What a risk signal is about; one case per detection rule family (vote_burst covers the product and the member form). */
enum RiskSignalKind: string
{
    case SharedDevice = 'shared_device';
    case IpCluster = 'ip_cluster';
    case VoteBurst = 'vote_burst';
    case FreshAccount = 'fresh_account';
    case DisposableEmail = 'disposable_email';
    case SingleMakerVoter = 'single_maker_voter';
    case LoginChurn = 'login_churn';
    case CoordinatedVotes = 'coordinated_votes';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
