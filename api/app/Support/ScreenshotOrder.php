<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * unique(product_id, position) is not deferrable, so positions cannot be swapped in place. Move every row far out
 * of the way first (positions are always below 1000), then write 0..n-1. The caller holds the product row lock.
 */
final class ScreenshotOrder
{
    /** @param  list<string>  $orderedIds  every screenshot id of the product, in the wanted order */
    public static function apply(Product $locked, array $orderedIds): void
    {
        DB::table('product_screenshots')->where('product_id', $locked->getKey())->update(['position' => DB::raw('position + 1000')]);

        foreach ($orderedIds as $position => $id) {
            DB::table('product_screenshots')->where('product_id', $locked->getKey())->where('id', $id)->update(['position' => $position]);
        }
    }
}
