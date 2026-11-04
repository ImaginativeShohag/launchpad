<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('lists pending products, oldest submission first, and nothing else', function () {
    // Given pending products submitted at different times, plus products in other statuses
    signedInStaff();
    $newer = Product::factory()->pending()->create(['submitted_at' => now()->subHour()]);
    $older = Product::factory()->pending()->create(['submitted_at' => now()->subDay()]);
    Product::factory()->complete()->create();
    Product::factory()->approved()->create();
    Product::factory()->live()->create();
    Product::factory()->pending()->create(['deleted_at' => now()]);

    // When the queue is read
    $response = $this->getJson('/api/v1/staff/review-queue');

    // Then only the two pending ones appear, oldest first, with the documented keys
    $response->assertOk()->assertJsonCount(2, 'data');
    expect($response->json('data.*.id'))->toBe([$older->id, $newer->id]);
    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'status', 'launch_date', 'submitted_at',
        'approved_at', 'launched_at', 'taken_down_at', 'takedown_reason', 'maker', 'topics',
    ]);
    expect(array_keys($response->json('data.0.maker')))->toBe(['id', 'handle', 'name']);
});

it('pages with a keyset cursor without skipping or repeating rows that share a timestamp', function () {
    // Given five pending products, three of which share one submitted_at
    signedInStaff();
    $tie = now()->subHours(3)->startOfSecond();
    $ids = collect([
        Product::factory()->pending()->create(['submitted_at' => $tie]),
        Product::factory()->pending()->create(['submitted_at' => $tie]),
        Product::factory()->pending()->create(['submitted_at' => $tie]),
        Product::factory()->pending()->create(['submitted_at' => now()->subHours(2)]),
        Product::factory()->pending()->create(['submitted_at' => now()->subHour()]),
    ])->pluck('id');

    // When the queue is read two at a time until it ends
    $seen = [];
    $cursor = null;
    do {
        $page = $this->getJson('/api/v1/staff/review-queue?per_page=2'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $seen = array_merge($seen, $page->json('data.*.id'));
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    // Then every product appears exactly once
    expect($seen)->toHaveCount(5)->and(collect($seen)->unique()->count())->toBe(5)->and(collect($seen)->sort()->values()->all())->toBe($ids->sort()->values()->all());
});

it('treats an empty cursor as the first page', function () {
    // Given a pending product
    signedInStaff();
    Product::factory()->pending()->create();

    // When the cursor is present but empty
    $response = $this->getJson('/api/v1/staff/review-queue?cursor=');

    // Then it is simply page one
    $response->assertOk()->assertJsonCount(1, 'data');
});

it('has an index that matches the ordering', function () {
    // Given the migrated schema
    // When the plan for the queue query is read with the alternatives to an ordered index scan disabled (with only
    // sequential scans off, the planner may still pick a bitmap scan plus a sort once the table statistics change)
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');
    DB::statement('SET LOCAL enable_sort = off');
    $plan = collect(DB::select("EXPLAIN select * from products where status = 'pending' order by submitted_at asc, id asc limit 26"))
        ->pluck('QUERY PLAN')->implode("\n");

    // Then Postgres can use products_status_submitted_at_id_index
    expect($plan)->toContain('products_status_submitted_at_id_index');
});

it('refuses tampered cursors and bad page sizes with 422', function (string $query) {
    // Given a reviewer
    signedInStaff();

    // When the query string is hostile
    $response = $this->getJson('/api/v1/staff/review-queue?'.$query);

    // Then it is a 422, never a 500
    $response->assertUnprocessable();
})->with([
    'null cursor' => ['cursor=bnVsbA'], 'garbage cursor' => ['cursor=!!!'], 'array cursor' => ['cursor[]=x'],
    'object cursor' => ['cursor[a]=b'], 'per_page zero' => ['per_page=0'], 'per_page huge' => ['per_page=101'],
    'per_page text' => ['per_page=abc'], 'per_page array' => ['per_page[]=1'], 'per_page object' => ['per_page[a]=1'],
]);

it('is for reviewers and admins only', function () {
    // Given a pending product
    Product::factory()->pending()->create();

    // When nobody, a member, an unconfirmed reviewer, a suspended reviewer, a reviewer and an admin ask
    $this->getJson('/api/v1/staff/review-queue')->assertUnauthorized();
    signedInMember();
    $this->getJson('/api/v1/staff/review-queue')->assertForbidden();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->unverified()->create());
    $this->getJson('/api/v1/staff/review-queue')->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->suspended()->create());
    $this->getJson('/api/v1/staff/review-queue')->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    signedInStaff(Role::Reviewer);
    $this->getJson('/api/v1/staff/review-queue')->assertOk()->assertJsonCount(1, 'data');
    signedInStaff(Role::Admin);
    $this->getJson('/api/v1/staff/review-queue')->assertOk();
});

it('is never cached', function () {
    // Given a reviewer
    signedInStaff();

    // When the queue is read
    // Then the response carries no-store (see Task 14 for the header rule)
    expect($this->getJson('/api/v1/staff/review-queue')->headers->get('Cache-Control'))->toContain('no-store');
});
