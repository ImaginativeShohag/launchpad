<?php

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('lists audit entries newest first with the actor, for admins only', function () {
    // Given two staff members who each did something, at different times
    $admin = User::factory()->role(Role::Admin)->create(['handle' => 'boss']);
    $reviewer = User::factory()->role(Role::Reviewer)->create(['handle' => 'rev']);
    $this->travel(-2)->hours();
    Audit::record('product.approved', $reviewer, null, ['note' => 'ok']);
    $this->travelBack();
    Audit::record('user.suspended', $admin, $reviewer, ['reason' => 'spam']);

    // When the admin opens the log
    Sanctum::actingAs($admin);
    $response = $this->getJson('/api/v1/admin/audit-logs');

    // Then both entries appear, newest first, with who did it, and the page is paginated
    $response->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'user.suspended')
        ->assertJsonPath('data.0.actor.handle', 'boss')
        ->assertJsonPath('data.1.action', 'product.approved')
        ->assertJsonStructure(['data', 'links', 'meta']);

    // And a reviewer is refused
    Sanctum::actingAs($reviewer);
    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
});

it('exposes no hashed ip, user agent or contact data', function () {
    // Given an entry written with an ip and a user agent
    $admin = User::factory()->role(Role::Admin)->create();
    $this->withHeader('User-Agent', 'SecretAgent/1.0');
    Audit::record('user.suspended', $admin, $admin);
    Sanctum::actingAs($admin);

    // When the admin lists the log
    $body = $this->getJson('/api/v1/admin/audit-logs')->assertOk()->getContent();

    // Then only the safe fields are present
    expect($body)->not->toContain('ip_hash')->not->toContain('user_agent')->not->toContain('SecretAgent')
        ->not->toContain($admin->email);
    $this->getJson('/api/v1/admin/audit-logs')->assertJsonPath('data.0.actor.id', $admin->id);
});

it('filters by actor, action and date range', function () {
    // Given entries from two actors on different days
    $admin = User::factory()->role(Role::Admin)->create();
    $other = User::factory()->role(Role::Reviewer)->create();
    $this->travelTo(now()->subDays(10));
    Audit::record('old.action', $other);
    $this->travelBack();
    Audit::record('user.role_changed', $admin);
    Audit::record('product.approved', $other);
    Sanctum::actingAs($admin);

    // When filtering by actor
    $byActor = $this->getJson('/api/v1/admin/audit-logs?actor='.$admin->id);
    // And by action
    $byAction = $this->getJson('/api/v1/admin/audit-logs?action=product.approved');
    // And by date
    $byDate = $this->getJson('/api/v1/admin/audit-logs?from='.now()->subDays(2)->toDateString());

    // Then each filter narrows the list
    expect($byActor->json('data.*.action'))->toBe(['user.role_changed']);
    expect($byAction->json('data.*.action'))->toBe(['product.approved']);
    expect($byDate->json('data'))->toHaveCount(2);
});

