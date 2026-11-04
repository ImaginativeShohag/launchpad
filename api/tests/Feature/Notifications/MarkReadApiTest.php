<?php

use App\Actions\Notification\MarkNotificationsRead;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

const NOTIF_READ = '/api/v1/me/notifications/read';

it('marks the given ids and answers marked and the remaining count', function () {
    // Given three unread rows, one foreign row and one already read row
    $me = signedInMember();
    $a = Notification::factory()->forUser($me)->createdAt(now()->subMinute())->create();
    $b = Notification::factory()->forUser($me)->createdAt(now()->subMinute())->create();
    Notification::factory()->forUser($me)->createdAt(now()->subMinute())->create();
    $read = Notification::factory()->forUser($me)->createdAt(now()->subMinute())->read()->create();
    $foreign = Notification::factory()->forUser(User::factory()->create())->createdAt(now()->subMinute())->create();

    // When ids including a foreign, an unknown, a read and a duplicate id are sent
    $response = $this->postJson(NOTIF_READ, ['ids' => [$a->id, $a->id, strtoupper($b->id), $read->id, $foreign->id, (string) Str::uuid7()]]);

    // Then only the two own unread rows were marked (duplicates collapsed) and one remains
    $response->assertOk()->assertExactJson(['marked' => 2, 'unread_count' => 1]);
    expect($foreign->fresh()->read_at)->toBeNull();
});

it('marks everything with all true and a repeat marks nothing', function () {
    // Given five unread rows
    $me = signedInMember();
    Notification::factory()->forUser($me)->count(5)->createdAt(now()->subMinute())->create();

    // When all is marked twice
    $first = $this->postJson(NOTIF_READ, ['all' => true]);
    $second = $this->postJson(NOTIF_READ, ['all' => true]);

    // Then 5 then 0
    $first->assertOk()->assertExactJson(['marked' => 5, 'unread_count' => 0]);
    $second->assertOk()->assertExactJson(['marked' => 0, 'unread_count' => 0]);
});

it('answers a foreign, unknown and read id with the same body (no existence oracle)', function () {
    // Given a foreign row, an already-read own row and an unknown id
    $me = signedInMember();
    $foreign = Notification::factory()->forUser(User::factory()->create())->create();
    $read = Notification::factory()->forUser($me)->read()->create();

    // When each is sent alone
    $bodies = [$foreign->id, $read->id, (string) Str::uuid7()];

    // Then the answers are identical
    $answers = array_map(fn (string $id) => $this->postJson(NOTIF_READ, ['ids' => [$id]])->assertOk()->getContent(), $bodies);
    expect(array_unique($answers))->toHaveCount(1);
});

it('rejects every odd payload with 422 and never 500', function (mixed $payload) {
    // Given a signed-in member
    signedInMember();
    $payload = $payload instanceof Closure ? $payload() : $payload;

    // When the odd payload is posted
    $response = $this->postJson(NOTIF_READ, $payload);

    // Then it is a 422
    $response->assertUnprocessable();
})->with([
    'empty object' => [[]],
    'ids empty list' => [['ids' => []]],
    'ids object' => [['ids' => ['a' => '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b']]],
    'ids nested' => [['ids' => [[]]]],
    'ids null element' => [['ids' => [null]]],
    'ids int element' => [['ids' => [1]]],
    'ids bad uuid' => [['ids' => ['not-a-uuid']]],
    'ids ulid' => [['ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']]],
    'ids string' => [['ids' => 'x']],
    'ids null' => [['ids' => null]],
    'ids 51' => [fn () => ['ids' => array_map(fn () => (string) Str::uuid7(), range(1, 51))]],
    'all yes' => [['all' => 'yes']],
    'all true string' => [['all' => 'true']],
    'all 1' => [['all' => 1]],
    'all string 1' => [['all' => '1']],
    'all false' => [['all' => false]],
    'all null' => [['all' => null]],
    'all array' => [['all' => []]],
    'all array true' => [['all' => [true]]],
    'both' => [['ids' => ['0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b'], 'all' => true]],
    'stray field only' => [['foo' => 'bar']],
    'ids and false all' => [['ids' => ['0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b'], 'all' => false]],
]);

it('accepts exactly 50 ids', function () {
    // Given a member and 50 unknown ids
    signedInMember();
    $ids = array_map(fn () => (string) Str::uuid7(), range(1, 50));

    // When they are posted
    // Then nothing is marked and it is a 200
    $this->postJson(NOTIF_READ, ['ids' => $ids])->assertOk()->assertJsonPath('marked', 0);
});

