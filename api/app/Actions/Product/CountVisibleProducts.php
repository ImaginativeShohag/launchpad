<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\User;

/** The member's publicly listed products: the same filter as GET /users/{handle}/products, one COUNT on the maker index. */
class CountVisibleProducts
{
    public function handle(User $user): int
    {
        return Product::visible()->where('maker_id', $user->getKey())->whereNotNull('launched_at')->count();
    }
}
