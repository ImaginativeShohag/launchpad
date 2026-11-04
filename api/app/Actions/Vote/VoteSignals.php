<?php

namespace App\Actions\Vote;

use App\Support\DeviceHash;
use Illuminate\Http\Request;

/** What a request tells us about where a vote came from. Only keyed hashes and a truncated user agent, never raw values. */
final readonly class VoteSignals
{
    private const MAX_USER_AGENT = 255;

    public function __construct(public ?string $ipHash, public ?string $deviceHash, public ?string $userAgent) {}

    public static function fromRequest(Request $request): self
    {
        $agent = $request->userAgent();

        return new self(
            DeviceHash::ip($request),
            DeviceHash::device($request),
            is_string($agent) && $agent !== '' ? mb_substr($agent, 0, self::MAX_USER_AGENT) : null,
        );
    }
}