it('answers a malformed id with 422, not 404 and not 500', function () {
    // Given a signed-in member
    signedInMember();

    // When a non-uuid string reaches the endpoint (Postgres would raise 22P02 on a uuid column)
    // Then it is a 422 on ids.0
    $this->postJson(NOTIF_READ, ['ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV-x']])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
});

it('guards the route: guest 401, suspended 403 before validation, unverified allowed', function () {
    // Given a guest
    $this->postJson(NOTIF_READ, ['ids' => 'x'])->assertUnauthorized();
    $this->postJson(NOTIF_READ, ['all' => true])->assertUnauthorized();

    // When a suspended member posts an invalid body
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->postJson(NOTIF_READ, ['all' => 'yes'])->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    // And an unverified member posts a valid one
    $unverified = User::factory()->unverified()->create();
    Sanctum::actingAs($unverified);
    Notification::factory()->forUser($unverified)->createdAt(now()->subMinute())->create();

    // Then it is allowed
    $this->postJson(NOTIF_READ, ['all' => true])->assertOk()->assertJsonPath('marked', 1);
});

it('throttles the 31st mark-read of a minute with a Retry-After header, and not the reads', function () {
    // Given a member who made 30 mark-read calls
    $me = signedInMember();
    foreach (range(1, 30) as $_) {
        $this->postJson(NOTIF_READ, ['all' => true])->assertOk();
    }

    // When a 31st arrives, and the two GETs
    $throttled = $this->postJson(NOTIF_READ, ['all' => true]);

    // Then the write is 429 with Retry-After and no-store, and the reads are not throttled by this limiter
    $throttled->assertStatus(429)->assertHeader('Retry-After');
    expect($throttled->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $this->getJson('/api/v1/me/notifications')->assertOk();
    $this->getJson('/api/v1/me/notifications/unread-count')->assertOk();
});

it('carries no-store on every response of the three routes, errors included', function () {
    // Given a member
    signedInMember();

    // When each route answers success and failure
    $responses = [
        $this->getJson('/api/v1/me/notifications'),
        $this->getJson('/api/v1/me/notifications?per_page=abc'),
        $this->getJson('/api/v1/me/notifications/unread-count'),
        $this->postJson(NOTIF_READ, ['all' => true]),
        $this->postJson(NOTIF_READ, []),
    ];

    // Then all are no-store, private
    foreach ($responses as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('sends the live count to a second tab: one notification.count frame with the new count', function () {
    // Given a member with three unread rows
    $me = signedInMember();
    Notification::factory()->forUser($me)->count(3)->createdAt(now()->subMinute())->create();
    notifClearFrames();

    // When tab one marks one row read
    $id = Notification::query()->where('user_id', $me->id)->value('id');
    $this->postJson(NOTIF_READ, ['ids' => [$id]])->assertOk()->assertJsonPath('unread_count', 2);

    // Then exactly one frame went to private-user.{id} carrying the new count
    $frames = notifFramesOn('private-user.'.$me->id);
    expect($frames)->toHaveCount(1)->and($frames[0]['name'])->toBe('notification.count')->and($frames[0]['data']['unread_count'])->toBe(2);
});

it('does not fail the request when the broadcaster is down', function () {
    // Given a broken broadcaster and an unread row
    notifBreakBroadcaster();
    Exceptions::fake();
    $me = signedInMember();
    Notification::factory()->forUser($me)->createdAt(now()->subMinute())->create();

    // When the member marks all
    // Then the request still succeeds and the failure is reported
    $this->postJson(NOTIF_READ, ['all' => true])->assertOk()->assertExactJson(['marked' => 1, 'unread_count' => 0]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb is down');
});

it('hands the action a list of distinct lower-case ids', function () {
    // Given the action replaced by a spy
    $seen = [];
    app()->instance(MarkNotificationsRead::class, new class($seen) extends MarkNotificationsRead
    {
        public function __construct(private array &$seen) {}

        public function handle(string $userId, ?array $ids, bool $all): int
        {
            $this->seen = ['ids' => $ids, 'all' => $all];

            return 0;
        }
    });
    signedInMember();
    $id = (string) Str::uuid7();

    // When the same id is sent three times in different cases
    $this->postJson(NOTIF_READ, ['ids' => [$id, strtoupper($id), $id]])->assertOk();

    // Then the action got it once, lower-cased, as a list
    expect($seen)->toBe(['ids' => [$id], 'all' => false]);
});
