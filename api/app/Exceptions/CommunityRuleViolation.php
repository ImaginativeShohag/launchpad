<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A community rule (comments, follows, reports, suspension) refused the request. Renders as JSON with a stable `code`; an expected refusal, so it is not reported as an error. */
class CommunityRuleViolation extends RuntimeException implements ShouldntReport
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function editWindowClosed(): self
    {
        return new self('This comment can no longer be edited.', 'edit_window_closed', 409);
    }

    public static function ownContent(): self
    {
        return new self('You cannot report your own content.', 'own_content', 403);
    }

    public static function selfFollow(): self
    {
        return new self('You cannot follow yourself.', 'self_follow', 403);
    }

    public static function memberUnavailable(): self
    {
        return new self('This member cannot be followed right now.', 'member_unavailable', 409);
    }

    public static function alreadyReviewed(): self
    {
        return new self('A report of yours on this was already reviewed.', 'already_reviewed', 409);
    }

    public static function reportLimit(): self
    {
        return new self('You have too many open reports. Wait for some to be reviewed.', 'report_limit', 409);
    }

    public static function cannotSuspend(): self
    {
        return new self('This account cannot be suspended.', 'cannot_suspend', 403);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
