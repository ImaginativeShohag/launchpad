<?php

use App\Models\Product;
use App\Models\Topic;

it('returns only the products linked to the topic', function () {
    // Given two topics and three products, one in each topic and one in none
    $ai = Topic::factory()->create(['slug' => 'ai']);
    $web = Topic::factory()->create(['slug' => 'web']);
    $inAi = Product::factory()->live()->create();
    $inWeb = Product::factory()->live()->create();
    Product::factory()->live()->create();
    searchTopicLink($inAi, $ai);
    searchTopicLink($inWeb, $web);

    // When the scope is applied
    $ids = Product::query()->hasTopicSlug('ai')->pluck('id')->all();

    // Then only the product in that topic comes back
    expect($ids)->toBe([$inAi->id]);
});

it('lists a product in two topics once and finds nothing for an unknown slug', function () {
    // Given a product in two topics
    $one = Topic::factory()->create(['slug' => 'one']);
    $two = Topic::factory()->create(['slug' => 'two']);
    $product = Product::factory()->live()->create();
    searchTopicLink($product, $one);
    searchTopicLink($product, $two);

    // When either topic, and an unknown slug, are applied
    // Then it appears once per topic filter and never for a missing slug
    expect(Product::query()->hasTopicSlug('one')->pluck('id')->all())->toBe([$product->id])
        ->and(Product::query()->hasTopicSlug('two')->pluck('id')->all())->toBe([$product->id])
        ->and(Product::query()->hasTopicSlug('nope')->pluck('id')->all())->toBe([]);
});

it('uses a product_topics sub-select, not a join', function () {
    // Given the scope applied to a query
    // When its SQL is read
    $sql = Product::query()->hasTopicSlug('ai')->toSql();

    // Then it is the semi-join the leaderboard walks, so the row count never multiplies
    expect($sql)->toContain('"products"."id" in (select "product_topics"."product_id" from "product_topics" inner join "topics"')
        ->and($sql)->not->toContain('left join');
});
