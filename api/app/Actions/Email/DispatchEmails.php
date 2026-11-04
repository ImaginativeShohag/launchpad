<?php

namespace App\Actions\Email;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * One run of the outbox: claim a batch, send each row, repeat until nothing is due, the time budget ends or the provider
 * looks down (the breaker). Stopping early gives the unsent claims back with their attempts, so an outage does not burn the
 * retry budget of mail it never tried.
 */
class DispatchEmails
{
    /** A bound on the number of claim rounds, so a clock that never advances can not loop for ever. */
    private const MAX_ROUNDS = 10000;

    public function __construct(private readonly ClaimDueEmails $claim, private readonly SendEmailMessage $send) {}

    /** @return array{sent: int, retried: int, suppressed: int, failed: int, exhausted: int, transient_failures: int, stopped: null|'breaker'|'budget'} */
    public function handle(?CarbonImmutable $startedAt = null): array
    {
        $start = $startedAt ?? Date::now()->toImmutable();
        $deadline = $start->addSeconds((int) config('launchpad.outbox.budget_seconds'));
        $batch = (int) config('launchpad.outbox.batch');
        $breaker = (int) config('launchpad.outbox.breaker');

        $result = ['sent' => 0, 'retried' => 0, 'suppressed' => 0, 'failed' => 0, 'exhausted' => 0, 'transient_failures' => 0, 'stopped' => null];
        $consecutive = 0;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $claims = $this->claim->handle(Date::now()->toImmutable(), $batch);

            // Lease-expired rows at their last attempt are failed by the claim; they count as failed and are surfaced.
            $result['failed'] += $this->claim->exhausted;
            $result['exhausted'] += $this->claim->exhausted;

            if ($claims === []) {
                if ($this->claim->exhausted > 0) {
                    continue; // more due rows may sit behind the ones just failed
                }

                break;
            }

            foreach ($claims as $index => $claim) {
                if (Date::now()->greaterThanOrEqualTo($deadline)) {
                    $this->claim->release(array_slice($claims, $index), Date::now()->toImmutable());
                    $result['stopped'] = 'budget';

                    break 2;
                }

                try {
                    $sent = $this->send->handle($claim['id'], $claim['attempts'], Date::now()->toImmutable());
                } catch (Throwable $e) {
                    // The database failed while recording: the row stays `sending` and is retried after its lease.
                    report($e);
                    $sent = new SendResult('retried', transientFailure: true);
                }

                if (isset($result[$sent->outcome])) {
                    $result[$sent->outcome]++;
                }

                if ($sent->transientFailure) {
                    $result['transient_failures']++;
                    $consecutive++;
                } elseif ($sent->outcome === 'sent') {
                    $consecutive = 0;
                }

                if ($consecutive >= $breaker) {
                    $this->claim->release(array_slice($claims, $index + 1), Date::now()->toImmutable());
                    $result['stopped'] = 'breaker';

                    break 2;
                }
            }
        }

        return $result;
    }
}
