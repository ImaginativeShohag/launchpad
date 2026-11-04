<?php

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

function staffListGet(string $query = ''): TestResponse
{
    return test()->getJson('/api/v1/staff/products'.($query === '' ? '' : '?'.$query));
}

it('lists every non-draft product, latest change first, with the row keys and never a draft or a deleted one', function () {
    // Given products in several statuses, a draft, a soft-deleted live product
    signedInStaff();
    $pending = Product::factory()->pending()->create(['updated_at' => now()->subDays(3)]);
    $live = Product::factory()->live()->create(['updated_at' => now()->subDay(), 'votes_count' => 12, 'comments_count' => 4]);
    $down = Product::factory()->takenDown()->create(['updated_at' => now()->subHour()]);
    Product::factory()->complete()->create();
    Product::factory()->live()->create(['deleted_at' => now()]);

    // When the list is read
    $response = staffListGet()->assertOk();

    // Then three rows, newest change first, with maker and counters and no description or email
    expect($response->json('data.*.id'))->toBe([$down->id, $live->id, $pending->id]);
    expect(array_keys($response->json('data.1')))->toBe([
        'id', 'slug', 'name', 'tagline', 'logo_url', 'status', 'launch_date', 'votes_count', 'comments_count', 'submitted_at', 'launched_at',
        'taken_down_at', 'takedown_reason', 'maker', 'topics', 'created_at', 'updated_at',
    ]);
    expect($response->json('data.1'))->toMatchArray(['votes_count' => 12, 'comments_count' => 4, 'status' => 'live']);
    expect(array_keys($response->json('data.1.maker')))->toBe(['id', 'handle', 'name']);
    expect($response->json('data.0'))->toMatchArray(['status' => 'taken_down', 'takedown_reason' => 'Test reason']);
    expect($response->getContent())->not->toContain('@example.test');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('is for reviewers and admins only, judged before anything is validated', function () {
    // Given a pending product
    Product::factory()->pending()->create();

    // When nobody, a member (also with hostile input), an unconfirmed and a suspended reviewer ask
    $this->getJson('/api/v1/staff/products')->assertUnauthorized();
    signedInMember();
    staffListGet()->assertForbidden();
    staffListGet('status=draft&q[]=x&cursor=!!!')->assertForbidden();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->unverified()->create());
    staffListGet()->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->suspended()->create());
    staffListGet()->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    // Then a reviewer and an admin get the list
    signedInStaff(Role::Reviewer);
    staffListGet()->assertOk()->assertJsonCount(1, 'data');
    signedInStaff(Role::Admin);
    staffListGet()->assertOk();
});

it('filters by status for every non-draft status and refuses draft', function (ProductStatus $status) {
    // Given one product per non-draft status and a draft
    signedInStaff();
    $byStatus = [];
    foreach (['pending' => 'pending', 'approved' => 'approved', 'scheduled' => 'scheduled', 'live' => 'live', 'rejected' => 'rejected', 'changes_requested' => 'changesRequested', 'taken_down' => 'takenDown'] as $value => $state) {
        $byStatus[$value] = Product::factory()->{$state}()->create();
    }
    Product::factory()->complete()->create();

    // When filtered
    $rows = staffListGet('status='.$status->value)->assertOk()->json('data.*.id');

    // Then only that status is listed
    expect($rows)->toBe([$byStatus[$status->value]->id]);
})->with(array_values(array_filter(ProductStatus::cases(), fn (ProductStatus $s) => $s !== ProductStatus::Draft)));

it('searches inside the name and at the start of the slug, with wildcards taken literally', function () {
    // Given products
    signedInStaff();
    $a = Product::factory()->live()->create(['name' => 'Lumen Notes', 'slug' => 'lumen-notes']);
    $b = Product::factory()->pending()->create(['name' => 'Bright Lumen', 'slug' => 'bright-one']);
    $c = Product::factory()->pending()->create(['name' => 'Other', 'slug' => 'lumen-extra']);
    $d = Product::factory()->pending()->create(['name' => '100% Done', 'slug' => 'done-100']);

    // When searched
    $lumen = staffListGet('q=LUMEN')->json('data.*.id');
    $pct = staffListGet('q='.rawurlencode('0% D'))->json('data.*.id');
    $wild = staffListGet('q=%25%25')->json('data.*.id');
    $combined = staffListGet('q=lumen&status=live')->json('data.*.id');

    // Then name-contains and slug-prefix both match, % is literal, and filters combine
    expect($lumen)->toContain($a->id, $b->id, $c->id)->not->toContain($d->id);
    expect($pct)->toBe([$d->id]);
    expect($wild)->toBe([]);
    expect($combined)->toBe([$a->id]);
});

