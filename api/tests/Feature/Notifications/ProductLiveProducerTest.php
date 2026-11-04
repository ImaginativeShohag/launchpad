<?php

use App\Actions\Product\PublishLaunches;
use App\Enums\ProductStatus;
use App\Events\ProductWentLive;
use App\Listeners\NotifyProductLive;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/MailFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:05 UTC')));

it('notifies the maker when the scheduler makes the product live, in the bell and by mail', function () {
    // Given a scheduled product due today (real listeners)
    $maker = User::factory()->create();
    $product = Product::factory()->scheduled('2026-10-20')->create(['maker_id' => $maker->id, 'name' => 'Acme', 'slug' => 'acme']);

    // When the launches are published
    app(PublishLaunches::class)->handle();

    // Then one bell row and one mail exist, with the launch day in their keys
    [$bell, $mail] = producerRows();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(1);
    expect($bell[0]['user_id'])->toBe($maker->id)->and($bell[0]['type'])->toBe('product_live')->and($bell[0]['dedupe_key'])->toBe('product_live:'.$product->id.':2026-10-20');
    expect(json_decode($bell[0]['data'], true))->toEqual([
        'message_key' => 'notifications.product_live', 'product_id' => $product->id, 'params' => ['product_name' => 'Acme'], 'href' => '/products/acme',
    ]);
    expect($mail[0]['type'])->toBe('product_live')->and($mail[0]['idempotency_key'])->toBe('product_live:'.$product->id.':2026-10-20:'.$maker->id);
    expect(json_decode($mail[0]['payload'], true))->toEqual(['product_id' => $product->id, 'launch_date' => '2026-10-20']);
});

it('writes nothing when the product was taken down before the job ran', function () {
    // Given a product that went live and was taken down again before the listener ran
    $product = Product::factory()->live('2026-10-20')->create();
    DB::table('products')->where('id', $product->id)->update(['status' => ProductStatus::TakenDown->value, 'taken_down_at' => now(), 'takedown_reason' => 'x']);

    // When the listener runs
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));

    // Then nothing is written
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});

it('leaves one row each for a repeated event and a re-run listener', function () {
    // Given a live product that was announced once
    $product = Product::factory()->live('2026-10-20')->create();
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));

    // When the event is dispatched twice more
    ProductWentLive::dispatch($product->id);
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));

    // Then there is still one bell row and one mail
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 1]);
});

it('creates no second row or mail when a product is taken down and restored on the same launch day', function () {
    // Given a live product that was announced, then taken down and restored (live again, same launch date)
    $product = Product::factory()->live('2026-10-20')->create();
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));
    DB::table('products')->where('id', $product->id)->update(['status' => 'taken_down']);
    DB::table('products')->where('id', $product->id)->update(['status' => 'live']);

    // When it goes live again and the listener runs
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));

    // Then nothing new was written
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 1]);
});

it('does not announce a launch that is still scheduled', function () {
    // Given a scheduled product
    $product = Product::factory()->scheduled('2026-10-25')->create();

    // When the listener runs for it
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));

    // Then nothing is written
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});
