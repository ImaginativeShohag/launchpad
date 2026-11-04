<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** An export rule refused the request (not ready yet, or gone). Renders as JSON with a stable `code`; an expected refusal, so it is not reported as an error. */
class ExportRuleViolation extends RuntimeException implements ShouldntReport
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notReady(): self
    {
        return new self('This export is not ready to download.', 'export_not_ready', 409);
    }

    public static function expired(): self
    {
        return new self('This export has expired. Start a new one.', 'export_expired', 410);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
