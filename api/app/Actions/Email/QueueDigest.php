<?php

namespace App\Actions\Email;

use App\Actions\Ranking\Leaderboard;
use App\Enums\EmailType;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The daily top five (every morning, about the UTC day that just ended) and the weekly best (Mondays, about the Monday to
 * Sunday week that just ended), queued into the outbox for every eligible member: verified, not suspended, not deleted,
 * not opted out of that type. All roles.
 *
 * The products are picked once per (kind, period) and stored in `digest_runs`, so every member gets the same list and a
 * resumed run keeps it. Recipients are read in chunks of 500 by user id: each chunk is ONE transaction that locks the
 * run row `FOR UPDATE`, reads the users `FOR SHARE` in id order (a concurrent account deletion finishes first and the
 * deleted member is skipped), inserts the outbox rows (`ON CONFLICT DO NOTHING`, the key holds the period and user) and
 * moves the cursor. A crash resumes at the cursor; a finished run is a single cheap lookup. No model is hydrated and no
 * in-site notification is written for a digest.
 *
 * Lock order: `digest_runs` row, then user rows (id order). No product row is ever held.
 *
 * An unfinished run is resumed by the next tick for RESUME_HOURS (18) after it started, even past midnight (its period
 * stays its own; the mail is re-filtered at send time). An older one is abandoned: closed, logged and counted as a
 * failure (non-zero exit). The clock is read once per run; the age check re-reads it only between chunks.
 */
class QueueDigest
{
    public const CHUNK = 500;

    /** Hour (UTC) from which an unfrozen day counts as "will stay empty" and is closed with nothing to send. */
    public const GIVE_UP_HOUR = 23;

    public const PRODUCTS = 5;

    /** An open run younger than this (by started_at) is resumed by the next tick, even after midnight; an older one is abandoned. */
    public const RESUME_HOURS = 18;

    /** How many kinds threw in the last run (each kind runs in its own try/catch). */
    public int $failed = 0;

