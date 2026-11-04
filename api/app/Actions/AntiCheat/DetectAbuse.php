<?php

namespace App\Actions\AntiCheat;

use App\Actions\AntiCheat\Rules\CoordinatedVotesRule;
use App\Actions\AntiCheat\Rules\DisposableEmailRule;
use App\Actions\AntiCheat\Rules\FreshAccountRule;
use App\Actions\AntiCheat\Rules\IpClusterRule;
use App\Actions\AntiCheat\Rules\LoginChurnRule;
use App\Actions\AntiCheat\Rules\ProductBurstRule;
use App\Actions\AntiCheat\Rules\Rule;
use App\Actions\AntiCheat\Rules\SharedDeviceRule;
use App\Actions\AntiCheat\Rules\SingleMakerVoterRule;
use App\Actions\AntiCheat\Rules\UserBurstRule;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One detection run (`launchpad:detect-abuse`, every five minutes).
 *
 * The scan is stateless: products whose launch day (UTC) is yesterday or today and that have a vote, in chunks of 50; then
 * their voters in chunks of 500; then the two run-level rules (login churn, coordinated votes). A launch day is the only day
 * a vote can be cast, so this covers every vote that can still change a ranking, and a missed run heals itself.
 *
 * Each chunk's findings are written in their own short transaction (`SignalWriter`) and the chunk's counters are merged
 * into the run only after that commit. A chunk (or run rule) that throws is reported and counted as failed and the loop
 * goes on; the cursor lives outside the loop body. The clock is read once.
 *
 * After the rules: the scoring pass (`users.risk_score`), then the flagging pass (`counted` <-> `flagged` votes, which both
 * count, so no counter, bucket or ranking moves).
 *
 * A dry run executes the whole pipeline inside one outer transaction that is rolled back at the end, so the counts
 * (including the coordinated-votes rule, which reads the signals this run wrote) are real and nothing persists. It sets a
 * local lock timeout, because that outer transaction keeps its key-share locks across chunks.
 */
final class DetectAbuse
{
    public const PRODUCT_CHUNK = 50;

    public const USER_CHUNK = 500;

    public function __construct(
        private readonly Settings $settings,
        private readonly SignalWriter $writer,
        private readonly ScoreUsers $scoring,
        private readonly FlagVotes $flagging,
    ) {}

    public function handle(?CarbonImmutable $now = null, bool $dryRun = false, int $productChunk = self::PRODUCT_CHUNK, int $userChunk = self::USER_CHUNK): DetectionResult
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();
        $config = RuleConfig::fromSettings($this->settings);
        $result = new DetectionResult;

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            if ($dryRun) {
                DB::statement("SET LOCAL lock_timeout = '10s'");
            }

