<?php

namespace App\Enums;

/**
 * Every admin-editable business rule. Admins change values, never invent keys.
 * Each case declares its default and its validation rules (type and bounds).
 */
enum SettingKey: string
{
    case LaunchMaxPerMakerPerDay = 'launch.max_per_maker_per_day';
    case LaunchMaxDaysAhead = 'launch.max_days_ahead';
    case ProductMaxTopics = 'product.max_topics';
    case ProductMaxScreenshots = 'product.max_screenshots';
    case ProductMaxPendingPerMaker = 'product.max_pending_per_maker';
    case AnticheatFlagRiskScore = 'anticheat.flag_risk_score';
    case DigestDailySendAtUtc = 'digest.daily_send_at_utc';
    case DigestWeeklySendAtUtc = 'digest.weekly_send_at_utc';
    case RetentionAuthEventsDays = 'retention.auth_events_days';
    case RetentionVoteHashesDays = 'retention.vote_hashes_days';
    case RetentionRiskSignalsDays = 'retention.risk_signals_days';
    case RetentionRankSnapshotsDays = 'retention.rank_snapshots_days';
    case RetentionAuditNetworkDays = 'retention.audit_network_days';
    case RetentionNotificationsDays = 'retention.notifications_days';
    case RetentionEmailMessagesDays = 'retention.email_messages_days';
    case RetentionExportsDays = 'retention.exports_days';
    case RetentionRiskSignalsReviewedDays = 'retention.risk_signals_reviewed_days';
    case CommunityCommentEditMinutes = 'community.comment_edit_minutes';
    case CommunityReportOpenCap = 'community.report_open_cap';
    // Anti-cheat rules (sub-project 8): weights, thresholds and windows. A weight of 0 switches its rule off.
    case AnticheatWeightSharedDevice = 'anticheat.weight.shared_device';
    case AnticheatWeightIpCluster = 'anticheat.weight.ip_cluster';
    case AnticheatWeightVoteBurst = 'anticheat.weight.vote_burst';
    case AnticheatWeightFreshAccount = 'anticheat.weight.fresh_account';
    case AnticheatWeightDisposableEmail = 'anticheat.weight.disposable_email';
    case AnticheatWeightSingleMakerVoter = 'anticheat.weight.single_maker_voter';
    case AnticheatWeightLoginChurn = 'anticheat.weight.login_churn';
    case AnticheatWeightCoordinatedVotes = 'anticheat.weight.coordinated_votes';
    case AnticheatGroupWindowHours = 'anticheat.group_window_hours';
    case AnticheatSharedDeviceMinAccounts = 'anticheat.shared_device.min_accounts';
    case AnticheatIpClusterMinAccounts = 'anticheat.ip_cluster.min_accounts';
    case AnticheatIpClusterEstablishedDays = 'anticheat.ip_cluster.established_days';
    case AnticheatBurstWindowMinutes = 'anticheat.burst.window_minutes';
    case AnticheatBurstMinVotes = 'anticheat.burst.min_votes';
    case AnticheatUserBurstVotes = 'anticheat.user_burst.votes';
    case AnticheatUserBurstMinutes = 'anticheat.user_burst.minutes';
    case AnticheatFreshAccountMinutes = 'anticheat.fresh_account.minutes';
    case AnticheatSingleMakerMinVotes = 'anticheat.single_maker.min_votes';
    case AnticheatSingleMakerDays = 'anticheat.single_maker.days';
    case AnticheatLoginChurnMinAccounts = 'anticheat.login_churn.min_accounts';
    case AnticheatCoordinatedMinAccounts = 'anticheat.coordinated.min_accounts';
    case AnticheatCoordinatedMinProducts = 'anticheat.coordinated.min_products';
    case AnticheatDecayDays = 'anticheat.decay_days';
    case AnticheatCoordinatedMaxPopularityPct = 'anticheat.coordinated.max_popularity_pct';
    // A comma separated list of lowercase domains, read through Settings::domains().
    case AnticheatDisposableDomains = 'anticheat.disposable_domains';
    // Decimals: settings hold int|string only, so these are decimal strings read through Settings::decimal().
    case RankingDecayExponent = 'ranking.decay_exponent';
    case RankingEarlyBonus = 'ranking.early_bonus';
    case AnticheatIpClusterDiscount = 'anticheat.ip_cluster.discount';
    case AnticheatBurstRatio = 'anticheat.burst.ratio';
    case AnticheatSingleMakerShare = 'anticheat.single_maker.share';
    case AnticheatCoordinatedOverlap = 'anticheat.coordinated.overlap';
    case AnticheatProductWatchRatio = 'anticheat.product_watch_ratio';
    case AnticheatProductAlertRatio = 'anticheat.product_alert_ratio';

