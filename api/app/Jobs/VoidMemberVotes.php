<?php

namespace App\Jobs;

use App\Actions\Vote\VoidVote;
use App\Exceptions\ProductRuleViolation;
use App\Models\Vote;
use Illuminate\Bus\UniqueLock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Voids a member's votes on launch days that are not frozen yet. Two callers, both AFTER their transaction committed:
 * `DeleteAccount` (reason `account_deleted`, the default) and `SuspendForAbuse` (reason `suspended`, only when staff asked
 * for it). Voiding needs other makers' product rows, and taking them while the caller holds the member's user row would
 * invert the lock order against CastVote (voting spec section 6). Idempotent, so a retry only finishes what is left.
 * Production needs an async queue and a running worker.
 *
 * The guard follows the reason, so a wrongly dispatched job never voids a live member's votes: `account_deleted` needs a
 * deleted account, `suspended` a suspended one (a member restored before the job ran is skipped). The hourly sweep
 * (`launchpad:sweep-deleted-member-votes`) stays deleted-only: a suspended member's votes keep counting unless staff
 * asked for the void, and a lost dispatch is repaired by calling the staff endpoint again.
 *
 * The class was `VoidDeletedMemberVotes`. A serialized job names its class, so payloads queued before the rename still
 * load through the deprecated subclass of that name, and they lack `reason`: it is a plain property with a default for
 * exactly that reason (do not make it readonly or promoted).
 */
class VoidMemberVotes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const REASON_DELETED = 'account_deleted';

    public const REASON_SUSPENDED = 'suspended';

    public const CHUNK = 100;

    public int $tries = 3;

    /** Below `retry_after` (90 s) so a hung run is not started twice; the unique lock expires if a worker dies. */
    public int $timeout = 60;

    public int $uniqueFor = 3600;

    /** Not promoted and not readonly on purpose: a payload queued before the rename has no such property and keeps this default. */
    public string $reason = self::REASON_DELETED;

    public function __construct(public readonly string $userId, string $reason = self::REASON_DELETED)
    {
        $this->reason = $reason;
    }

    /**
     * Dispatches the job without ever throwing. A failed push leaves the ShouldBeUnique lock behind (it is taken before the
     * push, for `$uniqueFor`), which would make every repair dispatch a silent no-op for an hour, so the lock is released
     * here. The failure is reported.
     *
     * @return bool false when the dispatch failed
     */
    public static function dispatchSafely(string $userId, string $reason = self::REASON_DELETED): bool
    {
        $job = new self($userId, $reason);

        try {
            dispatch($job);

            return true;
        } catch (Throwable $e) {
            report($e);

            try {
                (new UniqueLock(Container::getInstance()->make(Cache::class)))->release($job);
            } catch (Throwable $inner) {
                report($inner); // the cache itself is down: the lock expires with its TTL
            }

            return false;
        }
    }

    public function uniqueId(): string
    {
        return $this->reason.':'.$this->userId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(VoidVote $void, int $chunk = self::CHUNK): void
    {
        if (! $this->applies()) {
            return;
        }

        $today = Date::now('UTC')->toDateString();
        $afterId = '';
        $failure = null;

        do {
            $ids = Vote::query()
                ->where('user_id', $this->userId)
                ->active()
                ->whereIn('product_id', fn ($products) => $products->select('id')->from('products')->where('launch_date', '>=', $today))
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            foreach ($ids as $id) {
                try {
                    // VoidVote runs in its own transaction: one failed statement cannot poison the others. No actor: the
                    // deletion or the suspension (audited with its admin) is the cause.
                    $void->handle((string) $id, $this->reason);
                } catch (Throwable $e) {
                    if ($e instanceof ProductRuleViolation && $e->errorCode === 'day_frozen') {
                        continue; // the day froze meanwhile: it is final
                    }

                    report($e);
                    $failure ??= $e;
                }
            }

            $afterId = (string) $ids->last();
        } while ($ids->count() === $chunk);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** The account must be in the state the reason names; any other reason does nothing. */
    private function applies(): bool
    {
        $column = match ($this->reason) {
            self::REASON_DELETED => 'deleted_at',
            self::REASON_SUSPENDED => 'suspended_at',
            default => null,
        };

        return $column !== null && DB::table('users')->where('id', $this->userId)->whereNotNull($column)->exists();
    }
}
