<?php

use App\Actions\Review\DecideReview;
use App\Enums\ReviewDecision;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('lists the member\'s own products in every status, newest first, and nobody else\'s', function () {
    // Given a member with products in several statuses, a deleted draft, and another member's product
    $maker = signedInMember();
    $first = Product::factory()->complete()->create(['maker_id' => $maker->id, 'created_at' => now()->subDays(3)]);
    $second = Product::factory()->pending()->create(['maker_id' => $maker->id, 'created_at' => now()->subDays(2)]);
    $third = Product::factory()->takenDown()->create(['maker_id' => $maker->id, 'created_at' => now()->subDay()]);
    Product::factory()->create(['maker_id' => $maker->id, 'deleted_at' => now()]);
    Product::factory()->live()->create();

    // When the list is read
    $response = $this->getJson('/api/v1/me/products');

    // Then they see exactly their three, newest first, with the founder keys and no private-by-accident data
    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$third->id, $second->id, $first->id]);
    expect($response->json('data.0.status'))->toBe('taken_down')->and($response->json('data.0.takedown_reason'))->toBe('Test reason');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('filters by status and refuses unknown or wrong-shaped filters', function () {
    // Given a member with a draft and a pending product
    $maker = signedInMember();
    Product::factory()->complete()->create(['maker_id' => $maker->id]);
    $pending = Product::factory()->pending()->create(['maker_id' => $maker->id]);

    // When filtered by status
    $response = $this->getJson('/api/v1/me/products?status=pending');

    // Then only that status is listed
    expect($response->json('data.*.id'))->toBe([$pending->id]);

    // And hostile filters are 422
    foreach (['status=wizard', 'status[]=pending', 'status[a]=b', 'status=', 'cursor=bnVsbA', 'cursor[]=x', 'per_page=0', 'per_page=101', 'per_page[]=1'] as $query) {
        $r = $this->getJson('/api/v1/me/products?'.$query);
        expect($r->status())->toBe(422);
    }
});

