<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The search statement ran into its time cap (SQLSTATE 57014). Expected under load, so it is not reported; the client retries. */
final class SearchTimedOut extends RuntimeException implements ShouldntReport
{
    public const RETRY_AFTER_SECONDS = 2;

    public static function make(): self
    {
        return new self('The search took too long. Try again in a moment.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'search_timeout'], 503, ['Retry-After' => (string) self::RETRY_AFTER_SECONDS]);
    }
}
