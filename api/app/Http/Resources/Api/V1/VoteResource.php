<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain array, not a model; the stdClass mixin below only tells Scramble there is no model to infer.
 *
 * @mixin \stdClass
 * @property array{voted: bool, votes_count: int} $resource
 */
class VoteResource extends JsonResource
{
    /** @return array{voted: bool, votes_count: int} */
    public function toArray(Request $request): array
    {
        return ['voted' => $this->resource['voted'], 'votes_count' => $this->resource['votes_count']];
    }
}
