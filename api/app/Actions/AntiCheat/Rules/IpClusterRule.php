<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\RiskSignalKey;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Enums\RiskSignalKind;

/**
 * R2: at least N distinct accounts vote on the same product from the same IP inside the window. Households, campuses and
 * carrier NAT share addresses, so the weight is low, and it is multiplied by the discount (rounded half away from zero)
 * when every account of the group is older than `established_days`.
 */
class IpClusterRule extends GroupedVoteRule
{
    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightIpCluster <= 0) {
            return;
        }

        foreach ($this->groups($config, $scan, 'ip_hash', $config->ipClusterMinAccounts) as $group) {
            $allEstablished = ! in_array(false, array_column($group['users'], 'established'), true);
            $weight = $allEstablished ? (int) round($config->weightIpCluster * $config->ipClusterDiscount) : $config->weightIpCluster;

            yield from $this->findingsFor(
                $group, RiskSignalKind::IpCluster, $weight, $config->ipClusterMinAccounts,
                $config->groupWindowHours * 60, RiskSignalKey::ipCluster(...),
            );
        }
    }
}