    /** @var list<string> the kinds that threw in the last run */
    public array $failedKinds = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Leaderboard $leaderboard,
        private readonly int $chunk = self::CHUNK,
    ) {}

    /**
     * @return array{daily_top5: DigestResult|null, weekly_best: DigestResult|null} null = not in its window (or it failed)
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $this->failed = 0;
        $this->failedKinds = [];
        $results = ['daily_top5' => null, 'weekly_best' => null];

        foreach (['daily_top5', 'weekly_best'] as $kind) {
            try {
                // An open run of an earlier period (a crash near midnight) is finished first, by age, not by send day.
                $current = $kind === 'daily_top5' ? $now->subDay()->toDateString() : $now->subDays(7)->toDateString();
                // One lookup serves both: the open runs of the kind and the current period's run (finished or not).
                $known = DB::table('digest_runs')->where('kind', $kind)->where(fn ($q) => $q->whereNull('completed_at')->orWhere('period_date', $current))->get();
                $older = $this->resumeOlder($kind, $now, $current, $known);
                $currentRun = $known->first(fn (\stdClass $r) => (string) $r->period_date === $current);
                $today = $kind === 'daily_top5' ? $this->daily($now, $currentRun) : $this->weekly($now, $currentRun);
                // A resumed older run is reported unless today's period has real news of its own.
                $results[$kind] = $today !== null && $today->state !== 'waiting' ? $today : ($older ?? $today);
            } catch (Throwable $e) {
                $this->failed++;
                $this->failedKinds[] = $kind;
                report($e);
            }
        }

        return $results;
    }

    private function daily(CarbonImmutable $now, ?\stdClass $existing): ?DigestResult
    {
        if ($now->lt($this->at($now, SettingKey::DigestDailySendAtUtc))) {
            return null;
        }

        $period = $now->subDay()->toDateString();

        return $this->run('daily_top5', $period, $now, $existing, fn (): ?array => $this->dailyProducts($period, $now));
    }

    private function weekly(CarbonImmutable $now, ?\stdClass $existing): ?DigestResult
    {
        if (! $now->isMonday() || $now->lt($this->at($now, SettingKey::DigestWeeklySendAtUtc))) {
            return null;
        }

        $period = $now->subDays(7)->toDateString();
        $sunday = $now->subDay()->startOfDay();

        return $this->run('weekly_best', $period, $now, $existing, fn (): array => array_values($this->leaderboard
            ->ranking('week', null, $sunday, self::PRODUCTS, $sunday->toDateString())->pluck('id')->map(fn ($id) => (string) $id)->all()));
    }

    private function at(CarbonImmutable $now, SettingKey $key): CarbonImmutable
    {
        return CarbonImmutable::parse($now->toDateString().' '.$this->settings->timeOfDay($key), 'UTC');
    }

    /**
     * The visible products of the frozen day in rank order, at most five. Hidden ones are filtered BEFORE taking five
     * (frozen ranks are not dense once a product is taken down). Null = the day is not frozen yet and may still be.
     *
     * @return list<string>|null
     */
    protected function dailyProducts(string $period, CarbonImmutable $now): ?array
    {
        $ranked = DB::table('daily_rankings')->where('launch_date', $period)->orderBy('rank')->pluck('product_id')->map(fn ($id) => (string) $id)->all();

        if ($ranked === []) {
            // The freeze job runs every minute; an empty day that is still empty late in the send day stays empty.
            return $now->hour < self::GIVE_UP_HOUR ? null : [];
        }

        $visible = [];
        foreach (array_chunk($ranked, 1000) as $ids) {
            foreach (Product::query()->visible()->whereMakerAvailable()->whereIn('id', $ids)->pluck('id') as $id) {
                $visible[(string) $id] = true;
            }
        }

        return array_slice(array_values(array_filter($ranked, fn (string $id) => isset($visible[$id]))), 0, self::PRODUCTS);
    }

    /** @param  callable(): (list<string>|null)  $pick */
    private function run(string $kind, string $period, CarbonImmutable $now, ?\stdClass $existing, callable $pick): DigestResult
    {
        if ($existing !== null && $existing->completed_at !== null) {
            return new DigestResult($kind, $period, (int) $existing->enqueued, true, 'already_done');
        }

        $resumed = $existing !== null;

        if ($existing === null) {
            $products = $pick();

            if ($products === null) {
                return new DigestResult($kind, $period, 0, false, 'waiting');
            }

            $stamp = $now->format('Y-m-d H:i:s.uP');
            // A racing runner may insert first: its list wins (re-read below), so every member sees one list.
            DB::table('digest_runs')->insertOrIgnore([
                'kind' => $kind, 'period_date' => $period, 'product_ids' => json_encode($products, JSON_THROW_ON_ERROR), 'last_user_id' => null,
                'enqueued' => 0, 'started_at' => $stamp, 'completed_at' => $products === [] ? $stamp : null,
            ]);
            $existing = $this->find($kind, $period);

            if ($existing === null || $existing->completed_at !== null) {
                return new DigestResult($kind, $period, 0, true, $products === [] ? 'completed' : 'already_done');
            }
        }

        return $this->drain($kind, $period, $now, $resumed, $existing);
    }

    /**
     * Finishes every open run of $kind whose period is not the current one. Runs older than RESUME_HOURS were abandoned.
     */
    /** @param  Collection<int, \stdClass>  $known */
    private function resumeOlder(string $kind, CarbonImmutable $now, string $current, Collection $known): ?DigestResult
    {
        $result = null;
        $open = $known->filter(fn (\stdClass $r) => $r->completed_at === null && (string) $r->period_date !== $current)->sortBy('period_date');

        foreach ($open as $run) {
            $result = $this->drain($kind, (string) $run->period_date, $now, true, $run);
        }

        return $result;
    }

    /**
     * The chunk loop of an open run. A run younger than RESUME_HOURS (by `started_at`) is resumed whatever the date; an older
     * one is abandoned: closed, logged and counted as a failure so the command exits non-zero. The first check uses the
     * run's own clock reading; the clock is read again only between chunks.
     */
    private function drain(string $kind, string $period, CarbonImmutable $now, bool $resumed, \stdClass $run): DigestResult
    {
        $startedAt = CarbonImmutable::parse((string) $run->started_at);
        $clock = $now;
        $enqueued = 0;
        $type = $kind === 'daily_top5' ? EmailType::DailyTop5 : EmailType::WeeklyBest;

        while (true) {
            if ($clock->getTimestamp() - $startedAt->getTimestamp() > self::RESUME_HOURS * 3600) {
                DB::table('digest_runs')->where('kind', $kind)->where('period_date', $period)->whereNull('completed_at')->update(['completed_at' => $clock->format('Y-m-d H:i:s.uP')]);
                Log::warning('Digest abandoned: the run is too old to resume.', ['kind' => $kind, 'period' => $period]);
                $this->failed++;
                $this->failedKinds[] = $kind;

                return new DigestResult($kind, $period, $enqueued, false, 'abandoned');
            }

            [$inserted, $finished] = $this->chunk($kind, $period, $type);
            $enqueued += $inserted;

            if ($finished) {
                $final = $this->find($kind, $period);
                $total = $final === null ? $enqueued : (int) $final->enqueued;

                return new DigestResult($kind, $period, $total, true, $resumed ? 'resumed' : 'completed');
            }

            $this->afterChunk($kind, $period);
            $clock = CarbonImmutable::now('UTC');
        }
    }

    /**
     * Called after each committed chunk of an unfinished run. A no-op; the tests hook it to stop a run between chunks.
     */
    protected function afterChunk(string $kind, string $period): void {}

    /**
     * One chunk, one transaction.
     *
     * @return array{0: int, 1: bool} rows inserted, run finished
     */
    private function chunk(string $kind, string $period, EmailType $type): array
    {
        return DB::transaction(function () use ($kind, $period, $type): array {
            $run = DB::selectOne('SELECT product_ids, last_user_id, completed_at FROM digest_runs WHERE kind = ? AND period_date = ? FOR UPDATE', [$kind, $period]);

            if ($run === null || $run->completed_at !== null) {
                return [0, true];
            }

            /** @var list<string> $productIds */
            $productIds = json_decode((string) $run->product_ids, true, 512, JSON_THROW_ON_ERROR);
            $payloadKey = $kind === 'daily_top5' ? 'date' : 'week_start';
            $payload = json_encode([$payloadKey => $period, 'product_ids' => $productIds], JSON_THROW_ON_ERROR);

            // Eligible recipients after the cursor, locked for share in id order: a concurrent DeleteAccount (user row FOR UPDATE)
            // finishes first, and the deleted member no longer matches.
            $users = DB::select(
                'SELECT u.id, u.email FROM users u
                 WHERE u.id > ? AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL AND u.deleted_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM email_preferences p WHERE p.user_id = u.id AND p.type = ? AND p.enabled = false)
                 ORDER BY u.id LIMIT ? FOR SHARE OF u',
                [(string) ($run->last_user_id ?? ''), $type->value, $this->chunk],
            );

            $stamp = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
            $rows = [];
            foreach ($users as $user) {
                $rows[] = [
                    'id' => strtolower((string) Str::ulid()), 'user_id' => $user->id, 'type' => $type->value,
                    'idempotency_key' => $kind.':'.$period.':'.$user->id, 'to_email' => $user->email, 'payload' => $payload,
                    'status' => 'queued', 'attempts' => 0, 'next_attempt_at' => $stamp, 'last_error' => null, 'provider_message_id' => null,
                    'sent_at' => null, 'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }

            $inserted = $rows === [] ? 0 : DB::table('email_messages')->insertOrIgnore($rows);
            $finished = count($users) < $this->chunk;

            DB::update(
                'UPDATE digest_runs SET last_user_id = ?, enqueued = enqueued + ?, completed_at = ? WHERE kind = ? AND period_date = ?',
                [$users === [] ? $run->last_user_id : end($users)->id, $inserted, $finished ? $stamp : null, $kind, $period],
            );

            return [$inserted, $finished];
        });
    }

    private function find(string $kind, string $period): ?\stdClass
    {
        return DB::table('digest_runs')->where('kind', $kind)->where('period_date', $period)->first();
    }
}