it('pages by keyset without skipping or repeating products created at the same instant', function () {
    // Given five products, three created at one instant
    $maker = signedInMember();
    $tie = now()->subDays(2)->startOfSecond();
    foreach ([$tie, $tie, $tie, now()->subDay(), now()->subHour()] as $at) {
        Product::factory()->create(['maker_id' => $maker->id, 'created_at' => $at]);
    }

    // When read two at a time until the end
    $seen = [];
    $cursor = null;
    do {
        $page = $this->getJson('/api/v1/me/products?per_page=2'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $seen = array_merge($seen, $page->json('data.*.id'));
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    // Then each appears exactly once
    expect($seen)->toHaveCount(5)->and(collect($seen)->unique()->count())->toBe(5);
});

it('has an index that matches the ordering', function () {
    // Given the migrated schema
    // When the plan is read with sequential scans, bitmap scans and sorts disabled
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $plan = collect(DB::select("EXPLAIN select * from products where maker_id = '01JZZZZZZZZZZZZZZZZZZZZZZZ' and deleted_at is null order by created_at desc, id desc limit 26"))
        ->pluck('QUERY PLAN')->implode("\n");

    // Then Postgres can use products_maker_id_created_at_id_index
    expect($plan)->toContain('products_maker_id_created_at_id_index');
});

it('shows one own product in full, with the review notes but not the reviewer', function () {
    // Given a changes_requested product with a review note
    $maker = signedInMember();
    $product = Product::factory()->changesRequested()->create(['maker_id' => $maker->id]);
    $reviewer = User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_one']);
    ProductReview::factory()->create(['product_id' => $product->id, 'reviewer_id' => $reviewer->id, 'decision' => 'changes_requested', 'note' => 'Please add screenshots']);

    // When the maker opens it
    $response = $this->getJson("/api/v1/me/products/{$product->id}");

    // Then they see the note and the decision, the screenshots and what is editable, and not who reviewed
    $response->assertOk()->assertJsonPath('data.reviews.0.note', 'Please add screenshots')->assertJsonPath('data.reviews.0.decision', 'changes_requested')
        ->assertJsonPath('data.media_editable', true)->assertJsonCount(1, 'data.screenshots');
    expect($response->getContent())->not->toContain('rev_one');
});

it('answers another member, a deleted draft and an unknown id with the identical 404', function () {
    // Given another member's product and a deleted draft of the signed-in member
    $maker = signedInMember();
    $foreign = Product::factory()->pending()->create();
    $deleted = Product::factory()->create(['maker_id' => $maker->id, 'deleted_at' => now()]);

    // When they ask for them and for an unknown id
    // Then all are the same 404
    foreach ([$foreign->id, $deleted->id, '01JZZZZZZZZZZZZZZZZZZZZZZZ'] as $id) {
        $this->getJson("/api/v1/me/products/{$id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given a product
    $product = Product::factory()->create();

    // When nobody, an unconfirmed member and a suspended member ask
    $this->getJson('/api/v1/me/products')->assertUnauthorized();
    $this->getJson("/api/v1/me/products/{$product->id}")->assertUnauthorized();
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->getJson('/api/v1/me/products')->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->getJson('/api/v1/me/products')->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('flags only the latest changes_requested or rejected decision of the current submission as current', function () {
    // Given a changes_requested product with an old decision, an old approval-free history and a newer decision after submitted_at
    $maker = signedInMember();
    $product = Product::factory()->changesRequested()->create(['maker_id' => $maker->id, 'submitted_at' => now()->subHour()]);
    $old = ProductReview::factory()->create(['product_id' => $product->id, 'decision' => 'changes_requested', 'note' => 'old', 'created_at' => now()->subDays(2)]);
    $first = ProductReview::factory()->create(['product_id' => $product->id, 'decision' => 'rejected', 'note' => 'first', 'created_at' => now()->subMinutes(30)]);
    $latest = ProductReview::factory()->create(['product_id' => $product->id, 'decision' => 'changes_requested', 'note' => 'latest', 'created_at' => now()->subMinutes(10)]);

    // When the founder opens it
    $response = $this->getJson("/api/v1/me/products/{$product->id}")->assertOk();

    // Then the whole history is returned oldest first, and only the latest one is current
    expect($response->json('data.reviews.*.id'))->toBe([$old->id, $first->id, $latest->id]);
    expect($response->json('data.reviews.*.current'))->toBe([false, false, true]);
});

it('flags nothing as current while the product is pending again', function () {
    // Given a resubmitted product whose only decision belongs to the earlier submission
    $maker = signedInMember();
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id, 'submitted_at' => now()->subMinute()]);
    ProductReview::factory()->create(['product_id' => $product->id, 'decision' => 'changes_requested', 'note' => 'earlier', 'created_at' => now()->subHour()]);

    // When the founder opens it
    $response = $this->getJson("/api/v1/me/products/{$product->id}")->assertOk();

    // Then no review is current
    expect($response->json('data.reviews.*.current'))->toBe([false]);
});

it('answers odd product ids on me/products/{id} with 404, never 500', function (string $id) {
    // Given a signed-in member
    signedInMember();

    // When an id that is not a ULID is asked for
    $response = $this->getJson('/api/v1/me/products/'.$id);

    // Then it is a 404
    $response->assertNotFound();
})->with(['text' => 'abc', 'nul' => 'a%00b', 'long' => str_repeat('A', 300), 'quote' => '%27--']);

it('marks the current decision when it was made in the same second as the submission, through the real review path', function () {
    // Given a maker who submits, and a reviewer who rejects in the same instant
    $maker = signedInMember();
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id, 'submitted_at' => now()->startOfSecond()->addMicroseconds(400000)]);
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    app(DecideReview::class)->handle($reviewer, $product, ReviewDecision::Rejected, 'Not a fit', $product->fresh()->submitted_at->toIso8601ZuluString('microsecond'));

    // When the maker opens the product
    $response = $this->getJson("/api/v1/me/products/{$product->id}");

    // Then the rejection is flagged current
    expect($response->json('data.reviews.*.current'))->toBe([true]);
});

it('adds topics, screenshots, counters and the latest decision to the list, keeping the existing keys', function () {
    // Given a live product with counters, a changes_requested product with two decisions, and a draft
    $maker = signedInMember();
    $live = Product::factory()->live()->create(['maker_id' => $maker->id, 'votes_count' => 7, 'comments_count' => 3, 'created_at' => now()->subDays(3)]);
    $changes = Product::factory()->changesRequested()->create(['maker_id' => $maker->id, 'created_at' => now()->subDays(2)]);
    ProductReview::factory()->create(['product_id' => $changes->id, 'decision' => 'changes_requested', 'note' => 'older', 'created_at' => now()->subDays(2)]);
    ProductReview::factory()->create(['product_id' => $changes->id, 'decision' => 'changes_requested', 'note' => 'Add a screenshot', 'created_at' => now()->subHour()]);
    ProductReview::factory()->create(['product_id' => $changes->id, 'decision' => 'approved', 'note' => 'ignored', 'created_at' => now()]);
    $draft = Product::factory()->complete()->create(['maker_id' => $maker->id, 'created_at' => now()->subDay()]);

    // When the list is read
    $rows = collect($this->getJson('/api/v1/me/products')->assertOk()->json('data'))->keyBy('id');

    // Then the live card has counters, the changes card has the latest matching decision, the draft has neither
    expect($rows[$live->id])->toHaveKeys(['id', 'slug', 'status', 'editable_fields', 'topics', 'screenshots', 'votes_count', 'comments_count'])->not->toHaveKey('latest_review');
    expect($rows[$live->id]['votes_count'])->toBe(7)->and($rows[$live->id]['comments_count'])->toBe(3);
    expect($rows[$changes->id]['latest_review'])->toMatchArray(['decision' => 'changes_requested', 'note' => 'Add a screenshot'])->toHaveKeys(['decided_at']);
    expect($rows[$changes->id])->not->toHaveKey('votes_count');
    expect($rows[$draft->id])->not->toHaveKey('latest_review')->not->toHaveKey('votes_count');
});

it('runs a constant number of queries however long the list is', function () {
    // Given a member with three products of mixed status, each with a topic and a screenshot
    $maker = signedInMember();
    $make = function (string $state) use ($maker): void {
        $p = Product::factory()->{$state}()->create(['maker_id' => $maker->id]);
        if ($state !== 'live') {
            ProductReview::factory()->create(['product_id' => $p->id, 'decision' => $state === 'rejected' ? 'rejected' : 'changes_requested', 'note' => 'n']);
        }
    };
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/api/v1/me/products')->assertOk();

        return count(DB::getQueryLog());
    };
    foreach (['live', 'rejected', 'changesRequested'] as $state) {
        $make($state);
    }
    $small = $count();

    // When many more products exist
    foreach (['live', 'rejected', 'changesRequested', 'rejected', 'changesRequested', 'live'] as $state) {
        $make($state);
    }

    // Then the query count does not grow
    expect($count())->toBe($small);
});
