<?php

use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\MaskEmail;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** @param array<string, mixed> $extra */
function emailChangeLink(string $route, User $user, ?string $pending = null, int $minutes = 60, array $extra = []): string
{
    return URL::temporarySignedRoute(
        $route,
        now()->addMinutes($minutes),
        ['id' => $user->id, 'hash' => sha1($pending ?? (string) $user->pending_email), ...$extra],
        absolute: false,
    );
}

function requestEmailChange(User $user, string $new = 'new@example.com'): void
{
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/me/email', ['email' => $new, 'current_password' => 'correct-horse-battery'])->assertStatus(202);
}

function forgetActor(): void
{
    app('auth')->forgetGuards();
}

it('records a pending change, leaves the email alone and mails both addresses', function () {
    // Given a verified member with a password
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    Sanctum::actingAs($user);

    // When they ask to change their email
    $response = $this->postJson('/api/v1/me/email', ['email' => 'New@Example.com', 'current_password' => 'correct-horse-battery']);

    // Then the change is pending, the real email is untouched, and both addresses are mailed
    $response->assertStatus(202);
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('old@example.com');
    expect($fresh->pending_email)->toBe('new@example.com');
    expect($fresh->pending_email_expires_at->isFuture())->toBeTrue();
    Notification::assertSentOnDemand(ConfirmEmailChangeNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com');
    Notification::assertSentTo($user, EmailChangeRequestedNotification::class);
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::EmailChangeRequested)->exists())->toBeTrue();
});

it('points both emails at the web app with a signed link', function () {
    // Given a member with a pending change
    $user = User::factory()->create(['email' => 'old@example.com', 'pending_email' => 'new@example.com']);
    $expires = now()->addMinutes(60);

    // When the mails are composed
    $confirm = (new ConfirmEmailChangeNotification($user->id, 'new@example.com', $expires))->toMail($user);
    $notice = (new EmailChangeRequestedNotification('new@example.com', $expires))->toMail($user);

    // Then the buttons go to the React site and carry id, hash, expires and signature
    expect($confirm->actionUrl)->toStartWith('http://localhost:5173/confirm-email-change?')->toContain('id='.$user->id)->toContain('hash=')->toContain('expires=')->toContain('signature=');
    expect($notice->actionUrl)->toStartWith('http://localhost:5173/cancel-email-change?')->toContain('id='.$user->id)->toContain('hash=')->toContain('expires=')->toContain('signature=');
});

it('masks the new address in the notice to the old address and keeps the confirm link out of it', function () {
    // Given a member with a pending change
    $user = User::factory()->create(['email' => 'old@example.com', 'pending_email' => 'newaddress@example.com']);

    // When the notice for the old address is rendered
    $notice = (new EmailChangeRequestedNotification('newaddress@example.com', now()->addHour()))->toMail($user);
    $text = implode("\n", [...$notice->introLines, ...$notice->outroLines, (string) $notice->actionUrl]);

    // Then only the masked address and the cancel link appear
    expect($text)->toContain('n***@example.com')
        ->not->toContain('newaddress@example.com')
        ->not->toContain('confirm-email-change')
        ->toContain('cancel-email-change');
    expect((string) $notice->render())->not->toContain('newaddress@example.com');
});

it('masks email addresses', function (string $email, string $masked) {
    // When / Then
    expect(MaskEmail::mask($email))->toBe($masked);
})->with([
    ['newaddress@example.com', 'n***@example.com'],
    ['abc@b.io', '***@b.io'],
    ['abcd@b.io', 'a***@b.io'],
    ['a@b.io', '***@b.io'],
    ['Weird+tag@Sub.Example.com', 'W***@Sub.Example.com'],
    ['no-at-sign', '***'],
    ['@example.com', '***'],
]);

it('sends the confirm mail only to the new address', function () {
    // Given
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    // When
    requestEmailChange($user);

    // Then the old address gets only the notice
    Notification::assertSentTo($user, EmailChangeRequestedNotification::class);
    Notification::assertNotSentTo($user, ConfirmEmailChangeNotification::class);
    Notification::assertCount(2);
});