it('pages by keyset without skipping or repeating rows changed in the same second, keeping the filters in the links', function () {
    // Given five pending products, three changed at one instant
    signedInStaff();
    $tie = now()->subDays(3)->startOfSecond();
    foreach ([$tie, $tie, $tie, now()->subDay(), now()->subHour()] as $at) {
        $p = Product::factory()->pending()->create();
        DB::table('products')->where('id', $p->id)->update(['updated_at' => $at]);
    }

    // When read two at a time
    $seen = [];
    $cursor = null;
    do {
        $page = staffListGet('status=pending&per_page=2'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $seen = array_merge($seen, $page->json('data.*.id'));
        $cursor = $page->json('meta.next_cursor');
        if ($cursor !== null) {
            expect($page->json('links.next'))->toContain('status=pending');
        }
    } while ($cursor !== null);

    // Then each appears once
    expect($seen)->toHaveCount(5)->and(collect($seen)->unique()->count())->toBe(5);
});

it('refuses hostile parameters with 422, never 500', function (string $query) {
    // Given a reviewer
    signedInStaff();

    // When the query string is hostile
    // Then it is a 422
    staffListGet($query)->assertUnprocessable();
})->with([
    'status draft' => ['status=draft'], 'status unknown' => ['status=archived'], 'status array' => ['status[]=live'], 'status object' => ['status[a]=live'], 'status empty' => ['status='],
    'q one char' => ['q=a'], 'q empty' => ['q='], 'q array' => ['q[]=ab'], 'q object' => ['q[a]=bc'], 'q long' => [fn () => 'q='.str_repeat('a', 101)], 'q control' => ['q=ab%00cd'],
    'cursor null' => ['cursor=bnVsbA'], 'cursor garbage' => ['cursor=!!!'], 'cursor array' => ['cursor[]=x'], 'cursor object' => ['cursor[a]=b'],
    'per_page 0' => ['per_page=0'], 'per_page 51' => ['per_page=51'], 'per_page text' => ['per_page=abc'], 'per_page array' => ['per_page[]=1'], 'per_page object' => ['per_page[a]=1'],
]);

it('shows a take-down and a restore in the list', function () {
    // Given a live product and an admin
    $admin = signedInStaff(Role::Admin);
    $product = Product::factory()->live()->create();

    // When it is taken down and then restored
    $this->postJson("/api/v1/staff/products/{$product->id}/take-down", ['reason' => 'Spam product'])->assertOk();
    $down = staffListGet('status=taken_down')->json('data');
    $this->postJson("/api/v1/staff/products/{$product->id}/restore")->assertOk();
    $live = staffListGet('status=live')->json('data');

    // Then the list follows
    expect($down)->toHaveCount(1)->and($down[0]['takedown_reason'])->toBe('Spam product');
    expect(collect($live)->pluck('id')->all())->toBe([$product->id]);
    expect($admin->id)->not->toBeNull();
});

it('does not run N+1 queries', function () {
    // Given a few products with topics and makers
    signedInStaff();
    Product::factory()->pending()->count(2)->create();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        staffListGet('per_page=50')->assertOk();

        return count(DB::getQueryLog());
    };
    $small = $count();

    // When more exist
    Product::factory()->live()->count(6)->create();

    // Then the count is the same
    expect($count())->toBe($small);
});

it('serves the unfiltered and the status-filtered list, first page and next, by their indexes without a sort', function (string $filter, string $index) {
    // Given thousands of products
    signedInStaff();
    $maker = User::factory()->create();
    $statuses = ['pending', 'live', 'rejected', 'draft', 'taken_down'];
    $rows = [];
    for ($i = 0; $i < 3000; $i++) {
        $status = $statuses[$i % 5];
        $rows[] = [
            'id' => strtolower((string) Str::ulid()), 'maker_id' => $maker->id, 'slug' => 'idx-'.$i, 'name' => 'Idx '.$i, 'status' => $status,
            'launch_date' => in_array($status, ['live', 'taken_down'], true) ? '2026-10-01' : null, 'launched_at' => in_array($status, ['live', 'taken_down'], true) ? now() : null,
            'submitted_at' => $status === 'draft' ? null : now(), 'approved_at' => in_array($status, ['live', 'taken_down'], true) ? now() : null,
            'taken_down_at' => $status === 'taken_down' ? now() : null, 'takedown_reason' => $status === 'taken_down' ? 'x' : null,
            'created_at' => now(), 'updated_at' => now()->subMinutes($i % 1400), 'deleted_at' => null,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('products')->insert($chunk);
    }
    DB::statement('ANALYZE products');
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $capture = function (string $query): string {
        $captured = [];
        DB::listen(function ($q) use (&$captured): void {
            if (preg_match('/^\s*select\b/i', $q->sql) === 1 && str_contains($q->sql, 'order by') && preg_match('/from "products"/', $q->sql) === 1) {
                $captured[] = DB::getQueryGrammar()->substituteBindingsIntoRawSql($q->sql, $q->bindings);
            }
        });
        staffListGet($query)->assertOk();
        expect($captured)->toHaveCount(1);

        return $captured[0];
    };
    $base = $filter === '' ? 'per_page=25' : $filter.'&per_page=25';
    $cursor = staffListGet($base)->json('meta.next_cursor');

    // When the real SQL of page one and page two is planned
    // Then the matching index is walked in order
    foreach ([$capture($base), $capture($base.'&cursor='.$cursor)] as $sql) {
        $plan = collect(DB::select('EXPLAIN '.$sql))->pluck('QUERY PLAN')->implode("\n");
        expect($plan)->toContain($index)->not->toContain('Seq Scan');
        expect(preg_match('/^\s*(->\s+)?Sort\s+\(cost/m', $plan))->toBe(0);
    }
})->with([['', 'products_staff_list_index'], ['status=live', 'products_staff_status_index']]);
