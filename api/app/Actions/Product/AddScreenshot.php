<?php

namespace App\Actions\Product;

use App\Actions\Media\StoreImage;
use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Enums\SettingKey;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\ProductScreenshot;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AddScreenshot
{
    use LocksOwnedProduct;

    public function __construct(private readonly StoreImage $images, private readonly Settings $settings) {}

    public function handle(User $actor, Product $product, UploadedFile $file, ?string $altText): ProductScreenshot
    {
        if (! ProductPolicy::mediaEditable($product->status)) {
            throw ProductRuleViolation::locked();
        }

        // Cheap unlocked pre-check so a full product does not pay for a decode; re-checked under the lock below.
        $max = (int) $this->settings->get(SettingKey::ProductMaxScreenshots);

        if ($product->screenshots()->count() >= $max) {
            throw ValidationException::withMessages(['screenshot' => ["A product can have at most {$max} screenshots."]]);
        }

        $disk = (string) config('launchpad.product_disk');
        $stored = $this->images->store($file, $disk, 'products/screenshots', 'screenshot', fitWithin: 1600);

        try {
            return DB::transaction(function () use ($actor, $product, $stored, $altText, $max): ProductScreenshot {
                $locked = $this->lockOwned($actor, $product);

                if (! ProductPolicy::mediaEditable($locked->status)) {
                    throw ProductRuleViolation::locked();
                }

                if ($locked->screenshots()->count() >= $max) {
                    throw ValidationException::withMessages(['screenshot' => ["A product can have at most {$max} screenshots."]]);
                }

                $position = $locked->screenshots()->max('position');

                return $locked->screenshots()->create([
                    'path' => $stored->path,
                    'position' => $position === null ? 0 : ((int) $position) + 1,
                    'width' => $stored->width,
                    'height' => $stored->height,
                    'alt_text' => $altText,
                ]);
            });
        } catch (Throwable $e) {
            $this->images->discard($disk, $stored->path);

            throw $e;
        }
    }
}