it('refuses a wrong password, an address in use and the current address', function (string $email, string $password, string $errorField) {
    // Given a member and another member's address
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'mine@example.com']);
    Sanctum::actingAs($user);

    // When they ask for the change
    $response = $this->postJson('/api/v1/me/email', ['email' => $email, 'current_password' => $password]);

    // Then it is a plain 422 on the expected field, and the password error wins over "taken"
    $response->assertUnprocessable()->assertJsonValidationErrors($errorField);
    expect(array_keys($response->json('errors')))->toBe([$errorField]);
    expect($user->fresh()->pending_email)->toBeNull();
})->with([
    'wrong password' => ['new@example.com', 'wrong-password', 'current_password'],
    'wrong password and taken' => ['taken@example.com', 'wrong-password', 'current_password'],
    'taken' => ['Taken@example.com', 'correct-horse-battery', 'email'],
    'own address' => ['MINE@example.com', 'correct-horse-battery', 'email'],
]);

it('requires a confirmed email before a change can be requested', function () {
    // Given a member who has not confirmed their email
    Sanctum::actingAs(User::factory()->unverified()->create());

    // When / Then
    $this->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'current_password' => 'correct-horse-battery'])
        ->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('treats a corrupt stored password hash as a wrong password, not a server error', function () {
    // Given a member whose stored hash is foreign
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => 'not-a-hash']);
    Sanctum::actingAs($user->fresh());

    // When they request a change
    $response = $this->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'current_password' => 'whatever-password']);

    // Then it is a validation error
    $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
});

it('rejects non-string input on the request endpoint with 422, never 500', function (array $payload, string $field) {
    // Given a member
    Sanctum::actingAs(User::factory()->create());

    // When
    $response = $this->postJson('/api/v1/me/email', $payload + ['email' => 'new@example.com', 'current_password' => 'correct-horse-battery']);

    // Then
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'email array' => [['email' => ['a@b.io']], 'email'],
    'email object' => [['email' => ['a' => 'b']], 'email'],
    'email empty array' => [['email' => []], 'email'],
    'email null' => [['email' => null], 'email'],
    'email int' => [['email' => 12], 'email'],
    'email bool' => [['email' => true], 'email'],
    'password array' => [['current_password' => ['x']], 'current_password'],
    'password object' => [['current_password' => ['a' => 'b']], 'current_password'],
    'password empty array' => [['current_password' => []], 'current_password'],
    'password null' => [['current_password' => null], 'current_password'],
    'password int' => [['current_password' => 12], 'current_password'],
    'password bool' => [['current_password' => true], 'current_password'],
]);

it('lets a Google-only member change email after a recent sign-in, without a password', function () {
    // Given a Google-only member who signed in 3 minutes ago
    Notification::fake();
    $user = User::factory()->withoutPassword()->lastLoginMinutesAgo(3)->create();
    Sanctum::actingAs($user);

    // When they request the change
    $this->postJson('/api/v1/me/email', ['email' => 'new@example.com'])->assertStatus(202);

    // Then it is pending
    expect($user->fresh()->pending_email)->toBe('new@example.com');

    // And when they last signed in an hour ago, it is refused
    $user->forceFill(['last_login_at' => now()->subHour()])->save();
    $this->postJson('/api/v1/me/email', ['email' => 'other@example.com'])
        ->assertForbidden()->assertJsonPath('code', 'reauth_required');
});

it('limits email-change requests to three per hour', function () {
    // Given a member who already made three requests
    Notification::fake();
    Sanctum::actingAs(User::factory()->create());
    foreach (['a', 'b', 'c'] as $letter) {
        $this->postJson('/api/v1/me/email', ['email' => "$letter@example.com", 'current_password' => 'correct-horse-battery'])
            ->assertStatus(202);
    }

    // When they make a fourth
    $response = $this->postJson('/api/v1/me/email', ['email' => 'd@example.com', 'current_password' => 'correct-horse-battery']);

    // Then it is throttled
    $response->assertTooManyRequests();
});

it('swaps the email when the new address is confirmed, and signs the member out everywhere', function () {
    // Given a member with a pending change, a session and a device token
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com', 'email_verified_at' => null]);
    $user->forceFill(['email_verified_at' => now()->subYear()])->save();
    requestEmailChange($user);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    $user->createToken('phone');

    // When the link from the new address is used (by a client that is not signed in)
    forgetActor();
    $response = $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()));

    // Then the email is swapped and confirmed, pending is cleared, and everything else is signed out
    $response->assertOk()->assertExactJson(['message' => 'Your email address has been updated. Sign in again.']);
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('new@example.com');
    expect($fresh->pending_email)->toBeNull()->and($fresh->pending_email_expires_at)->toBeNull();
    expect($fresh->hasVerifiedEmail())->toBeTrue();
    expect($fresh->email_verified_at->isAfter(now()->subMinute()))->toBeTrue();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect($user->tokens()->count())->toBe(0);
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::EmailChanged)->exists())->toBeTrue();
    $this->assertGuest();
});