            $this->run($config, $now, $result, $productChunk, $userChunk);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return $result;
    }

    private function run(RuleConfig $config, CarbonImmutable $now, DetectionResult $result, int $productChunk, int $userChunk): void
    {
        /** @var list<Rule> $productRules */
        $productRules = [app(SharedDeviceRule::class), app(IpClusterRule::class), app(ProductBurstRule::class)];
        /** @var list<Rule> $userRules */
        $userRules = [app(UserBurstRule::class), app(FreshAccountRule::class), app(DisposableEmailRule::class), app(SingleMakerVoterRule::class)];
        /** @var list<Rule> $runRules */
        $runRules = [app(LoginChurnRule::class), app(CoordinatedVotesRule::class)];

        $voters = [];
        $cursor = '';
        $since = $now->subDay()->toDateString();

        while (true) {
            $ids = $this->nextProducts($since, $cursor, $productChunk);

            if ($ids === []) {
                break;
            }

            // Advance before the work: a chunk that keeps failing must not be fetched again.
            $cursor = end($ids);

            try {
                // The reads run in a savepoint (a transaction of their own in production): a statement error inside a rule
                // must not poison a surrounding transaction, which a dry run and the test suite both have.
                $chunkVoters = DB::transaction(fn (): array => array_map(fn (object $row) => $row->user_id, Sql::select(
                    'SELECT DISTINCT v.user_id FROM votes v WHERE v.product_id IN ('.Sql::placeholders($ids).') AND '.Sql::activeVote('v'),
                    $ids,
                )));
                // The voters join the user-bound phase as soon as they are known, so a rule or write failure of this chunk
                // does not hide them from the user rules (those only depend on the voters, not on this chunk's findings).
                foreach ($chunkVoters as $id) {
                    $voters[$id] = true;
                }

                $findings = DB::transaction(fn (): array => $this->findings($productRules, $config, Scan::products($now, $ids)));
                $counts = $this->writer->write($findings, $now);
            } catch (Throwable $e) {
                $this->failed($result, $e);

                continue;
            }

            // Only now, after the chunk's transaction committed.
            $result->addSignals($counts);
            $result->touch($this->userIds($findings));
        }

        $voterIds = array_keys($voters);
        sort($voterIds);

        foreach (array_chunk($voterIds, max(1, $userChunk)) as $chunk) {
            $this->chunk($result, $now, $userRules, $config, Scan::users($now, $chunk));
        }

        // Last, and in this order: the coordinated-votes rule reads the signals the others just committed.
        foreach ($runRules as $rule) {
            $this->chunk($result, $now, [$rule], $config, Scan::run($now, $result->touchedUserIds()));
        }

        $this->rescore($result, $now, $config, $userChunk);

        $flag = $this->flagging->handle($now);
        $result->flagged = $flag->flagged;
        $result->unflagged = $flag->unflagged;
        $result->failedChunks += $flag->failedChunks;
    }

    /**
     * The scoring pass: every member this run named plus every member who still has a score (so decay lowers scores
     * without a separate job). A chunk that throws is reported and counted; the others still update.
     */
    private function rescore(DetectionResult $result, CarbonImmutable $now, RuleConfig $config, int $userChunk): void
    {
        $ids = array_fill_keys($result->touchedUserIds(), true);

        foreach (Sql::select('SELECT id FROM users WHERE risk_score > 0 AND deleted_at IS NULL') as $row) {
            $ids[$row->id] = true;
        }

        $ids = array_keys($ids);
        sort($ids);

        foreach (array_chunk($ids, max(1, min($userChunk, ScoreUsers::CHUNK))) as $chunk) {
            try {
                $result->usersRescored += $this->scoring->handle($chunk, $now, $config->decayDays);
            } catch (Throwable $e) {
                $this->failed($result, $e);
            }
        }
    }

    /**
     * @param  list<Rule>  $rules
     */
    private function chunk(DetectionResult $result, CarbonImmutable $now, array $rules, RuleConfig $config, Scan $scan): void
    {
        try {
            $findings = DB::transaction(fn (): array => $this->findings($rules, $config, $scan));
            $counts = $this->writer->write($findings, $now);
        } catch (Throwable $e) {
            $this->failed($result, $e);

            return;
        }

        $result->addSignals($counts);
        $result->touch($this->userIds($findings));
    }

    /**
     * @param  list<Rule>  $rules
     * @return list<Finding>
     */
    private function findings(array $rules, RuleConfig $config, Scan $scan): array
    {
        $findings = [];

        foreach ($rules as $rule) {
            foreach ($rule->detect($config, $scan) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private function userIds(array $findings): array
    {
        return array_values(array_unique(array_filter(array_map(fn (Finding $f) => $f->userId, $findings))));
    }

    /**
     * The next chunk of scan-set product ids after the cursor: launched yesterday or today (UTC), soft-deleted included,
     * with at least one vote.
     *
     * @return list<string>
     */
    private function nextProducts(string $since, string $cursor, int $limit): array
    {
        return array_map(fn (object $row) => $row->id, Sql::select(
            'SELECT p.id FROM products p WHERE p.launch_date >= ? AND p.id > ? AND EXISTS (SELECT 1 FROM votes v WHERE v.product_id = p.id) ORDER BY p.id LIMIT '.max(1, $limit),
            [$since, $cursor],
        ));
    }

    private function failed(DetectionResult $result, Throwable $e): void
    {
        $result->failedChunks++;
        report($e);
    }
}
