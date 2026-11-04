<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

it('keeps queueing the auth mail but exposes no Laravel database notifications relation', function () {
    // Given a member and a faked notification channel
    Notification::fake();
    $user = User::factory()->unverified()->create();

    // When an auth notification is sent to the member and to an anonymous route
    $user->notify(new VerifyEmailNotification);
    Notification::route('mail', 'someone@example.com')->notify(new VerifyEmailNotification);

    // Then both were handed to the notification system, and nothing reads the wrong table
    Notification::assertSentTo($user, VerifyEmailNotification::class);
    Notification::assertSentTo(new AnonymousNotifiable, VerifyEmailNotification::class);
    expect(method_exists($user, 'notifications'))->toBeFalse()
        ->and(method_exists($user, 'unreadNotifications'))->toBeFalse()
        ->and($user->routeNotificationFor('mail'))->toBe($user->email);
});
