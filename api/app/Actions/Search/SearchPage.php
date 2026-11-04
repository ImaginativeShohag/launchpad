<?php

namespace App\Actions\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/** One page of search results and the cursor of the next one (null on the last page). */
final class SearchPage
{
    /** @param  Collection<int, Product>  $products */
    public function __construct(public readonly Collection $products, public readonly ?string $nextCursor) {}
}
