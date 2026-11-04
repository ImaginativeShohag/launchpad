<?php

use App\Actions\Auth\ConfirmEmail;
use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

function verifyUrl(User $user, ?string $emailForHash = null, int $minutes = 60): string
{
    return URL::temporarySignedRoute(
        'auth.email.verify',
        now()->addMinutes($minutes),
        ['id' => $user->id, 'hash' => sha1($emailForHash ?? $user->email)],
        absolute: false,
    );
}

it('builds a link to the web app that carries the signed query string', function () {
    // Given an unverified member
    $user = User::factory()->unverified()->create();

    // When the confirmation mail is composed
    $mail = (new VerifyEmailNotification)->toMail($user);

    // Then the button points at the React site and carries id, hash, expires and signature
    expect($mail->actionUrl)->toStartWith('http://localhost:5173/verify-email?')
        ->toContain('id='.$user->id)->toContain('hash=')->toContain('expires=')->toContain('signature=');
});

it('confirms the email when the signed link is used', function () {
    // Given an unverified member holding a valid link
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    // When the web app posts the link's query string
    $response = $this->postJson(verifyUrl($user));

    // Then the email is confirmed and the event is recorded
    $response->assertOk()->assertJsonPath('data.email_verified', true);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::EmailVerified)->count())->toBe(1);
});

it('treats a second use of the link as harmless', function () {
    // Given a member who already confirmed
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When the link is used again
    $response = $this->postJson(verifyUrl($user));

    // Then it still succeeds and no second event is written
    $response->assertOk();
    expect(AuthEvent::query()->where('event', AuthEventType::EmailVerified)->count())->toBe(0);
});

it('refuses tampered, expired, foreign and wrong-address links', function () {
    // Given an unverified member and a second member
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    // When the signature is tampered with
    $tampered = str_replace('hash=', 'hash=0', verifyUrl($user));
    // Then it is refused
    $this->postJson($tampered)->assertForbidden();

    // When the link is signed for another address
    $this->postJson(verifyUrl($user, 'someone-else@example.com'))->assertForbidden();

    // When the link belongs to another member
    $this->postJson(verifyUrl($other))->assertForbidden();

    // When the link has expired
    $link = verifyUrl($user);
    $this->travel(61)->minutes();
    $this->postJson($link)->assertForbidden();

    // Then nothing was confirmed
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends the confirmation email, at most once a minute', function () {
    // Given an unverified member
    Notification::fake();
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    // When they ask for another email
    $this->postJson('/api/v1/auth/email/resend')->assertNoContent();

    // Then it is sent, and an immediate second request is throttled
    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    $this->postJson('/api/v1/auth/email/resend')->assertTooManyRequests();
});

it('sends nothing for a member who is already confirmed', function () {
    // Given a confirmed member
    Notification::fake();
    Sanctum::actingAs(User::factory()->create());

    // When they ask for another email
    $response = $this->postJson('/api/v1/auth/email/resend');

    // Then it is a quiet no-op
    $response->assertNoContent();
    Notification::assertNothingSent();
});

it('never answers 500 for array-valued or empty id and hash parameters', function (mixed $id, mixed $hash) {
    // Given an unverified member and a correctly signed link whose id/hash are not plain strings
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);
    $url = URL::temporarySignedRoute(
        'auth.email.verify',
        now()->addMinutes(60),
        ['id' => $id ?? $user->id, 'hash' => $hash ?? sha1($user->email)],
        absolute: false,
    );

    // When the web app posts it
    $response = $this->postJson($url);

    // Then it is refused as a failed check, never a server error
    $response->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with([
    'id array' => [['x'], null],
    'id empty array' => [[], null],
    'id nested' => [['a' => ['b' => 'c']], null],
    'hash array' => [null, ['x']],
    'hash empty array' => [null, []],
    'hash nested' => [null, ['a' => ['b' => 'c']]],
    'id and hash arrays' => [['x'], ['y']],
]);

it('refuses a signed link with missing id and hash', function () {
    // Given an unverified member and a signed link without id or hash
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);
    $url = URL::temporarySignedRoute('auth.email.verify', now()->addMinutes(60), [], absolute: false);

    // When it is posted
    // Then it is refused
    $this->postJson($url)->assertForbidden();
});

