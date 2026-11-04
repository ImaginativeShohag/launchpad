<?php

namespace App\Rules;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Format (3 to 80), reserved words, and uniqueness across all products, soft-deleted ones included. */
class ProductSlug implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreProductId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! SlugFormat::matches($value, 3, 80)) {
            $fail('The :attribute must be 3 to 80 lowercase letters, numbers and single hyphens.');

            return;
        }

        // `deleted-` is how a deleted draft frees its slug (D3); nobody may squat the prefix.
        if (in_array($value, (array) config('launchpad.reserved_product_slugs'), true) || str_starts_with($value, 'deleted-')) {
            $fail('This :attribute is reserved.');

            return;
        }

        $taken = Product::withTrashed()
            ->where('slug', $value)
            ->when($this->ignoreProductId !== null, fn ($query) => $query->whereKeyNot($this->ignoreProductId))
            ->exists();

        if ($taken) {
            $fail('This :attribute is already taken.');
        }
    }
}
