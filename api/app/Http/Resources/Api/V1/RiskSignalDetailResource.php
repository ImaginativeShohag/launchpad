<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RiskSignal;
use Illuminate\Http\Request;

/**
 * A signal with its stored `details` and the evidence resolved from them (members, votes with fingerprints, product).
 *
 * @mixin RiskSignal
 */
class RiskSignalDetailResource extends RiskSignalResource
{
    /** @var array<string, mixed> */
    private array $evidence = [];

    /** @param  array<string, mixed>  $evidence */
    public function withEvidence(array $evidence): static
    {
        $this->evidence = $evidence;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'details' => $this->details, 'evidence' => $this->evidence];
    }
}