it('does not log anyone in when the link is used', function () {
    // Given a pending change
    Notification::fake();
    $user = User::factory()->create();
    requestEmailChange($user);
    forgetActor();

    // When confirming without a session
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()))->assertOk();

    // Then no session cookie authenticates anyone
    $this->assertGuest('web');
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('refuses expired, stale, tampered and taken confirmations with the right errors', function () {
    // Given a member with a pending change
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    $user = $user->fresh();
    forgetActor();

    // When the link is tampered with, it fails its signature
    $this->postJson(str_replace('hash=', 'hash=0', emailChangeLink('auth.email.change.confirm', $user)))->assertForbidden();

    // When the link was issued for an address that is no longer pending, it is stale
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user, 'older@example.com'))
        ->assertUnprocessable()->assertJsonPath('message', 'This link is invalid or has expired.');

    // When someone else registers the address in the meantime, it is a conflict
    User::factory()->create(['email' => 'new@example.com']);
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user))->assertStatus(409);
    expect($user->fresh()->email)->toBe('old@example.com');

    // When the pending change has expired, it is refused with the same generic error
    User::query()->where('email', 'new@example.com')->delete();
    $user->forceFill(['pending_email_expires_at' => now()->subMinute()])->save();
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user))
        ->assertUnprocessable()->assertJsonPath('message', 'This link is invalid or has expired.');
    expect($user->fresh()->email)->toBe('old@example.com');
});

it('refuses a link whose signature has expired', function () {
    // Given a pending change and a link signed 2 hours ago
    Notification::fake();
    $user = User::factory()->create();
    requestEmailChange($user);
    forgetActor();
    $link = emailChangeLink('auth.email.change.confirm', $user->fresh(), minutes: -1);

    // When it is used
    // Then the signature check refuses it
    $this->postJson($link)->assertForbidden();
    expect($user->fresh()->pending_email)->toBe('new@example.com');
});

it('never applies a stale link after a newer request, and the newer link still works', function () {
    // Given a pending change to A, with its link
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user, 'a@example.com');
    $linkA = emailChangeLink('auth.email.change.confirm', $user->fresh());

    // When a newer request replaces it with B
    DB::table('users')->where('id', $user->id)->update(['pending_email' => 'b@example.com']);
    forgetActor();

    // Then A's link is refused and the email is untouched, B's link applies
    $this->postJson($linkA)->assertUnprocessable();
    expect($user->fresh()->email)->toBe('old@example.com');
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()))->assertOk();
    expect($user->fresh()->email)->toBe('b@example.com');
});

it('applies a confirmation only once', function () {
    // Given a confirmed change
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    $link = emailChangeLink('auth.email.change.confirm', $user->fresh());
    forgetActor();
    $this->postJson($link)->assertOk();

    // When the same link is replayed
    $replay = $this->postJson($link);

    // Then it is the generic error and nothing changes
    $replay->assertUnprocessable()->assertJsonPath('message', 'This link is invalid or has expired.');
    expect($user->fresh()->email)->toBe('new@example.com');
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::EmailChanged)->count())->toBe(1);
});

it('refuses to apply a pending change for suspended or deleted members', function (string $column) {
    // Given a pending change on an account that is then suspended or deleted
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    DB::table('users')->where('id', $user->id)->update([$column => now()]);
    forgetActor();

    // When the link is used
    $response = $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()));

    // Then it is the generic error and the pending change is untouched
    $response->assertUnprocessable()->assertJsonPath('message', 'This link is invalid or has expired.');
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('old@example.com')->and($fresh->pending_email)->toBe('new@example.com');
})->with([
    'suspended' => ['suspended_at'],
    'deleted' => ['deleted_at'],
]);

it('blocks suspended members from requesting a change', function () {
    // Given a suspended member
    Sanctum::actingAs(User::factory()->suspended()->create());

    // When / Then
    $this->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'current_password' => 'correct-horse-battery'])
        ->assertForbidden();
});

