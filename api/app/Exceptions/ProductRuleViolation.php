<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** A business rule refused the request. Renders as JSON with a stable `code` the clients can switch on; an expected refusal, so it is not reported as an error. */
class ProductRuleViolation extends RuntimeException implements ShouldntReport
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function topicInUse(): self
    {
        return new self('This topic still has products.', 'topic_in_use', 409);
    }

    public static function invalidStatus(): self
    {
        return new self('This product cannot do that in its current status.', 'invalid_status', 409);
    }

    public static function makerUnavailable(): self
    {
        return new self('The maker of this product is suspended or deleted.', 'maker_unavailable', 409);
    }

    public static function changedMeanwhile(): self
    {
        return new self('Something changed while this was running. Try again.', 'try_again', 409);
    }

    public static function locked(): self
    {
        return new self('This product can no longer be changed that way.', 'product_locked', 403);
    }

    public static function staleSubmission(): self
    {
        return new self('This product was changed or resubmitted since you opened it. Reload it and review again.', 'stale_submission', 409);
    }

    public static function pendingLimit(): self
    {
        return new self('You have too many products waiting for review. Wait for a review or withdraw one first.', 'pending_limit', 409);
    }

    public static function ownProduct(string $verb = 'review'): self
    {
        return new self("You cannot {$verb} your own product.", 'own_product', 403);
    }

    public static function votingClosed(): self
    {
        return new self('Voting is only open on the launch day.', 'voting_closed', 409);
    }

    public static function dayFrozen(): self
    {
        return new self('This launch day is frozen and its votes can no longer change.', 'day_frozen', 409);
    }

    public static function hasActiveProduct(): self
    {
        return new self('Withdraw or finish your products before deleting your account.', 'has_active_product', 403);
    }

    /**
     * A refused launch day: the usual 422 `{message, errors: {launch_date: [...]}}` plus a stable top-level `code`
     * (launch_date_invalid, launch_date_today, launch_date_out_of_window, launch_cap_reached) the web app can switch on.
     */
    public static function launchDate(string $code, string $message): ValidationException
    {
        $exception = ValidationException::withMessages(['launch_date' => [$message]]);
        $exception->response = response()->json(['message' => $message, 'code' => $code, 'errors' => ['launch_date' => [$message]]], 422);

        return $exception;
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
