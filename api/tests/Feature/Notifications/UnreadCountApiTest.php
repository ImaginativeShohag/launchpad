<?php

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

it('returns the unread count unwrapped', function () {
    // Given a member with three unread and two read rows, and another member's rows
    $me = signedInMember();
    Notification::factory()->forUser($me)->count(3)->create();
    Notification::factory()->forUser($me)->count(2)->read()->create();
    Notification::factory()->forUser(User::factory()->create())->count(4)->create();

    // When the count is read
    $response = $this->getJson('/api/v1/me/notifications/unread-count');

    // Then it is exactly {unread_count, capped}
    $response->assertOk()->assertExactJson(['unread_count' => 3, 'capped' => false]);
});

it('caps at 100', function (int $rows) {
    // Given a member with $rows unread rows
    $me = signedInMember();
    notifBulk($me->id, $rows);

    // When the count is read
    // Then it is capped at 100
    $this->getJson('/api/v1/me/notifications/unread-count')->assertOk()->assertExactJson(['unread_count' => min($rows, 100), 'capped' => $rows >= 100]);
})->with([99, 100, 150]);

it('runs a fixed number of queries whatever the size of the table', function () {
    // Given a member with many rows
    $me = signedInMember();
    notifBulk($me->id, 150);
    $this->getJson('/api/v1/me/notifications/unread-count')->assertOk();

    // When the queries of one request are counted for 150 rows and again for 300 rows
    $count = function () {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $this->getJson('/api/v1/me/notifications/unread-count')->assertOk();

        return $n;
    };
    $first = $count();
    notifBulk($me->id, 150, null, 1000);
    $second = $count();

    // Then the count does not grow
    expect($second)->toBe($first);
});

it('guards the route: guest 401, suspended 403, unverified allowed', function () {
    // Given the route
    $this->getJson('/api/v1/me/notifications/unread-count')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->suspended()->create());
    $this->getJson('/api/v1/me/notifications/unread-count')->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    Sanctum::actingAs(User::factory()->unverified()->create());

    // When an unverified member reads it
    // Then it is allowed
    $this->getJson('/api/v1/me/notifications/unread-count')->assertOk();
});