it('treats non-string id and hash parameters as an invalid link, never a server error', function (string $route, array $extra) {
    // Given a pending change and a correctly signed URL whose id/hash are not strings
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    forgetActor();
    $user = $user->fresh();
    $params = ['id' => $user->id, 'hash' => sha1('new@example.com'), ...$extra];
    $url = URL::temporarySignedRoute($route, now()->addHour(), $params, absolute: false);

    // When it is used
    $response = $this->postJson($url);

    // Then it is refused with 422 and nothing changed
    $response->assertUnprocessable();
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('old@example.com')->and($fresh->pending_email)->toBe('new@example.com');
})->with(function () {
    $values = ['array' => ['x'], 'nested' => ['a' => ['b' => 'c']], 'empty string' => '', 'ulid-ish junk' => 'not-a-ulid'];
    foreach (['auth.email.change.confirm', 'auth.email.change.cancel'] as $route) {
        foreach ($values as $label => $value) {
            yield "$route id $label" => [$route, ['id' => $value]];
            yield "$route hash $label" => [$route, ['hash' => $value]];
        }
    }
});

it('refuses links without id or hash', function (string $route) {
    // Given a signed URL with no parameters
    $url = URL::temporarySignedRoute($route, now()->addHour(), [], absolute: false);

    // When / Then
    $this->postJson($url)->assertUnprocessable();
})->with(['auth.email.change.confirm', 'auth.email.change.cancel']);

it('cancels a pending change from the old address, and is harmless to repeat', function () {
    // Given a member with a pending change
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    $link = emailChangeLink('auth.email.change.cancel', $user->fresh());
    forgetActor();

    // When the cancel link is used, twice
    $first = $this->postJson($link);
    $second = $this->postJson($link);

    // Then the change is gone and the email never moved
    $first->assertOk()->assertJsonPath('code', 'cancelled')->assertJsonPath('message', 'The email change was cancelled.');
    $second->assertOk()->assertJsonPath('code', 'no_pending_change');
    $fresh = $user->fresh();
    expect($fresh->pending_email)->toBeNull()->and($fresh->email)->toBe('old@example.com');
});

it('does not claim a cancellation when the change was already confirmed', function () {
    // Given a change that was confirmed, and the old address's cancel link used afterwards
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    $cancel = emailChangeLink('auth.email.change.cancel', $user->fresh());
    $confirm = emailChangeLink('auth.email.change.confirm', $user->fresh());
    forgetActor();
    $this->postJson($confirm)->assertOk();

    // When the cancel link is used
    $response = $this->postJson($cancel);

    // Then the answer says nothing was cancelled and points at a password reset
    $response->assertOk()->assertJsonPath('code', 'no_pending_change');
    expect($response->json('message'))->toContain('no pending email change')->toContain('reset your password');
    expect($user->fresh()->email)->toBe('new@example.com');
});

it('does not let a stale cancel link clear a newer pending change', function () {
    // Given a pending change to B and a cancel link issued for A
    Notification::fake();
    $user = User::factory()->create();
    requestEmailChange($user, 'b@example.com');
    forgetActor();

    // When the link for A is used
    $response = $this->postJson(emailChangeLink('auth.email.change.cancel', $user->fresh(), 'a@example.com'));

    // Then it is the generic error and B stays pending
    $response->assertUnprocessable()->assertJsonPath('message', 'This link is invalid or has expired.');
    expect($user->fresh()->pending_email)->toBe('b@example.com');
});

it('never lets an old verification link verify the new address', function () {
    // Given a member who is unverified and holds a verification link for the old address
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
    $oldVerification = URL::temporarySignedRoute('auth.email.verify', now()->addHour(),
        ['id' => $user->id, 'hash' => sha1('old@example.com')], absolute: false);
    // And a pending change that gets confirmed (verified.api blocks the request, so set it directly)
    DB::table('users')->where('id', $user->id)->update([
        'pending_email' => 'new@example.com', 'pending_email_expires_at' => now()->addHour(),
    ]);
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()))->assertOk();
    // And the member then marks the new address as unconfirmed again (e.g. an admin reset)
    DB::table('users')->where('id', $user->id)->update(['email_verified_at' => null]);

    // When the old verification link is posted
    Sanctum::actingAs($user->fresh());
    $response = $this->postJson($oldVerification);

    // Then it is refused by the hash check and the new address stays unverified
    $response->assertForbidden()->assertJsonPath('message', '');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    expect($user->fresh()->email)->toBe('new@example.com');
});

