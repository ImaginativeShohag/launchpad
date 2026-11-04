<?php

namespace App\Actions\Product;

use App\Actions\Media\StoreImage;
use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Enums\ProductStatus;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteDraft
{
    use LocksOwnedProduct;

    public function __construct(private readonly StoreImage $images) {}

    /**
     * Soft-deletes a draft. The slug is rewritten to `deleted-<ulid>` so the founder can reuse it (D3); review
     * history stays (plain foreign key, product row kept). Files go only after the commit.
     *
     * @throws ProductRuleViolation unless the product is a draft
     */
    public function handle(User $actor, Product $product): void
    {
        $paths = DB::transaction(function () use ($actor, $product): array {
            $locked = $this->lockOwned($actor, $product);

            if ($locked->status !== ProductStatus::Draft) {
                throw ProductRuleViolation::invalidStatus();
            }

            $paths = $locked->screenshots()->pluck('path')->all();
            if ($locked->logo_path !== null) {
                $paths[] = $locked->logo_path;
            }

            $locked->screenshots()->delete();
            $locked->links()->delete();
            $locked->topics()->detach();
            $locked->forceFill(['slug' => 'deleted-'.strtolower((string) $locked->getKey()), 'logo_path' => null])->save();
            $locked->delete();

            return $paths;
        });

        foreach ($paths as $path) {
            $this->images->discard((string) config('launchpad.product_disk'), $path);
        }
    }
}
