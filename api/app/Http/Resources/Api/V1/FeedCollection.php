<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

/** A page of the following feed: per-product state (has_voted, reasons) is known for the whole page up front. */
class FeedCollection extends ResourceCollection
{
    public $collects = FeedProductResource::class;

    /**
     * @param  array<string, bool>  $voted  product id => true
     * @param  array<string, list<array<string, mixed>>>  $reasons  product id => reasons
     */
    public function __construct($resource, private readonly array $voted, private readonly array $reasons)
    {
        parent::__construct($resource);
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, FeedProductResource> $cards */
        $cards = $this->collection;

        return $cards
            ->map(fn (FeedProductResource $card) => $card->feedFor(isset($this->voted[$card->id]), $this->reasons[$card->id] ?? [])->toArray($request))
            ->all();
    }
}
