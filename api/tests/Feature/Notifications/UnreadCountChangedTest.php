<?php

use App\Events\UnreadCountChanged;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

it('is a private-channel broadcast that carries only the user id', function () {
    // Given the event for a member
    $event = new UnreadCountChanged('01ARZ3NDEKTSV4RRFFQ69G5FAV');

    // When its broadcast shape is read
    // Then it is queued after commit, tried once, on the private user channel under notification.count
    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event->afterCommit)->toBeTrue()->and($event->tries)->toBe(1)
        ->and($event->broadcastAs())->toBe('notification.count');
    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1)->and($channels[0])->toBeInstanceOf(PrivateChannel::class)->not->toBeInstanceOf(PresenceChannel::class)
        ->and($channels[0]->name)->toBe('private-user.01ARZ3NDEKTSV4RRFFQ69G5FAV');
    expect(array_keys(get_object_vars($event)))->toContain('userId')->not->toContain('count');
});

it('reads the count when the broadcast job runs, not when the event was built', function () {
    // Given an event built while the member has one unread row
    $user = User::factory()->create();
    Notification::factory()->forUser($user)->createdAt(now()->subMinute())->create();
    $event = new UnreadCountChanged($user->id);

    // When another row arrives before the job reads the payload
    $newest = Notification::factory()->forUser($user)->createdAt(now())->create();
    $payload = $event->broadcastWith();

    // Then the payload is current
    expect($payload)->toBe(['unread_count' => 2, 'notification_id' => $newest->id]);
});

it('sends a null notification id when nothing is unread and nothing but two keys', function () {
    // Given a member whose only row is read
    $user = User::factory()->create();
    Notification::factory()->forUser($user)->read()->create();

    // When the payload is built
    $payload = (new UnreadCountChanged($user->id))->broadcastWith();

    // Then the id is null
    expect($payload)->toBe(['unread_count' => 0, 'notification_id' => null]);
});

it('puts a small frame with no name, link or address on the wire', function () {
    // Given a member with a notification that has a product name and a href
    $user = User::factory()->create(['email' => 'secret.person@example.com']);
    Notification::factory()->forUser($user)->create();
    notifClearFrames();

    // When the event is dispatched for real
    UnreadCountChanged::dispatch($user->id);

    // Then the recorded frame is small and holds none of the member's text
    $frames = notifFramesOn('private-user.'.$user->id);
    expect($frames)->toHaveCount(1)->and($frames[0]['size'])->toBeLessThan(400);
    $raw = json_encode(array_map(fn ($r) => (string) $r->getBody(), array_values(app('test.reverb.requests')->getArrayCopy())));
    expect($raw)->not->toContain('Acme')->not->toContain('/me/products')->not->toContain('secret.person')->not->toContain($user->name);
});
