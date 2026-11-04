<?php

namespace App\Actions\Dashboard;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Who may read a product's dashboard, and which product it is. The id is a plain string (route-model binding would run
 * before the guards and before this check). It runs inside authorize() of the dashboard requests, so a stranger gets the
 * uniform 404 whatever the query parameters are.
 *
 * `me/` prefix: the maker of a non-deleted product, else 404 (a reviewer or admin is simply not the maker).
 * `staff/` prefix: the staff role first (403, identical for an existing and an unknown id), then the lookup (404), then the
 * policy (a draft is the maker's private work: 404). Read-only; takes no lock.
 */
final class DashboardAccess
{
    /**
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     */
    public function forRequest(Request $request, User $caller, string $productId): Product
    {
        if ($this->isStaffRoute($request)) {
            if (! Gate::forUser($caller)->allows('review', Product::class)) {
                throw new AuthorizationException;
            }

            $product = $this->find($productId);
            Gate::forUser($caller)->authorize('viewStaff', $product);

            return $product;
        }

        $product = $this->find($productId);

        if ($product->maker_id !== $caller->getKey()) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        return $product;
    }

    /** The staff mirror is told apart by the matched route's own URI, not by the request path (which a proxy prefix could change). */
    private function isStaffRoute(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->uri(), 'api/v1/staff/');
    }

    private function find(string $productId): Product
    {
        if (! Str::isUlid($productId)) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        // Soft-deleted products are excluded by the model.
        return Product::query()->findOrFail($productId);
    }
}