it('refuses unauthenticated and suspended callers', function () {
    // Given a valid link
    $user = User::factory()->unverified()->create();
    $url = verifyUrl($user);

    // When nobody is signed in
    $this->postJson($url)->assertUnauthorized();

    // When the owner is suspended and uses a token
    $suspended = User::factory()->unverified()->suspended()->create();
    $token = $suspended->createToken('phone')->plainTextToken;
    $this->withToken($token)->postJson(verifyUrl($suspended))->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    // Then nothing was confirmed
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    expect($suspended->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses an old link after the member changed their email', function () {
    // Given a link issued for the old address
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);
    $link = verifyUrl($user);

    // When the email changes in the database and the old link is used
    DB::table('users')->where('id', $user->id)->update(['email' => 'new-address@example.com']);
    Sanctum::actingAs($user->fresh());
    $this->postJson($link)->assertForbidden();

    // Then nothing was confirmed
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a link whose query parameters were reordered', function () {
    // Given a valid link
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);
    $link = verifyUrl($user);

    // When its parameters are reordered (signed:relative is order-sensitive, so the
    // web app must forward the query string verbatim)
    [$path, $query] = explode('?', $link, 2);
    $reordered = $path.'?'.implode('&', array_reverse(explode('&', $query)));
    $response = $this->postJson($reordered);

    // Then it is refused
    $response->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('ignores a JSON body on the verify route', function () {
    // Given a valid link
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    // When the body carries array id, hash and signature
    $response = $this->postJson(verifyUrl($user), ['id' => ['x'], 'hash' => ['y'], 'signature' => ['z']]);

    // Then only the signed query string counts
    $response->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('does not allow GET on the verify route', function () {
    // Given a valid link
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    // When it is fetched with GET
    // Then the method is not allowed
    $this->getJson(verifyUrl($user))->assertStatus(405);
});

it('refuses resend for unauthenticated and suspended callers', function () {
    // Given nobody is signed in
    // When they ask for a resend
    // Then 401
    $this->postJson('/api/v1/auth/email/resend')->assertUnauthorized();

    // Given a suspended member with a token
    Notification::fake();
    $suspended = User::factory()->unverified()->suspended()->create();
    $token = $suspended->createToken('phone')->plainTextToken;

    // When they ask for a resend
    $response = $this->withToken($token)->postJson('/api/v1/auth/email/resend');

    // Then they are refused and nothing is sent
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    Notification::assertNothingSent();
});

it('caps resends at six an hour', function () {
    // Given an unverified member
    Notification::fake();
    Sanctum::actingAs(User::factory()->unverified()->create());

    // When they resend six times, a minute apart
    foreach (range(1, 6) as $ignored) {
        $this->postJson('/api/v1/auth/email/resend')->assertNoContent();
        $this->travel(61)->seconds();
    }

    // Then the seventh within the hour is throttled
    $this->postJson('/api/v1/auth/email/resend')->assertTooManyRequests();
});

it('confirms exactly once even when the action runs twice', function () {
    // Given an unverified member
    $user = User::factory()->unverified()->create();
    $same = User::query()->findOrFail($user->id);

    // When the action runs twice, as two concurrent requests would
    app(ConfirmEmail::class)->handle($user);
    app(ConfirmEmail::class)->handle($same);

    // Then one event is recorded
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::EmailVerified)->count())->toBe(1);
});

it('does not stamp a new address that replaced the one being verified', function () {
    // Given a model loaded for the old address
    $user = User::factory()->unverified()->create();
    $stale = User::query()->findOrFail($user->id);

    // When the email changes in the database before the action runs
    DB::table('users')->where('id', $user->id)->update(['email' => 'changed@example.com']);
    app(ConfirmEmail::class)->handle($stale);

    // Then the new address stays unverified and no event is written
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    expect(AuthEvent::query()->where('event', AuthEventType::EmailVerified)->count())->toBe(0);
});
