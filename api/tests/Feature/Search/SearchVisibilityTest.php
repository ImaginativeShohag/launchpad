<?php

use App\Actions\Product\RestoreProduct;
use App\Enums\SearchSort;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('never returns a product that is not public, in any sort or topic filter', function (string $label) {
    // Given one public control, and a product in this non-public state that carries the searched word and a topic
    searchLive('Quokka Control');
    [$build, $status] = searchHiddenStates()[$label];
    $hidden = $build();
    expect(DB::table('products')->where('id', $hidden->id)->value('status'))->toBe($status);

    // When every sort is searched, with and without the topic
    // Then only the control is ever returned
    foreach ([SearchSort::Relevance, SearchSort::Votes, SearchSort::Newest] as $sort) {
        expect(searchNames('quokka', null, $sort))->toBe(['Quokka Control'], "{$label} {$sort->value}");
        expect(searchNames('quokka', 'hidden-topic', $sort))->toBe([], "{$label} {$sort->value} topic");
    }
    expect(searchNames(null, 'hidden-topic'))->toBe([]);
})->with(array_keys(searchHiddenStates()));

it('keeps the products of a suspended maker', function () {
    // Given a product of a suspended maker
    searchLive('Quokka Suspended', null, null, ['maker_id' => User::factory()->suspended()->create()->id]);

    // When searched
    // Then it is present (as in the leaderboard)
    expect(searchNames('quokka'))->toBe(['Quokka Suspended']);
});

it('drops a taken-down product and finds it again once restored, with no reindex', function () {
    // Given a live product
    $product = searchLive('Quokka Tool');
    expect(searchNames('quokka'))->toBe(['Quokka Tool']);

    // When it is taken down
    $product->forceFill(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'test'])->save();
    $gone = searchNames('quokka');

    // And restored
    $product->forceFill(['status' => 'live', 'taken_down_at' => null, 'takedown_reason' => null])->save();

    // Then it was absent, and is present again
    expect($gone)->toBe([])->and(searchNames('quokka'))->toBe(['Quokka Tool']);
});

it('restores through the real action too', function () {
    // Given a taken-down product
    $product = Product::factory()->takenDown()->create(['name' => 'Quokka Tool', 'tagline' => null, 'description' => null]);
    expect(searchNames('quokka'))->toBe([]);

    // When the staff action restores it
    app(RestoreProduct::class)->handle(User::factory()->create(['role' => 'admin']), $product);

    // Then it is searchable at once
    expect(searchNames('quokka'))->toBe(['Quokka Tool']);
});

it('finds a new description word at once and stops finding a removed one', function () {
    // Given a live product with a description word
    $product = searchLive('Plain Name', null, 'mentions the okapi');
    expect(searchNames('okapi'))->toBe(['Plain Name']);

    // When the description is edited after launch
    $product->forceFill(['description' => 'mentions the quokka'])->save();

    // Then the new word matches and the removed one no longer does
    expect(searchNames('quokka'))->toBe(['Plain Name'])->and(searchNames('okapi'))->toBe([]);
});

it('answers a hidden product exactly like a nonsense query', function () {
    // Given a hidden product
    $product = Product::factory()->pending()->create(['name' => 'Quokka Secret']);

    // When its exact name and nonsense are searched
    $hidden = searchRun('quokka secret');
    $nonsense = searchRun('zzxqvbn');

    // Then both are the same empty page
    expect($hidden->products)->toHaveCount(0)->and($hidden->nextCursor)->toBeNull()
        ->and($nonsense->products)->toHaveCount(0)->and($nonsense->nextCursor)->toBeNull();
    expect($product->exists)->toBeTrue();
});

it('calls the two public scopes and takes no lock and writes nothing', function () {
    // Given a live product
    searchLive('Quokka Tool');

    // When a search of each sort runs
    $sql = searchCaptureSql(function () {
        foreach ([SearchSort::Relevance, SearchSort::Votes, SearchSort::Newest] as $sort) {
            searchRun('quokka', null, $sort);
        }
    });

    // Then no statement locks or writes, and every product read carries the visibility predicates
    $text = strtolower(implode("\n", $sql));
    expect($text)->not->toContain('for update')->not->toContain('for share')->not->toContain('for key share')
        ->not->toMatch('/\b(insert into|update "|delete from)\b/');
    $reads = array_filter($sql, fn (string $statement) => str_contains($statement, 'from "products"') || str_contains($statement, ') as "products"'));
    expect($reads)->not->toBe([]);
    foreach ($reads as $statement) {
        expect($statement)->toContain('"status" = \'live\'')->toContain('"launch_date" <= ')->toContain('exists (select * from "users" where "products"."maker_id" = "users"."id" and "deleted_at" is null)')->toContain('"products"."deleted_at" is null');
    }
});
