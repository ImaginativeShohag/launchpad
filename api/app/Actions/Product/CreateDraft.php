<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateDraft
{
    /**
     * Share-locks the maker's user row and re-checks it, so a draft can never appear for an account that
     * DeleteAccount anonymised (it locks the user row exclusively) or that was suspended since the request
     * passed the middleware. Only the user row is locked here, so the global order (products, admins, user) holds.
     *
     * @param  array<string, mixed>  $data  validated input: `name` and `slug` strings
     *
     * @throws ProductRuleViolation 409 `maker_unavailable` when the maker is deleted or suspended
     */
    public function handle(User $maker, array $data): Product
    {
        try {
            // A transaction even for one insert: it is a savepoint inside an outer transaction, so the
            // catch below can still query after the failed INSERT (otherwise SQLSTATE 25P02).
            return DB::transaction(function () use ($maker, $data): Product {
                $active = User::query()->whereKey($maker->getKey())->whereNull('deleted_at')->whereNull('suspended_at')
                    ->sharedLock()->exists();

                if (! $active) {
                    throw ProductRuleViolation::makerUnavailable();
                }

                $product = new Product(['name' => $data['name'], 'slug' => $data['slug']]);
                $product->forceFill([
                    'maker_id' => $maker->getKey(),
                    'status' => ProductStatus::Draft,
                    'tagline' => null, 'description' => null, 'website_url' => null, 'logo_path' => null,
                    'launch_date' => null, 'submitted_at' => null, 'approved_at' => null, 'launched_at' => null,
                    'taken_down_at' => null, 'taken_down_by' => null, 'takedown_reason' => null,
                    'votes_count' => 0, 'comments_count' => 0, 'hot_score' => 0.0,
                ])->save();

                return $product;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Only a slug clash is the member's mistake; re-query instead of reading the driver message (L4).
            if (Product::withTrashed()->where('slug', $data['slug'])->exists()) {
                throw ValidationException::withMessages(['slug' => ['This slug is already taken.']]);
            }

            throw $e;
        }
    }
}