it('keeps the verification stamp set by the confirm when an old verification link is replayed', function () {
    // Given a member who is unverified, a link for the old address, and a confirmed change
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
    $oldVerification = URL::temporarySignedRoute('auth.email.verify', now()->addHour(),
        ['id' => $user->id, 'hash' => sha1('old@example.com')], absolute: false);
    DB::table('users')->where('id', $user->id)->update([
        'pending_email' => 'new@example.com', 'pending_email_expires_at' => now()->addHour(),
    ]);
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()))->assertOk();
    $stamped = $user->fresh()->email_verified_at;

    // When the old verification link is replayed by the signed-in member
    Sanctum::actingAs($user->fresh());
    $this->postJson($oldVerification)->assertForbidden();

    // Then the stamp set by the confirm is exactly as it was: set, and not touched
    expect($stamped)->not->toBeNull();
    expect($user->fresh()->email_verified_at->equalTo($stamped))->toBeTrue();
});

it('retries delivery of every queued mail', function (string $class) {
    // Given / When
    $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    // Then
    expect($notification)->toBeInstanceOf(ShouldQueue::class);
    expect($notification->tries)->toBeGreaterThanOrEqual(3);
    expect($notification->backoff())->not->toBeEmpty()->each->toBeInt();
})->with([
    ConfirmEmailChangeNotification::class,
    EmailChangeRequestedNotification::class,
    VerifyEmailNotification::class,
    ResetPasswordNotification::class,
]);

it('rotates the remember-me token and rejects the old remember cookie after an email change', function () {
    // Given a member with a known remember token who is recognised by that cookie
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com', 'remember_token' => 'known-remember-token']);
    $recaller = $user->id.'|known-remember-token|'.Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
    $cookie = Auth::guard('web')->getRecallerName();
    requestEmailChange($user);
    forgetActor();
    $link = emailChangeLink('auth.email.change.confirm', $user->fresh());
    expect(Auth::guard('web')->getRecallerName())->toBe($cookie);
    spa()->withCredentials()->withCookie($cookie, $recaller)->getJson('/api/v1/me')->assertOk();
    forgetActor();

    // When the change is confirmed
    $this->postJson($link)->assertOk();

    // Then the remember token is rotated and the old cookie no longer signs anyone in
    expect($user->fresh()->remember_token)->not->toBe('known-remember-token')->not->toBeEmpty();
    forgetActor();
    // The test app shares one session store between requests; drop what the first request put in it.
    app('session.store')->flush();
    spa()->withCredentials()->withCookie($cookie, $recaller)->getJson('/api/v1/me')->assertUnauthorized();
});

it('clears password-reset tokens for both the old and the new address on confirm', function () {
    // Given reset tokens for both addresses and a pending change
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    foreach (['old@example.com', 'new@example.com'] as $email) {
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'x', 'created_at' => now()]);
    }
    forgetActor();

    // When the change is confirmed
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()))->assertOk();

    // Then neither token survives
    expect(DB::table('password_reset_tokens')->whereIn('email', ['old@example.com', 'new@example.com'])->count())->toBe(0);
});

it('answers 409 when the address is claimed between the check and the write', function () {
    // Given a pending change, and a rival who registers the address right after the availability check
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    $link = emailChangeLink('auth.email.change.confirm', $user->fresh());
    forgetActor();
    $armed = true;
    DB::listen(function ($query) use (&$armed) {
        if ($armed && str_contains($query->sql, 'select exists') && str_contains($query->sql, '"users"')) {
            $armed = false;
            User::factory()->create(['email' => 'new@example.com']);
        }
    });

    // When the link is used
    $response = $this->postJson($link);

    // Then the unique violation is resolved by re-reading the data: 409, nothing changed
    $response->assertStatus(409);
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('old@example.com')->and($fresh->pending_email)->toBe('new@example.com');
});

it('rethrows a unique violation that is not about the address being taken', function () {
    // Given a pending change, and a write that collides on another unique column (the handle)
    Notification::fake();
    User::factory()->create(['handle' => 'taken_handle']);
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user);
    $link = emailChangeLink('auth.email.change.confirm', $user->fresh());
    forgetActor();
    User::updating(fn (User $u) => $u->handle = 'taken_handle');

    // When the link is used
    $response = $this->postJson($link);

    // Then it is a real server error, not a misleading 409, and nothing changed
    $response->assertStatus(500);
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('old@example.com')->and($fresh->pending_email)->toBe('new@example.com');
});

