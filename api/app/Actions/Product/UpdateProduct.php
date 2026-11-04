<?php

namespace App\Actions\Product;

use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Enums\ProductStatus;
use App\Enums\SettingKey;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\ProductLink;
use App\Models\Topic;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProduct
{
    use LocksOwnedProduct;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Saves any subset of the editable fields. The status is read from the locked row, so a request that raced a
     * submit or a review cannot write to a product that has just been locked. Edits of a live product are audited
     * in the same transaction: if the audit cannot be written, the edit does not happen.
     *
     * @param  array<string, mixed>  $data  validated input; only the keys present are changed
     *
     * @throws ProductRuleViolation when no field may be edited in the current status
     * @throws ValidationException when a field is not editable in the current status, or a value is not allowed
     */
    public function handle(User $actor, Product $product, array $data): Product
    {
        try {
            return DB::transaction(function () use ($actor, $product, $data): Product {
                $locked = $this->lockOwned($actor, $product);
                $editable = ProductPolicy::editableFields($locked->status);

                if ($editable === []) {
                    throw ProductRuleViolation::locked();
                }

                $this->assertEditable($locked, $editable, $data);

                // Only live products are audited: their changes are public at once.
                $before = $locked->status === ProductStatus::Live ? $this->snapshot($locked) : null;

                $locked->fill(Arr::only($data, ['name', 'slug', 'tagline', 'description', 'website_url']))->save();

                if (array_key_exists('links', $data)) {
                    $this->replaceLinks($locked, array_values((array) $data['links']));
                }
                if (array_key_exists('topic_ids', $data)) {
                    $this->syncTopics($locked, array_values((array) $data['topic_ids']));
                }
                if (array_key_exists('screenshots', $data)) {
                    $this->updateAltTexts($locked, array_values((array) $data['screenshots']));
                }

                if ($before !== null) {
                    $this->auditEditAfterLaunch($actor, $locked, $before);
                }

                $product->setRawAttributes($locked->getAttributes(), true);

                return $product;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (isset($data['slug']) && is_string($data['slug'])
                && Product::withTrashed()->where('slug', $data['slug'])->whereKeyNot($product->getKey())->exists()) {
                throw ValidationException::withMessages(['slug' => ['This slug is already taken.']]);
            }

            throw $e;
        }
    }

    /**
     * @param  list<string>  $editable
     * @param  array<string, mixed>  $data
     */
    private function assertEditable(Product $locked, array $editable, array $data): void
    {
        $messages = [];

        foreach (array_diff(array_keys($data), $editable) as $field) {
            $messages[$field] = ['This field cannot be changed while the product is '.$locked->status->value.'.'];
        }

        if ($locked->status === ProductStatus::Live) {
            foreach (['description', 'website_url'] as $field) {
                if (array_key_exists($field, $data) && blank($data[$field])) {
                    $messages[$field] = ['A live product needs a '.str_replace('_', ' ', $field).'.'];
                }
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * The values the audit compares: the product's own public content, never member data (decision D7).
     * Links compare by kind and url in order, because their ids change on every save.
     *
     * @return array{description: ?string, website_url: ?string, links: list<array{kind: string, url: string}>}
     */
    private function snapshot(Product $locked): array
    {
        return [
            'description' => $locked->description,
            'website_url' => $locked->website_url,
            'links' => array_values($locked->links()->get()
                ->map(fn (ProductLink $link) => ['kind' => $link->kind->value, 'url' => $link->url])
                ->all()),
        ];
    }

    /**
     * `product.edited_after_launch` with before/after for the fields that changed. Written in the same transaction
     * and not swallowed: if the audit cannot be written, the edit does not happen.
     *
     * @param  array{description: ?string, website_url: ?string, links: list<array{kind: string, url: string}>}  $before
     */
    private function auditEditAfterLaunch(User $actor, Product $locked, array $before): void
    {
        $after = $this->snapshot($locked);
        $fields = array_keys(array_filter($after, fn (mixed $value, string $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

        if ($fields === []) {
            return;
        }

        Audit::record('product.edited_after_launch', $actor, $locked, [
            'product_id' => $locked->getKey(),
            'fields' => $fields,
            'before' => Arr::only($before, $fields),
            'after' => Arr::only($after, $fields),
        ]);
    }

    /**
     * unique(product_id, position) is not deferrable: delete everything, then insert 0..n-1.
     *
     * @param  list<array<string, mixed>>  $links
     */
    private function replaceLinks(Product $locked, array $links): void
    {
        $locked->links()->delete();

        foreach ($links as $position => $link) {
            $locked->links()->create(['kind' => $link['kind'], 'url' => $link['url'], 'position' => $position]);
        }
    }

    /** @param  list<string>  $ids */
    private function syncTopics(Product $locked, array $ids): void
    {
        $max = (int) $this->settings->get(SettingKey::ProductMaxTopics);

        if (count($ids) > $max) {
            throw ValidationException::withMessages(['topic_ids' => ["Pick at most {$max} topics."]]);
        }

        if ($ids !== []) {
            // Share-lock the topics in id order: DeleteTopic takes the topic row FOR UPDATE, so an attach and a
            // delete cannot interleave and leave a foreign-key error behind.
            $found = Topic::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->pluck('id');

            if ($found->count() !== count($ids)) {
                throw ValidationException::withMessages(['topic_ids' => ['One or more topics do not exist.']]);
            }
        }

        $locked->topics()->sync($ids);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function updateAltTexts(Product $locked, array $items): void
    {
        foreach ($items as $index => $item) {
            // An item without `alt_text` only confirms the screenshot; it must not wipe the stored text.
            $query = $locked->screenshots()->whereKey($item['id']);
            $updated = array_key_exists('alt_text', $item) ? $query->update(['alt_text' => $item['alt_text']]) : $query->count();

            if ($updated === 0) {
                throw ValidationException::withMessages(["screenshots.{$index}.id" => ['This screenshot does not belong to the product.']]);
            }
        }
    }
}