it('rejects malformed filters', function () {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When the date range is backwards
    $response = $this->getJson('/api/v1/admin/audit-logs?from=2026-10-10&to=2026-10-01');

    // Then it is a validation error
    $response->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('rejects array, object and malformed filter input with 422', function (string $query, string $field) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

    // When a filter is an array, an object or malformed
    $response = $this->getJson('/api/v1/admin/audit-logs?'.$query);

    // Then it is a validation error, never a 500
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'actor array' => ['actor[]=x', 'actor'],
    'actor object' => ['actor[a]=x', 'actor'],
    'actor not a ulid' => ['actor=nope', 'actor'],
    'action array' => ['action[]=x', 'action'],
    'action too long' => ['action='.'a-long-action-name-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x', 'action'],
    'from array' => ['from[]=2026-01-01', 'from'],
    'from words' => ['from=tomorrow', 'from'],
    'to array' => ['to[]=2026-01-01', 'to'],
    'per_page array' => ['per_page[]=5', 'per_page'],
    'per_page too big' => ['per_page=101', 'per_page'],
    'per_page zero' => ['per_page=0', 'per_page'],
    'cursor array' => ['cursor[]=x', 'cursor'],
]);

it('pages with a keyset cursor and never returns more than the maximum page size', function () {
    // Given an admin and three entries
    $admin = User::factory()->role(Role::Admin)->create();
    foreach (['a.one', 'a.two', 'a.three'] as $i => $action) {
        $this->travel($i)->minutes();
        Audit::record($action, $admin);
    }
    $this->travelBack();
    Sanctum::actingAs($admin);

    // When a page of two is requested, then the next page
    $first = $this->getJson('/api/v1/admin/audit-logs?per_page=2')->assertOk();
    $next = $first->json('meta.next_cursor');
    $second = $this->getJson('/api/v1/admin/audit-logs?per_page=2&cursor='.$next)->assertOk();

    // Then the pages follow on without overlap
    expect($first->json('data.*.action'))->toBe(['a.three', 'a.two']);
    expect($second->json('data.*.action'))->toBe(['a.one']);
    expect($second->json('meta.next_cursor'))->toBeNull();
});

it('treats an empty cursor as the first page, like the review queue', function () {
    // Given an admin and two entries
    $admin = User::factory()->role(Role::Admin)->create();
    Audit::record('a.one', $admin);
    Audit::record('a.two', $admin);
    Sanctum::actingAs($admin);

    // When the list is requested with an empty cursor
    $response = $this->getJson('/api/v1/admin/audit-logs?cursor=');

    // Then it is page one, not a 422
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('rejects a garbage cursor', function () {
    // Given an admin with one entry
    $admin = User::factory()->role(Role::Admin)->create();
    Audit::record('a.one', $admin);
    Sanctum::actingAs($admin);

    // When the cursor is garbage
    $response = $this->getJson('/api/v1/admin/audit-logs?cursor=not-a-cursor');

    // Then it is a validation error
    $response->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

it('requires sign-in, an active account and a confirmed email', function () {
    // Given no one is signed in
    $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();

    // Given a suspended admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    // Given an unconfirmed admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

function cursorOf(array $parameters): string
{
    return rtrim(strtr(base64_encode(json_encode($parameters)), '+/', '-_'), '=');
}

it('rejects each kind of tampered cursor', function (string $kind) {
    // Given an admin
    Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    $ulid = (string) Str::ulid();
    $cursor = match ($kind) {
        'garbage date' => cursorOf(['created_at' => 'garbage', 'id' => $ulid, '_pointsToNextItems' => true]),
        'array date' => cursorOf(['created_at' => ['x'], 'id' => $ulid, '_pointsToNextItems' => true]),
        'int date' => cursorOf(['created_at' => 5, 'id' => $ulid, '_pointsToNextItems' => true]),
        'array id' => cursorOf(['created_at' => '2026-01-01 00:00:00', 'id' => [$ulid], '_pointsToNextItems' => true]),
        'int id' => cursorOf(['created_at' => '2026-01-01 00:00:00', 'id' => 7, '_pointsToNextItems' => true]),
        'non ulid id' => cursorOf(['created_at' => '2026-01-01 00:00:00', 'id' => 'x', '_pointsToNextItems' => true]),
        'missing id' => cursorOf(['created_at' => '2026-01-01 00:00:00', '_pointsToNextItems' => true]),
        'extra key' => cursorOf(['created_at' => '2026-01-01 00:00:00', 'id' => $ulid, 'x' => 1, '_pointsToNextItems' => true]),
        'bad direction' => cursorOf(['created_at' => '2026-01-01 00:00:00', 'id' => $ulid, '_pointsToNextItems' => 'yes']),
        'not json' => rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '='),
        'json scalar' => cursorOf([]),
        'not base64' => '!!!',
    };

    // When it is used
    $response = $this->getJson('/api/v1/admin/audit-logs?cursor='.urlencode($cursor));

    // Then it is a 422
    $response->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with(['garbage date', 'array date', 'int date', 'array id', 'int id', 'non ulid id', 'missing id', 'extra key', 'bad direction', 'not json', 'json scalar', 'not base64']);

it('walks entries that share one created_at without duplicates or gaps', function () {
    // Given an admin and five entries written at the very same instant
    $admin = User::factory()->role(Role::Admin)->create();
    $this->travelTo(now()->startOfSecond());
    foreach (range(1, 5) as $i) {
        Audit::record('tie.'.$i, $admin);
    }
    Sanctum::actingAs($admin);

    // When every page of two is walked
    $seen = [];
    $url = '/api/v1/admin/audit-logs?per_page=2';
    do {
        $page = $this->getJson($url)->assertOk();
        $seen = [...$seen, ...$page->json('data.*.id')];
        $next = $page->json('meta.next_cursor');
        $url = '/api/v1/admin/audit-logs?per_page=2&cursor='.urlencode((string) $next);
    } while ($next !== null);

    // Then each of the five appears exactly once
    expect($seen)->toHaveCount(5)->and(array_unique($seen))->toHaveCount(5);
});

it('accepts a "to" date without a "from" date', function () {
    // Given an admin with one entry
    $admin = User::factory()->role(Role::Admin)->create();
    Audit::record('a.one', $admin);
    Sanctum::actingAs($admin);

    // When only "to" is given
    $response = $this->getJson('/api/v1/admin/audit-logs?to='.now()->toDateString());

    // Then it validates and filters
    $response->assertOk()->assertJsonCount(1, 'data');
});
