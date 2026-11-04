<?php

namespace App\Actions\Retention;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The daily retention run: vote hashes, auth events, rank snapshots, the network columns of audit_logs, bell notifications,
 * finished outbox mail and digest run rows, expired exports (the zips go after each chunk commits) and risk signals (365 days
 * unreviewed, 90 days after the review, 365 at most), each in its own try/catch so one failing table does not stop the
 * others. `audit_logs` rows are never deleted (append-only by trigger); only their `ip_hash` and `user_agent` are emptied.
 */
class PurgeRetention
{
    /** @var array<string, array{purged: int, capped: bool, failed: bool}> table => outcome of the last run */
    public array $results = [];

    public int $failed = 0;

    public function __construct(
        private readonly PurgeVoteHashes $votes,
        private readonly PurgeAuthEvents $authEvents,
        private readonly PurgeRankSnapshots $rankSnapshots,
        private readonly PurgeAuditNetwork $auditNetwork,
        private readonly PurgeNotifications $notifications,
        private readonly PurgeEmailMessages $emailMessages,
        private readonly PurgeDigestRuns $digestRuns,
        private readonly PurgeExports $exports,
        private readonly PurgeRiskSignals $riskSignals,
    ) {}

    /**
     * @param  CarbonImmutable|null  $now  fixed instant for tests; the real clock, read once per run, when null
     * @return int total rows purged across all tables, including chunks committed before a table failed
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $this->results = [];
        $this->failed = 0;
        $total = 0;

        foreach ([$this->votes, $this->authEvents, $this->rankSnapshots, $this->auditNetwork, $this->notifications, $this->emailMessages, $this->digestRuns, $this->exports, $this->riskSignals] as $purge) {
            try {
                $purged = $purge->purge($now);
                $total += $purged;
                $this->results[$purge->table()] = ['purged' => $purged, 'capped' => $purge->capped, 'failed' => false];
            } catch (Throwable $e) {
                $this->failed++;
                report($e);
                // Chunks before the failing one are committed: count them.
                $this->results[$purge->table()] = ['purged' => $purge->purgedSoFar, 'capped' => false, 'failed' => true];
                $total += $purge->purgedSoFar;
            }
        }

        return $total;
    }
}