    public function default(): int|string
    {
        return match ($this) {
            self::LaunchMaxPerMakerPerDay => 2,
            self::LaunchMaxDaysAhead => 60,
            self::ProductMaxTopics => 3,
            self::ProductMaxScreenshots => 6,
            self::ProductMaxPendingPerMaker => 5,
            self::AnticheatFlagRiskScore => 70,
            self::DigestDailySendAtUtc, self::DigestWeeklySendAtUtc => '07:00',
            self::RetentionAuthEventsDays => 180,
            self::RetentionVoteHashesDays => 90,
            self::RetentionRiskSignalsDays => 365,
            self::RetentionRankSnapshotsDays => 180,
            self::RetentionAuditNetworkDays => 365,
            self::RetentionNotificationsDays, self::RetentionEmailMessagesDays => 90,
            self::RetentionExportsDays => 7,
            self::RetentionRiskSignalsReviewedDays => 90,
            self::AnticheatWeightSharedDevice => 40,
            self::AnticheatWeightIpCluster, self::AnticheatWeightFreshAccount => 15,
            self::AnticheatWeightVoteBurst, self::AnticheatWeightDisposableEmail => 25,
            self::AnticheatWeightSingleMakerVoter, self::AnticheatWeightLoginChurn => 20,
            self::AnticheatWeightCoordinatedVotes => 35,
            self::AnticheatGroupWindowHours => 24,
            self::AnticheatSharedDeviceMinAccounts, self::AnticheatCoordinatedMinAccounts => 3,
            self::AnticheatIpClusterMinAccounts => 5,
            self::AnticheatIpClusterEstablishedDays => 30,
            self::AnticheatBurstWindowMinutes => 10,
            self::AnticheatBurstMinVotes => 30,
            self::AnticheatUserBurstVotes => 8,
            self::AnticheatUserBurstMinutes => 5,
            self::AnticheatFreshAccountMinutes => 30,
            self::AnticheatSingleMakerMinVotes => 5,
            self::AnticheatSingleMakerDays => 30,
            self::AnticheatLoginChurnMinAccounts => 4,
            self::AnticheatCoordinatedMinProducts => 4,
            self::AnticheatDecayDays => 14,
            self::AnticheatCoordinatedMaxPopularityPct => 40,
            self::AnticheatDisposableDomains => self::DEFAULT_DISPOSABLE_DOMAINS,
            self::AnticheatIpClusterDiscount => '0.5',
            self::AnticheatBurstRatio => '4',
            self::AnticheatSingleMakerShare, self::AnticheatCoordinatedOverlap => '0.8',
            self::AnticheatProductWatchRatio => '0.9',
            self::AnticheatProductAlertRatio => '0.7',
            self::CommunityCommentEditMinutes => 15,
            self::CommunityReportOpenCap => 20,
            self::RankingDecayExponent => '0.9',
            self::RankingEarlyBonus => '0.35',
        };
    }

    /** Twenty well-known throwaway mail services; an admin edits the list through the settings endpoint. */
    public const DEFAULT_DISPOSABLE_DOMAINS = 'mailinator.com,guerrillamail.com,10minutemail.com,yopmail.com,tempmail.com,temp-mail.org,throwawaymail.com,trashmail.com,sharklasers.com,getnada.com,dispostable.com,maildrop.cc,fakeinbox.com,mailnesia.com,mintemail.com,mytemp.email,spamgourmet.com,tempinbox.com,discard.email,emailondeck.com';

