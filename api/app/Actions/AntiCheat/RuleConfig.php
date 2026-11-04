<?php

namespace App\Actions\AntiCheat;

use App\Enums\SettingKey;
use App\Support\Settings;

/**
 * Every anti-cheat setting for one run, read once through the typed `Settings` readers (so a corrupt stored value falls
 * back to its default with a logged warning) and then immutable. Rules never touch `Settings` themselves.
 */
final readonly class RuleConfig
{
    /**
     * @param  list<string>  $domains  the disposable e-mail domains (lowercase)
     * @param  array{watch: float, alert: float}  $bands  the product integrity ratios
     */
    public function __construct(
        public int $weightSharedDevice,
        public int $weightIpCluster,
        public int $weightVoteBurst,
        public int $weightFreshAccount,
        public int $weightDisposableEmail,
        public int $weightSingleMakerVoter,
        public int $weightLoginChurn,
        public int $weightCoordinatedVotes,
        public int $groupWindowHours,
        public int $sharedDeviceMinAccounts,
        public int $ipClusterMinAccounts,
        public int $ipClusterEstablishedDays,
        public float $ipClusterDiscount,
        public int $burstWindowMinutes,
        public int $burstMinVotes,
        public float $burstRatio,
        public int $userBurstVotes,
        public int $userBurstMinutes,
        public int $freshAccountMinutes,
        public array $domains,
        public int $singleMakerMinVotes,
        public int $singleMakerDays,
        public float $singleMakerShare,
        public int $loginChurnMinAccounts,
        public int $coordinatedMinAccounts,
        public int $coordinatedMinProducts,
        public float $coordinatedOverlap,
        public int $coordinatedMaxPopularityPct,
        public int $decayDays,
        public int $flagScore,
        public array $bands,
    ) {}

    public static function fromSettings(Settings $s): self
    {
        return new self(
            weightSharedDevice: $s->integer(SettingKey::AnticheatWeightSharedDevice),
            weightIpCluster: $s->integer(SettingKey::AnticheatWeightIpCluster),
            weightVoteBurst: $s->integer(SettingKey::AnticheatWeightVoteBurst),
            weightFreshAccount: $s->integer(SettingKey::AnticheatWeightFreshAccount),
            weightDisposableEmail: $s->integer(SettingKey::AnticheatWeightDisposableEmail),
            weightSingleMakerVoter: $s->integer(SettingKey::AnticheatWeightSingleMakerVoter),
            weightLoginChurn: $s->integer(SettingKey::AnticheatWeightLoginChurn),
            weightCoordinatedVotes: $s->integer(SettingKey::AnticheatWeightCoordinatedVotes),
            groupWindowHours: $s->integer(SettingKey::AnticheatGroupWindowHours),
            sharedDeviceMinAccounts: $s->integer(SettingKey::AnticheatSharedDeviceMinAccounts),
            ipClusterMinAccounts: $s->integer(SettingKey::AnticheatIpClusterMinAccounts),
            ipClusterEstablishedDays: $s->integer(SettingKey::AnticheatIpClusterEstablishedDays),
            ipClusterDiscount: $s->decimal(SettingKey::AnticheatIpClusterDiscount),
            burstWindowMinutes: $s->integer(SettingKey::AnticheatBurstWindowMinutes),
            burstMinVotes: $s->integer(SettingKey::AnticheatBurstMinVotes),
            burstRatio: $s->decimal(SettingKey::AnticheatBurstRatio),
            userBurstVotes: $s->integer(SettingKey::AnticheatUserBurstVotes),
            userBurstMinutes: $s->integer(SettingKey::AnticheatUserBurstMinutes),
            freshAccountMinutes: $s->integer(SettingKey::AnticheatFreshAccountMinutes),
            domains: $s->domains(),
            singleMakerMinVotes: $s->integer(SettingKey::AnticheatSingleMakerMinVotes),
            singleMakerDays: $s->integer(SettingKey::AnticheatSingleMakerDays),
            singleMakerShare: $s->decimal(SettingKey::AnticheatSingleMakerShare),
            loginChurnMinAccounts: $s->integer(SettingKey::AnticheatLoginChurnMinAccounts),
            coordinatedMinAccounts: $s->integer(SettingKey::AnticheatCoordinatedMinAccounts),
            coordinatedMinProducts: $s->integer(SettingKey::AnticheatCoordinatedMinProducts),
            coordinatedOverlap: $s->decimal(SettingKey::AnticheatCoordinatedOverlap),
            coordinatedMaxPopularityPct: $s->integer(SettingKey::AnticheatCoordinatedMaxPopularityPct),
            decayDays: $s->integer(SettingKey::AnticheatDecayDays),
            flagScore: $s->integer(SettingKey::AnticheatFlagRiskScore),
            bands: $s->integrityBands(),
        );
    }
}
