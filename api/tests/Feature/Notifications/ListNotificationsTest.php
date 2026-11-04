<?php

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

const NOTIF_LIST = '/api/v1/me/notifications';

function notifCursorOf(mixed $payload): string
{
    return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
}

it('lists the newest first with the documented shape', function () {
    // Given a member with an old read row and a newer unread row
    $me = signedInMember();
    $old = Notification::factory()->forUser($me)->createdAt(Carbon::parse('2026-10-20 10:00:00', 'UTC'))->read(Carbon::parse('2026-10-20 11:00:00', 'UTC'))->create();
    $new = Notification::factory()->forUser($me)->createdAt(Carbon::parse('2026-10-21 10:00:00', 'UTC'))->create();

    // When the list is read
    $response = $this->getJson(NOTIF_LIST);

    // Then it is newest first, with id, type, data, read_at and created_at only
    $response->assertOk()->assertJsonPath('data.0.id', $new->id)->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.0.read_at', null)->assertJsonPath('data.1.read_at', '2026-10-20T11:00:00+00:00')
        ->assertJsonPath('data.0.type', 'review_approved')
        ->assertJsonPath('data.0.created_at', '2026-10-21T10:00:00+00:00')
        ->assertJsonStructure(['data', 'links', 'meta']);
    expect(array_keys($response->json('data.0')))->toBe(['id', 'type', 'data', 'read_at', 'created_at']);
    expect($response->json('data.0.data'))->toEqual($new->data);
});

it('orders ties on created_at by id descending and keeps microsecond order inside one second', function () {
    // Given three rows in one second, two of them at the exact same microsecond
    $me = signedInMember();
    $second = Carbon::parse('2026-10-21 10:00:00', 'UTC');
    $a = Notification::factory()->forUser($me)->createdAt($second->copy()->addMicroseconds(100))->create();
    $b = Notification::factory()->forUser($me)->createdAt($second->copy()->addMicroseconds(300))->create();
    $c = Notification::factory()->forUser($me)->createdAt($second->copy()->addMicroseconds(300))->create();

    // When the list is read
    $ids = collect($this->getJson(NOTIF_LIST)->assertOk()->json('data'))->pluck('id')->all();

    // Then later microseconds come first and the tie falls back to id desc
    $tied = collect([$b->id, $c->id])->sortDesc()->values()->all();
    expect($ids)->toBe([...$tied, $a->id]);
});

it('walks every row exactly once in order with a keyset cursor', function () {
    // Given seven rows, two sharing a timestamp
    $me = signedInMember();
    $rows = [];
    foreach (range(1, 7) as $i) {
        $at = Carbon::parse('2026-10-21 10:00:00', 'UTC')->addMicroseconds(($i === 4 ? 3 : $i) * 1000);
        $rows[] = Notification::factory()->forUser($me)->createdAt($at)->create();
    }
    $order = collect($rows)
        ->sort(fn ($x, $y) => [$y->created_at->format('Y-m-d H:i:s.u'), $y->id] <=> [$x->created_at->format('Y-m-d H:i:s.u'), $x->id])
        ->pluck('id')->values()->all();

    // When walking with per_page=2
    $seen = [];
    $url = NOTIF_LIST.'?per_page=2';
    $pages = 0;
    do {
        $body = $this->getJson($url)->assertOk()->json();
        $seen = [...$seen, ...array_column($body['data'], 'id')];
        $url = $body['links']['next'] ?? null;
        $pages++;
    } while ($url !== null && $pages < 10);

    // Then every row appeared once, in order, over four pages
    expect($seen)->toBe($order)->and($pages)->toBe(4);
});

it('keeps per_page and unread on the next link', function () {
    // Given three unread rows
    $me = signedInMember();
    Notification::factory()->forUser($me)->count(3)->createdAt(now()->subMinute())->create();

    // When the first unread page is read
    $next = $this->getJson(NOTIF_LIST.'?per_page=2&unread=1')->assertOk()->json('links.next');

    // Then the next link carries both
    expect($next)->toContain('per_page=2')->toContain('unread=1')->toContain('cursor=');
});

it('uses 20 per page by default and at most 50', function () {
    // Given 55 rows
    $me = signedInMember();
    notifBulk($me->id, 55);

    // When read with no per_page and with 50
    // Then 20 and 50 rows come back
    expect($this->getJson(NOTIF_LIST)->assertOk()->json('data'))->toHaveCount(20);
    expect($this->getJson(NOTIF_LIST.'?per_page=50')->assertOk()->json('data'))->toHaveCount(50);
});

