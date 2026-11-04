<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

/** A page of public product cards with votes_count and comments_count, and has_voted when the viewer is known. */
class ProductCardCollection extends ResourceCollection
{
    public $collects = ProductResource::class;

    /** @param  array<string, bool>|null  $voted  product id => true; null for a guest (the key is omitted) */
    public function __construct($resource, private readonly ?array $voted)
    {
        parent::__construct($resource);
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, ProductResource> $cards */
        $cards = $this->collection;

        return $cards
            ->map(fn (ProductResource $card): array => $card->withVotes($this->voted === null ? null : isset($this->voted[$card->id]))->toArray($request))
            ->all();
    }
}