    /** The disposable domain list: at most this many entries and characters (one place for the write check and the read path). */
    public const DOMAINS_MAX_ENTRIES = 300;

    public const DOMAINS_MAX_CHARS = 4000;

    /** A plain decimal without a sign, exponent, leading zero (other than `0.x`) or trailing newline. */
    public const DECIMAL_PATTERN = '/^(0|[1-9]\d?)(\.\d{1,3})?\z/';

    /**
     * The allowed range of a decimal setting; one place for the write rules and for `Settings::decimal()`.
     *
     * @return array{0: float, 1: float}
     */
    public function decimalRange(): array
    {
        return match ($this) {
            self::RankingDecayExponent => [0.1, 3.0],
            self::RankingEarlyBonus => [0.0, 5.0],
            self::AnticheatIpClusterDiscount => [0.0, 1.0],
            self::AnticheatBurstRatio => [1.0, 20.0],
            self::AnticheatSingleMakerShare, self::AnticheatCoordinatedOverlap => [0.5, 1.0],
            self::AnticheatProductWatchRatio, self::AnticheatProductAlertRatio => [0.1, 1.0],
            default => throw new \LogicException($this->value.' is not a decimal setting.'),
        };
    }

    /**
     * The allowed range of a plain integer setting that is not a retention window; one place for the write rules and for `Settings::integer()`.
     *
     * @return array{0: int, 1: int}
     */
    public function intRange(): array
    {
        return match ($this) {
            self::CommunityCommentEditMinutes => [0, 1440],
            self::CommunityReportOpenCap => [1, 200],
            self::AnticheatFlagRiskScore => [1, 100],
            self::AnticheatWeightSharedDevice, self::AnticheatWeightIpCluster, self::AnticheatWeightVoteBurst, self::AnticheatWeightFreshAccount,
            self::AnticheatWeightDisposableEmail, self::AnticheatWeightSingleMakerVoter, self::AnticheatWeightLoginChurn, self::AnticheatWeightCoordinatedVotes => [0, 100],
            self::AnticheatGroupWindowHours => [1, 48],
            self::AnticheatSharedDeviceMinAccounts => [2, 20],
            self::AnticheatIpClusterMinAccounts => [2, 50],
            self::AnticheatIpClusterEstablishedDays => [1, 365],
            self::AnticheatBurstWindowMinutes => [1, 60],
            self::AnticheatBurstMinVotes => [5, 1000],
            self::AnticheatUserBurstVotes => [3, 50],
            self::AnticheatUserBurstMinutes => [1, 60],
            self::AnticheatFreshAccountMinutes => [1, 1440],
            self::AnticheatSingleMakerMinVotes => [3, 50],
            self::AnticheatSingleMakerDays => [7, 90],
            self::AnticheatLoginChurnMinAccounts => [2, 50],
            self::AnticheatCoordinatedMinAccounts => [2, 20],
            self::AnticheatCoordinatedMinProducts => [2, 50],
            self::AnticheatDecayDays => [1, 90],
            self::AnticheatCoordinatedMaxPopularityPct => [5, 100],
            default => throw new \LogicException($this->value.' is not an integer-range setting.'),
        };
    }

    /**
     * The allowed range (in days) of a retention window; one place for the write rules and for `Settings::days()`.
     *
     * @return array{0: int, 1: int}
     */
    public function dayRange(): array
    {
        return match ($this) {
            self::RetentionAuthEventsDays, self::RetentionVoteHashesDays => [7, 3650],
            self::RetentionRiskSignalsDays, self::RetentionAuditNetworkDays => [30, 3650],
            // The email window never goes below 30 days: a purged `sent` row frees its idempotency key, and every producer's
            // re-derivation window (the 48 hour sweep) must stay far inside it (see Reconcile::MIN_EMAIL_RETENTION_HOURS).
            self::RetentionRankSnapshotsDays, self::RetentionNotificationsDays => [7, 365],
            self::RetentionEmailMessagesDays => [30, 365],
            // A finished export is the maker's own data copy: a day to a month is plenty to download it.
            self::RetentionExportsDays => [1, 30],
            self::RetentionRiskSignalsReviewedDays => [7, 3650],
            default => throw new \LogicException($this->value.' is not a retention setting.'),
        };
    }