it('rejects a bad per_page with 422', function (string $query) {
    // Given a signed-in member
    signedInMember();

    // When the list is requested with a bad per_page
    // Then it is a 422
    $this->getJson(NOTIF_LIST.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors('per_page');
})->with(['too big' => 'per_page=51', 'zero' => 'per_page=0', 'text' => 'per_page=abc', 'array' => 'per_page[]=1', 'object' => 'per_page[a]=1', 'negative' => 'per_page=-1', 'float' => 'per_page=1.5']);

it('filters by unread=1 and treats 0, absent and empty as all', function () {
    // Given an unread and a read row
    $me = signedInMember();
    $unread = Notification::factory()->forUser($me)->createdAt(now()->subMinutes(2))->create();
    $read = Notification::factory()->forUser($me)->createdAt(now()->subMinute())->read()->create();

    // When read with each unread value
    // Then 1 shows only unread, the rest show both in order
    expect(collect($this->getJson(NOTIF_LIST.'?unread=1')->assertOk()->json('data'))->pluck('id')->all())->toBe([$unread->id]);
    foreach (['?unread=0', '', '?unread='] as $query) {
        expect(collect($this->getJson(NOTIF_LIST.$query)->assertOk()->json('data'))->pluck('id')->all())->toBe([$read->id, $unread->id]);
    }
});

it('rejects a bad unread value with 422', function (string $query) {
    // Given a signed-in member
    signedInMember();

    // When the list is requested with it
    // Then it is a 422
    $this->getJson(NOTIF_LIST.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors('unread');
})->with(['two' => 'unread=2', 'array' => 'unread[]=1', 'text' => 'unread=true', 'object' => 'unread[a]=1', 'yes' => 'unread=yes']);

it('shows only the caller\'s rows', function () {
    // Given rows of two members
    $me = signedInMember();
    $other = User::factory()->create();
    $mine = Notification::factory()->forUser($me)->create();
    Notification::factory()->forUser($other)->count(3)->create();

    // When the list is read
    // Then only mine appear
    expect(collect($this->getJson(NOTIF_LIST)->assertOk()->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

it('rejects tampered cursors with 422 and never 500', function (mixed $cursor) {
    // Given a signed-in member
    signedInMember();
    $cursor = $cursor instanceof Closure ? $cursor() : $cursor;

    // When a tampered cursor is sent
    $response = $this->getJson(NOTIF_LIST.'?'.http_build_query(['cursor' => $cursor]));

    // Then it is a 422 on cursor
    $response->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with([
    'not base64' => '!!!',
    'json null' => fn () => notifCursorOf(null),
    'missing pointer' => fn () => notifCursorOf(['created_at' => '2026-10-21 10:00:00', 'id' => (string) Str::uuid7()]),
    'wrong order' => fn () => notifCursorOf(['id' => (string) Str::uuid7(), 'created_at' => '2026-10-21 10:00:00', '_pointsToNextItems' => true]),
    'ulid for uuid' => fn () => notifCursorOf(['created_at' => '2026-10-21 10:00:00', 'id' => (string) Str::ulid(), '_pointsToNextItems' => true]),
    'string for date' => fn () => notifCursorOf(['created_at' => 'yesterday', 'id' => (string) Str::uuid7(), '_pointsToNextItems' => true]),
    'february 31' => fn () => notifCursorOf(['created_at' => '2026-02-31 10:00:00', 'id' => (string) Str::uuid7(), '_pointsToNextItems' => true]),
    'int id' => fn () => notifCursorOf(['created_at' => '2026-10-21 10:00:00', 'id' => 5, '_pointsToNextItems' => true]),
]);

it('rejects an array cursor with 422', function () {
    // Given a signed-in member
    signedInMember();

    // When cursor is an array
    // Then it is a 422
    $this->getJson(NOTIF_LIST.'?cursor[]=a')->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

it('treats an empty cursor as page one and ignores a JSON body on GET', function () {
    // Given one row
    $me = signedInMember();
    $row = Notification::factory()->forUser($me)->create();

    // When read with ?cursor= and with a JSON body that would be invalid as a query
    $empty = $this->getJson(NOTIF_LIST.'?cursor=');
    $body = $this->call('GET', NOTIF_LIST, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['per_page' => 'x', 'cursor' => ['a'], 'unread' => []]));

    // Then both answer page one
    $empty->assertOk()->assertJsonPath('data.0.id', $row->id);
    $body->assertOk()->assertJsonPath('data.0.id', $row->id);
});

it('answers a guest 401 and a suspended member 403 without validating', function () {
    // Given a guest with a bad query
    $this->getJson(NOTIF_LIST)->assertUnauthorized();
    $this->getJson(NOTIF_LIST.'?per_page=abc')->assertUnauthorized();

    // When a suspended member sends a bad query
    Sanctum::actingAs(User::factory()->suspended()->create());

    // Then the account guard answers first
    $this->getJson(NOTIF_LIST.'?per_page=abc')->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('lets an unverified member read the list', function () {
    // Given an unverified member with a row
    $me = User::factory()->unverified()->create();
    Sanctum::actingAs($me);
    Notification::factory()->forUser($me)->create();

    // When the list is read
    // Then it is allowed
    $this->getJson(NOTIF_LIST)->assertOk()->assertJsonCount(1, 'data');
});

it('always emits data.params as a JSON object, empty included', function () {
    // Given rows whose params are empty, absent and filled
    $me = signedInMember();
    Notification::factory()->forUser($me)->createdAt(now()->subMinutes(3))->create(['data' => ['message_key' => 'notifications.review_approved', 'params' => []]]);
    Notification::factory()->forUser($me)->createdAt(now()->subMinutes(2))->create(['data' => ['message_key' => 'notifications.review_approved']]);
    Notification::factory()->forUser($me)->createdAt(now()->subMinute())->create();

    // When the raw JSON is read
    $raw = $this->getJson(NOTIF_LIST)->assertOk()->getContent();

    // Then every params is {} or an object, never []
    expect($raw)->toContain('"params":{}')->not->toContain('"params":[]')->toContain('"params":{"product_name":"Acme"}');
});
