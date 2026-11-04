<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\RiskSignalKey;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Enums\RiskSignalKind;

/**
 * R1: at least N distinct accounts have an active vote on the same product from the same device inside the window. A lab
 * computer that votes for different products never fires, and one account re-voting is one account. One signal per account.
 */
class SharedDeviceRule extends GroupedVoteRule
{
    public function detect(RuleConfig $config, Scan $scan): iterable
    {
        if ($config->weightSharedDevice <= 0) {
            return;
        }

        foreach ($this->groups($config, $scan, 'device_hash', $config->sharedDeviceMinAccounts) as $group) {
            yield from $this->findingsFor(
                $group, RiskSignalKind::SharedDevice, $config->weightSharedDevice, $config->sharedDeviceMinAccounts,
                $config->groupWindowHours * 60, RiskSignalKey::sharedDevice(...),
            );
        }
    }
}
