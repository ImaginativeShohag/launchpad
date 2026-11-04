<?php

namespace App\Actions\Views;

use App\Support\DeviceHash;
use Illuminate\Http\Request;

/** What the beacon request tells us, as keyed hashes only: no raw IP, device id or full referrer ever leaves this object. */
final readonly class ViewSignals
{
    public function __construct(public string $deviceHash, public string $ipHash, public ?string $userAgent, public ?string $referrer) {}

    public static function fromRequest(Request $request, ?string $referrer): self
    {
        // DeviceId gives every request an id, so the device hash is always present; `none` keeps the IP key well formed
        // when the address is unknown (all such requests then share one cap, the safe direction).
        return new self(
            DeviceHash::device($request) ?? 'none',
            DeviceHash::viewIp($request) ?? 'none',
            $request->userAgent(),
            $referrer,
        );
    }
}
