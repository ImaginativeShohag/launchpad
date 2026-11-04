<?php

namespace App\Actions\AntiCheat;

use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Which product a staff anti-cheat read is about. The id is a plain string (route-model binding would run before the
 * guards): the staff role first (403, the same for an existing and an unknown id), then the lookup (404 for a non-ULID or
 * missing id), then the policy (a draft is the maker's private work: the same 404 as `GET staff/products/{id}`).
 *
 * A soft-deleted product stays readable once it has left the draft stage: its votes are evidence. Read-only; takes no lock.
 */
final class StaffProductLookup
{
    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function find(User $caller, string $productId): Product
    {
        if (! Gate::forUser($caller)->allows('review', RiskSignal::class)) {
            throw new AuthorizationException;
        }

        if (! Str::isUlid($productId)) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        $product = Product::withTrashed()->findOrFail($productId);
        Gate::forUser($caller)->authorize('viewStaff', $product);

        return $product;
    }
}
