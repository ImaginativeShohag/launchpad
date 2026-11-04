<?php

namespace App\Actions\Product;

use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Enums\ProductStatus;
use App\Enums\SettingKey;
use App\Events\ProductSubmitted;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitProduct
{
    use LocksOwnedProduct;

    public function __construct(private readonly Settings $settings) {}

    /**
     * draft or changes_requested -> pending. The status and the counts are read on the locked row.
     *
     * Global lock order (L8), step 1: the maker's whole product set, in id order, so two parallel submits queue here and
     * the second one counts the first one's committed row (the pending cap cannot be exceeded). Precedence of refusals:
     * invalid status, then missing fields, then the pending cap.
     *
     * @throws ProductRuleViolation from any other status, or when the maker is at the pending cap
     * @throws ValidationException listing every missing field
     */
    public function handle(User $actor, Product $product): Product
    {
        DB::transaction(function () use ($actor, $product): void {
            Product::query()->where('maker_id', $actor->getKey())->orderBy('id')->lockForUpdate()->pluck('id');
            $locked = $this->lockOwned($actor, $product);
            $from = $locked->status;
            $locked->transitionTo(ProductStatus::Pending);

            $missing = $this->missing($locked);

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            $this->assertUnderPendingCap($actor, $locked);

            $locked->forceFill(['submitted_at' => now(), 'approved_at' => null])->save();
            Audit::record('product.submitted', $actor, $locked, ['from_status' => $from->value]);
            ProductSubmitted::dispatch((string) $locked->getKey(), (string) $actor->getKey());

            $product->setRawAttributes($locked->getAttributes(), true);
        });

        return $product;
    }

    /** The maker's other pending products; a withdrawn or decided product no longer counts, so its slot is free. */
    private function assertUnderPendingCap(User $actor, Product $locked): void
    {
        $cap = (int) $this->settings->get(SettingKey::ProductMaxPendingPerMaker);

        $pending = Product::query()
            ->where('maker_id', $actor->getKey())
            ->whereKeyNot($locked->getKey())
            ->where('status', ProductStatus::Pending->value)
            ->count();

        if ($pending >= $cap) {
            throw ProductRuleViolation::pendingLimit();
        }
    }

    /** @return array<string, list<string>> field => messages, for every gap (name and slug are never null) */
    private function missing(Product $locked): array
    {
        $missing = [];

        if (blank($locked->tagline)) {
            $missing['tagline'] = ['Add a one-line tagline.'];
        }
        if (blank($locked->description)) {
            $missing['description'] = ['Add a description.'];
        }
        if (blank($locked->website_url)) {
            $missing['website_url'] = ['Add the product website.'];
        }
        if (blank($locked->logo_path)) {
            $missing['logo'] = ['Upload a logo.'];
        }
        if (! $locked->screenshots()->exists()) {
            $missing['screenshots'] = ['Add at least one screenshot.'];
        }

        $topics = $locked->topics()->count();
        $max = (int) $this->settings->get(SettingKey::ProductMaxTopics);

        if ($topics < 1) {
            $missing['topic_ids'] = ['Pick at least one topic.'];
        } elseif ($topics > $max) {
            $missing['topic_ids'] = ["Pick at most {$max} topics."];
        }

        return $missing;
    }
}
