<?php

use App\Models\Product;

it('counts ordered relations without a GROUP BY error', function () {
    // Given a product with a screenshot, a link and a topic
    $product = Product::factory()->complete()->create();

    // When the counts are taken the way the screenshot cap does it
    $screenshots = $product->screenshots()->count();
    $links = $product->links()->count();
    $topics = $product->topics()->count();

    // Then Postgres accepts them
    expect($screenshots)->toBeInt()->and($links)->toBeInt()->and($topics)->toBeInt();
});
