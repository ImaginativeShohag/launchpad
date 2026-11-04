<?php

namespace App\Actions\Export;

use App\Models\Export;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** The maker's view of their exports: the latest five of a product, or one of them. Plain reads; takes no lock. */
final class ReadExports
{
    public const LATEST = 5;

    public function __construct(private readonly ExportAccess $access) {}

    /** @return Collection<int, Export> */
    public function latest(User $actor, string $productId): Collection
    {
        $product = $this->access->product($actor, $productId);

        // Served by exports_product_created_index (product_id, created_at DESC, id DESC).
        return Export::query()->where('product_id', $product->getKey())->where('user_id', $actor->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::LATEST)->get();
    }

    public function one(User $actor, string $productId, string $exportId): Export
    {
        return $this->access->export($actor, $productId, $exportId);
    }
}
