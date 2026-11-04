<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** An anti-cheat rule refused a staff action. Renders as JSON with a stable `code`; an expected refusal, so it is not reported as an error. */
class AntiCheatRuleViolation extends RuntimeException implements ShouldntReport
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    /** Nobody reviews a signal about their own account. */
    public static function ownSignal(): self
    {
        return new self('You cannot review a signal about your own account.', 'own_signal', 403);
    }

    /** A reviewer may not judge a signal about a reviewer or an admin. */
    public static function adminOnlySignal(): self
    {
        return new self('Only an admin can review a signal about a staff member.', 'admin_only_signal', 403);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
