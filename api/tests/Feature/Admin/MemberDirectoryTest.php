<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

function directoryGet(string $query = ''): TestResponse
{
    return test()->getJson('/api/v1/admin/users'.($query === '' ? '' : '?'.$query));
}

it('lists members newest first with the admin keys and no secret', function () {
    // Given an admin and two members created at different times, one suspended and unverified
    $admin = signedInStaff(Role::Admin);
    $older = User::factory()->create(['created_at' => now()->subDays(2), 'handle' => 'older_one']);
    $newer = User::factory()->suspended()->unverified()->create(['created_at' => now()->subDay(), 'handle' => 'newer_one', 'risk_score' => 40]);

    // When the directory is read
    $response = directoryGet()->assertOk();

    // Then the newest comes first with the documented keys, and no hash or token is anywhere in the body
    expect($response->json('data.*.id'))->toBe([$admin->id, $newer->id, $older->id]);
    expect(array_keys($response->json('data.1')))->toBe([
        'id', 'handle', 'name', 'email', 'role', 'created_at', 'is_suspended', 'suspended_at', 'suspended_reason', 'deleted_at', 'email_verified_at', 'risk_score',
    ]);
    expect($response->json('data.1'))->toMatchArray(['is_suspended' => true, 'risk_score' => 40, 'email_verified_at' => null, 'deleted_at' => null, 'role' => 'member']);
    $body = $response->getContent();
    expect($body)->not->toContain('password')->not->toContain('remember_token')->not->toContain('avatar_path')->not->toContain('pending_email');
    $response->assertJsonStructure(['data', 'links', 'meta']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('is for admins only, judged before anything is validated', function () {
    // Given members of every role
    // When nobody, a member, a reviewer, an unconfirmed admin and a suspended admin ask (with hostile input too)
    $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    signedInMember();
    directoryGet()->assertForbidden();
    directoryGet('q[]=x&per_page=999&cursor=!!!')->assertForbidden();
    signedInStaff(Role::Reviewer);
    directoryGet()->assertForbidden();
    directoryGet('role[]=x&status=nope')->assertForbidden();
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    directoryGet()->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    directoryGet()->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    // Then an admin gets in
    signedInStaff(Role::Admin);
    directoryGet()->assertOk();
});

it('leaves deleted members out by default and filters by status and role', function () {
    // Given an admin, an active member, a suspended reviewer and a deleted member
    $admin = signedInStaff(Role::Admin);
    $active = User::factory()->create();
    $suspended = User::factory()->role(Role::Reviewer)->suspended()->create();
    $deleted = User::factory()->create(['deleted_at' => now()]);

    // When read with each filter
    $all = directoryGet()->json('data.*.id');

    // Then the default hides the deleted one, and each filter narrows as named
    expect($all)->toContain($admin->id, $active->id, $suspended->id)->not->toContain($deleted->id);
    expect(directoryGet('status=deleted')->json('data.*.id'))->toBe([$deleted->id]);
    expect(directoryGet('status=suspended')->json('data.*.id'))->toBe([$suspended->id]);
    expect(directoryGet('status=active')->json('data.*.id'))->toContain($active->id, $admin->id)->not->toContain($suspended->id)->not->toContain($deleted->id);
    expect(directoryGet('role=reviewer')->json('data.*.id'))->toBe([$suspended->id]);
    expect(directoryGet('role=admin&status=suspended')->json('data'))->toBe([]);
});

it('searches the start of the handle, the name or a word of the name, and treats wildcards literally', function () {
    // Given an admin and members whose handle or name could match
    signedInStaff(Role::Admin);
    $byHandle = User::factory()->create(['handle' => 'zora_k', 'name' => 'Someone Else']);
    $byName = User::factory()->create(['handle' => 'plain1', 'name' => 'Zorana Petrovic']);
    $byWord = User::factory()->create(['handle' => 'plain2', 'name' => 'Ada Zora']);
    $inside = User::factory()->create(['handle' => 'plain3', 'name' => 'Lazora']);
    $percent = User::factory()->create(['handle' => 'plain4', 'name' => '100% Real']);

    // When searched (mixed case, a leading @, and wildcard characters)
    $zor = directoryGet('q=ZoR')->json('data.*.id');
    $at = directoryGet('q=@zora_')->json('data.*.id');
    $wild = directoryGet('q=%25%25')->json('data.*.id');
    $under = directoryGet('q=zo_a')->json('data.*.id');
    $pct = directoryGet('q='.rawurlencode('100%'))->json('data.*.id');

    // Then prefixes match, a mid-word hit does not, and % and _ are not wildcards
    expect($zor)->toContain($byHandle->id, $byName->id, $byWord->id)->not->toContain($inside->id);
    expect($at)->toBe([$byHandle->id]);
    expect($wild)->toBe([]);
    expect($under)->toBe([]);
    expect($pct)->toBe([$percent->id]);
});

it('judges the length of q after the leading @ is stripped', function () {
    // Given an admin and a member whose handle starts with zq (a prefix random Faker names never produce)
    signedInStaff(Role::Admin);
    $member = User::factory()->create(['handle' => 'zqel_x']);

    // When q is '@zq'
    // Then it searches 'zq' (and '@@zq' too), while '@a' and '@@' are refused above
    expect(directoryGet('q=%40zq')->assertOk()->json('data.*.id'))->toBe([$member->id]);
    expect(directoryGet('q=%40%40zq')->assertOk()->json('data.*.id'))->toBe([$member->id]);
});

it('does not search the e-mail', function () {
    // Given a member with a distinctive e-mail
    signedInStaff(Role::Admin);
    User::factory()->create(['email' => 'findme.please@example.test']);

    // When the e-mail is searched
    // Then nothing is found
    expect(directoryGet('q=findme')->json('data'))->toBe([]);
});

it('pages by keyset without skipping or repeating members created in the same second, and keeps the filters in the links', function () {
    // Given an admin and five members, three created at one instant
    signedInStaff(Role::Admin)->forceFill(['created_at' => now()->subYear()])->save();
    $tie = now()->subDays(3)->startOfSecond();
    foreach ([$tie, $tie, $tie, now()->subDay(), now()->subHour()] as $at) {
        User::factory()->create(['created_at' => $at, 'handle' => 'paged'.Str::lower(Str::random(6))]);
    }

    // When read two at a time with a filter until the end
    $seen = [];
    $cursor = null;
    do {
        $page = directoryGet('q=paged&per_page=2'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $seen = array_merge($seen, $page->json('data.*.id'));
        $cursor = $page->json('meta.next_cursor');
        if ($cursor !== null) {
            expect($page->json('links.next'))->toContain('q=paged')->toContain('per_page=2');
        }
    } while ($cursor !== null);

    // Then each member appears once
    expect($seen)->toHaveCount(5)->and(collect($seen)->unique()->count())->toBe(5);
});

it('refuses hostile parameters with 422, never 500', function (string $query) {
    // Given an admin
    signedInStaff(Role::Admin);

    // When the query string is hostile
    // Then it is a 422
    directoryGet($query)->assertUnprocessable();
})->with([
    'q one char' => ['q=a'], 'q empty' => ['q='], 'q array' => ['q[]=ab'], 'q object' => ['q[a]=bc'], 'q long' => [fn () => 'q='.str_repeat('a', 101)],
    'q only at signs' => ['q=@@'], 'q at plus one char' => ['q=@a'], 'q control' => ['q=ab%00cd'], 'q newline' => ['q=ab%0Acd'],
    'role unknown' => ['role=owner'], 'role array' => ['role[]=admin'], 'role object' => ['role[a]=admin'], 'role empty' => ['role='],
    'status unknown' => ['status=banned'], 'status array' => ['status[]=active'], 'status object' => ['status[a]=b'],
    'cursor null' => ['cursor=bnVsbA'], 'cursor garbage' => ['cursor=!!!'], 'cursor array' => ['cursor[]=x'], 'cursor object' => ['cursor[a]=b'],
    'per_page 0' => ['per_page=0'], 'per_page 51' => ['per_page=51'], 'per_page text' => ['per_page=abc'], 'per_page array' => ['per_page[]=1'], 'per_page object' => ['per_page[a]=1'],
]);

it('treats an empty cursor as page one and honours per_page up to 50', function () {
    // Given an admin and 55 members
    signedInStaff(Role::Admin);
    User::factory()->count(55)->create();

    // When read with an empty cursor and the largest page
    // Then page one comes back and the default size is 20
    directoryGet('cursor=')->assertOk()->assertJsonCount(20, 'data');
    directoryGet('per_page=50')->assertOk()->assertJsonCount(50, 'data');
});

it('runs a constant number of queries whatever the page size', function () {
    // Given an admin and a few members
    signedInStaff(Role::Admin);
    User::factory()->count(3)->create();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        directoryGet('per_page=50')->assertOk();

        return count(DB::getQueryLog());
    };
    $small = $count();

    // When many more exist
    User::factory()->count(30)->create();

    // Then the count is unchanged
    expect($count())->toBe($small);
});

it('serves the first and the next page by the created-at index without a sort', function () {
    // Given thousands of members
    $admin = signedInStaff(Role::Admin);
    $rows = [];
    for ($i = 0; $i < 3000; $i++) {
        $id = strtolower((string) Str::ulid());
        $rows[] = ['id' => $id, 'handle' => 'idx-'.$id, 'name' => 'Idx', 'email' => 'idx-'.$id.'@example.test', 'role' => 'member',
            'created_at' => now()->subMinutes($i % 1500), 'updated_at' => now(), 'deleted_at' => $i % 50 === 0 ? now() : null];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('users')->insert($chunk);
    }
    DB::statement('ANALYZE users');
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $capture = function (string $query): string {
        $captured = [];
        DB::listen(function ($q) use (&$captured): void {
            if (preg_match('/^\s*select\b/i', $q->sql) === 1 && str_contains($q->sql, 'order by') && preg_match('/from "users"/', $q->sql) === 1) {
                $captured[] = DB::getQueryGrammar()->substituteBindingsIntoRawSql($q->sql, $q->bindings);
            }
        });
        directoryGet($query)->assertOk();
        expect($captured)->toHaveCount(1);

        return $captured[0];
    };
    $cursor = directoryGet('per_page=25')->json('meta.next_cursor');

    // When the real SQL of page one and page two is planned
    // Then users_created_at_id_index is walked in order
    foreach ([$capture('per_page=25'), $capture('per_page=25&cursor='.$cursor)] as $sql) {
        $plan = collect(DB::select('EXPLAIN '.$sql))->pluck('QUERY PLAN')->implode("\n");
        expect($plan)->toContain('users_created_at_id_index')->not->toContain('Seq Scan');
        expect(preg_match('/^\s*(->\s+)?Sort\s+\(cost/m', $plan))->toBe(0);
    }
    expect($admin->id)->not->toBeNull();
});