    /** @return list<string> */
    public function rules(): array
    {
        return match ($this) {
            self::LaunchMaxPerMakerPerDay => ['required', 'integer', 'between:1,20'],
            self::LaunchMaxDaysAhead => ['required', 'integer', 'between:1,365'],
            self::ProductMaxTopics => ['required', 'integer', 'between:1,5'],
            self::ProductMaxScreenshots => ['required', 'integer', 'between:1,20'],
            self::ProductMaxPendingPerMaker => ['required', 'integer', 'between:1,50'],
            self::AnticheatFlagRiskScore => ['required', 'integer', 'between:1,100'],
            // Before 22:00: the digest give-up hour (23:00) must always leave room after the send time.
            self::DigestDailySendAtUtc, self::DigestWeeklySendAtUtc => ['required', 'date_format:H:i', 'regex:/\A([01]\d|2[01]):[0-5]\d\z/'],
            self::RetentionAuthEventsDays, self::RetentionVoteHashesDays, self::RetentionRiskSignalsDays, self::RetentionRankSnapshotsDays, self::RetentionAuditNetworkDays, self::RetentionNotificationsDays, self::RetentionEmailMessagesDays, self::RetentionExportsDays, self::RetentionRiskSignalsReviewedDays => ['required', 'integer', 'between:'.implode(',', $this->dayRange())],
            self::CommunityCommentEditMinutes, self::CommunityReportOpenCap,
            self::AnticheatWeightSharedDevice, self::AnticheatWeightIpCluster, self::AnticheatWeightVoteBurst, self::AnticheatWeightFreshAccount,
            self::AnticheatWeightDisposableEmail, self::AnticheatWeightSingleMakerVoter, self::AnticheatWeightLoginChurn, self::AnticheatWeightCoordinatedVotes,
            self::AnticheatGroupWindowHours, self::AnticheatSharedDeviceMinAccounts, self::AnticheatIpClusterMinAccounts, self::AnticheatIpClusterEstablishedDays,
            self::AnticheatBurstWindowMinutes, self::AnticheatBurstMinVotes, self::AnticheatUserBurstVotes, self::AnticheatUserBurstMinutes,
            self::AnticheatFreshAccountMinutes, self::AnticheatSingleMakerMinVotes, self::AnticheatSingleMakerDays, self::AnticheatLoginChurnMinAccounts,
            self::AnticheatCoordinatedMinAccounts, self::AnticheatCoordinatedMinProducts, self::AnticheatDecayDays, self::AnticheatCoordinatedMaxPopularityPct => ['required', 'integer', 'between:'.implode(',', $this->intRange())],
            // The shape rules only; the entries themselves are checked by UpdateSettingRequest::after() through Settings::parseDomains().
            self::AnticheatDisposableDomains => ['required', 'string', 'max:'.self::DOMAINS_MAX_CHARS],
            // `numeric` makes `between` compare values, not string lengths; the regex keeps out "1e2", signs and long tails.
            self::RankingDecayExponent, self::RankingEarlyBonus,
            self::AnticheatIpClusterDiscount, self::AnticheatBurstRatio, self::AnticheatSingleMakerShare, self::AnticheatCoordinatedOverlap,
            self::AnticheatProductWatchRatio, self::AnticheatProductAlertRatio => ['required', 'string', 'regex:'.self::DECIMAL_PATTERN, 'numeric', 'between:'.implode(',', $this->decimalRange())],
        };
    }
}