it('mails nothing and writes nothing when the request transaction rolls back', function () {
    // Given the real queue and a dispatcher that fails on the second notification
    config(['queue.default' => 'database']);
    $user = User::factory()->create(['email' => 'old@example.com']);
    app()->extend(NotificationDispatcher::class, fn ($real) => new class($real) implements NotificationDispatcher
    {
        private int $sent = 0;

        public function __construct(private readonly NotificationDispatcher $real) {}

        public function send($notifiables, $notification): void
        {
            if (++$this->sent === 2) {
                throw new RuntimeException('queue unavailable');
            }
            $this->real->send($notifiables, $notification);
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            $this->real->sendNow($notifiables, $notification, $channels);
        }
    });
    Sanctum::actingAs($user);

    // When the member asks for the change
    $response = $this->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'current_password' => 'correct-horse-battery']);

    // Then it fails, the pending change is rolled back and no job was queued
    $response->assertStatus(500);
    expect($user->fresh()->pending_email)->toBeNull();
    expect(DB::table('jobs')->count())->toBe(0);
});

it('queues both mails only after commit, and encrypts them at rest', function () {
    // Given the real database queue
    config(['queue.default' => 'database']);
    $user = User::factory()->create(['email' => 'old@example.com']);

    // When a change is requested
    requestEmailChange($user, 'newaddress@example.com');

    // Then two jobs are stored and neither contains the new address in clear text
    $payloads = DB::table('jobs')->pluck('payload');
    expect($payloads)->toHaveCount(2);
    foreach ($payloads as $payload) {
        expect($payload)->not->toContain('newaddress@example.com')->not->toContain('old@example.com');
    }
});

it('lets a Google-only member run the whole request and confirm cycle and stay Google-linked', function () {
    // Given a Google-only member who just signed in
    Notification::fake();
    $user = User::factory()->withoutPassword()->lastLoginMinutesAgo(2)->create(['email' => 'old@example.com']);
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'g-1']);
    Sanctum::actingAs($user);

    // When they request the change without a password and confirm it from the new address
    $this->postJson('/api/v1/me/email', ['email' => 'new@example.com'])->assertStatus(202);
    forgetActor();
    $this->postJson(emailChangeLink('auth.email.change.confirm', $user->fresh()))->assertOk();

    // Then the email moved, there is still no password, and the Google link is untouched
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('new@example.com')->and($fresh->hasPassword())->toBeFalse();
    expect(SocialAccount::query()->where('user_id', $user->id)->pluck('provider_user_id')->all())->toBe(['g-1']);
});

it('rejects quoted local parts and IP-literal domains on the request endpoint', function (string $email) {
    // Given a member
    Sanctum::actingAs(User::factory()->create());

    // When
    $response = $this->postJson('/api/v1/me/email', ['email' => $email, 'current_password' => 'correct-horse-battery']);

    // Then
    $response->assertUnprocessable()->assertJsonValidationErrors('email');
})->with(['"<script>"@example.com', 'a@[127.0.0.1]']);

it('retries a failing mail provider with the declared backoff, then fails exactly once', function (string $kind) {
    // Given the real database queue and a mail provider that is down
    config(['queue.default' => 'database', 'mail.default' => 'down', 'mail.mailers.down' => ['transport' => 'down']]);
    Mail::extend('down', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('provider down');
        }

        public function __toString(): string
        {
            return 'down';
        }
    });
    $user = User::factory()->create(['email' => 'old@example.com']);
    $kind === 'confirm'
        ? Notification::route('mail', 'new@example.com')
            ->notify(new ConfirmEmailChangeNotification((string) $user->id, 'new@example.com', now()->addHour()))
        : $user->notify(new VerifyEmailNotification);
    expect(DB::table('jobs')->count())->toBe(1);

    // When the worker runs the job eight times, making it due again each time
    $delays = [];
    for ($attempt = 1; $attempt <= 8; $attempt++) {
        DB::table('jobs')->update(['available_at' => time()]);
        Artisan::call('queue:work', ['--once' => true, '--tries' => 0, '--sleep' => 0]);
        $job = DB::table('jobs')->first();
        $delays[] = $job === null ? null : $job->available_at - time();
    }

    // Then each failure was released with 60, 120, 300, 900, 1800, 3600 and 3600 seconds, and the eighth one failed the job for good
    foreach ([60, 120, 300, 900, 1800, 3600, 3600] as $i => $expected) {
        expect($delays[$i])->toBeGreaterThanOrEqual($expected - 3)->toBeLessThanOrEqual($expected);
    }
    expect($delays[7])->toBeNull();
    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(1);
    DB::table('failed_jobs')->delete();
})->with([
    'confirm notification (on-demand)' => ['confirm'],
    'verification notification' => ['verify'],
]);
