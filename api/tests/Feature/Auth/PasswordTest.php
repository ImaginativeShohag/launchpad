<?php

use App\Enums\AuthEventType;
use App\Jobs\SendPasswordResetLink;
use App\Models\AuthEvent;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function plantSession(User $user, string $id = 'other-session'): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time(),
    ]);
}

it('answers forgot-password the same way for known and unknown emails', function () {
    // Given one real member
    Notification::fake();
    $user = User::factory()->create(['email' => 'amina@example.com']);

    // When a reset is requested for a real and for an unknown address
    $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'amina@example.com']);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com']);

    // Then both get the identical 202, and only the real member is emailed
    $known->assertStatus(202);
    $unknown->assertStatus(202);
    expect($unknown->json())->toBe($known->json());
    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    Notification::assertCount(1);
    expect(AuthEvent::query()->where('event', AuthEventType::PasswordResetRequested)->count())->toBe(2);
});

it('points the reset email at the web app', function () {
    // Given a member and a reset token
    $user = User::factory()->create(['email' => 'amina@example.com']);

    // When the reset mail is composed
    $mail = (new ResetPasswordNotification('abc123'))->toMail($user);

    // Then the link goes to the React site with token and email
    expect($mail->actionUrl)->toBe('http://localhost:5173/reset-password?token=abc123&email=amina%40example.com');
});

it('resets the password once and signs the member out everywhere', function () {
    // Given a member with a session and a device token, and a valid reset token
    $user = User::factory()->create(['email' => 'amina@example.com', 'remember_token' => 'known-remember-token']);
    plantSession($user);
    $user->createToken('phone');
    $token = PasswordBroker::createToken($user);

    // When they reset their password
    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ]);

    // Then the new password works, every session and token is gone, and it is recorded
    $response->assertOk();
    expect(Hash::check('a-brand-new-passphrase', $user->fresh()->password))->toBeTrue();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect($user->tokens()->count())->toBe(0);
    expect($user->fresh()->remember_token)->not->toBe('known-remember-token')->not->toBeEmpty();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::PasswordReset)->exists())->toBeTrue();

    // And the same token cannot be used twice
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => $token, 'password' => 'yet-another-passphrase',
    ])->assertUnprocessable();
});

it('gives the same error for a bad token and an unknown email', function () {
    // Given a member
    User::factory()->create(['email' => 'amina@example.com']);

    // When the token is wrong, and when the email is unknown
    $badToken = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => 'nope', 'password' => 'a-brand-new-passphrase',
    ]);
    $unknown = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'ghost@example.com', 'token' => 'nope', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then the responses are identical
    $badToken->assertUnprocessable();
    expect($unknown->json())->toBe($badToken->json());
});

it('lets a Google-only member set a first password through the reset flow', function () {
    // Given a Google-only member
    $user = User::factory()->withoutPassword()->create(['email' => 'amina@example.com']);
    $token = PasswordBroker::createToken($user);

    // When they complete a reset
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ])->assertOk();

    // Then they now have a password
    expect($user->fresh()->hasPassword())->toBeTrue();
});

it('changes the password and signs out the other devices only', function () {
    // Given a member on two devices and another session
    $user = User::factory()->create(['remember_token' => 'known-remember-token']);
    plantSession($user);
    $current = $user->createToken('this-phone');
    $user->createToken('old-tablet');

    // When they change their password using this phone's token
    $response = $this->withToken($current->plainTextToken)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then it succeeds, the other session and token are revoked, this phone stays signed in
    $response->assertNoContent();
    expect(Hash::check('a-brand-new-passphrase', $user->fresh()->password))->toBeTrue();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect($user->tokens()->pluck('name')->all())->toBe(['this-phone']);
    // Accepted: remember-me cookies on other devices are signed out too
    expect($user->fresh()->remember_token)->not->toBe('known-remember-token');
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::PasswordChanged)->exists())->toBeTrue();
});

it('rejects a wrong current password and a reused password', function () {
    // Given a signed-in member
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;

    // When the current password is wrong
    $wrong = $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'not-my-password', 'password' => 'a-brand-new-passphrase',
    ]);
    // And when the new password equals the old one
    $same = $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery', 'password' => 'correct-horse-battery',
    ]);

    // Then both are refused
    $wrong->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $same->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('does not offer change-password to a Google-only member', function () {
    // Given a Google-only member
    $token = User::factory()->withoutPassword()->create()->createToken('phone')->plainTextToken;

    // When they try to change a password they do not have
    $response = $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'x', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then they are pointed to the reset flow
    $response->assertForbidden()->assertJsonPath('code', 'no_password');
});

it('answers forgot-password identically for suspended and deleted accounts and sends them nothing', function (string $state) {
    // Given a suspended or a deleted account, and a normal one for comparison
    Notification::fake();
    $user = $state === 'suspended'
        ? User::factory()->suspended()->create(['email' => 'blocked@example.com'])
        : User::factory()->create(['email' => 'blocked@example.com', 'deleted_at' => now()]);
    $normal = User::factory()->create(['email' => 'amina@example.com']);

    // When a reset is requested for each
    $blocked = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'blocked@example.com']);
    $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'amina@example.com']);

    // Then the answers are identical, only the normal account is emailed, and the request is logged
    $blocked->assertStatus(202);
    expect($blocked->json())->toBe($known->json());
    Notification::assertNothingSentTo($user);
    Notification::assertSentToTimes($normal, ResetPasswordNotification::class, 1);
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::PasswordResetRequested)->exists())->toBeTrue();
})->with(['suspended', 'deleted']);

it('treats a throttled broker like any other forgot-password request', function () {
    // Given a member who already asked within the broker throttle window
    Notification::fake();
    $user = User::factory()->create(['email' => 'amina@example.com']);
    $first = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'amina@example.com']);

    // When they ask again
    $second = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'amina@example.com']);

    // Then the answer is the same and no second mail is sent
    $second->assertStatus(202);
    expect($second->json())->toBe($first->json());
    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
});

it('normalises the email on forgot and reset', function () {
    // Given a member
    Notification::fake();
    $user = User::factory()->create(['email' => 'amina@example.com']);

    // When the email is typed with capitals and spaces
    $this->postJson('/api/v1/auth/password/forgot', ['email' => '  Amina@Example.COM '])->assertStatus(202);
    $token = PasswordBroker::createToken($user);
    $reset = $this->postJson('/api/v1/auth/password/reset', [
        'email' => ' AMINA@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ]);

    // Then both find the member
    Notification::assertSentTo($user, ResetPasswordNotification::class);
    $reset->assertOk();
});

it('does not sign the member in after a reset', function () {
    // Given a valid reset token
    $user = User::factory()->create(['email' => 'amina@example.com']);
    $token = PasswordBroker::createToken($user);

    // When the reset is completed from a browser
    spa()->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ])->assertOk();

    // Then nobody is signed in and the remember token was rotated
    $this->assertGuest('web');
    expect($user->fresh()->remember_token)->not->toBe($user->remember_token);
});

it('refuses to reset a suspended or deleted account with the generic error', function (string $state) {
    // Given a suspended or deleted account with a valid token, and a bad-token baseline
    $user = $state === 'suspended'
        ? User::factory()->suspended()->create(['email' => 'blocked@example.com'])
        : User::factory()->create(['email' => 'blocked@example.com', 'deleted_at' => now()]);
    $oldHash = $user->password;
    $token = PasswordBroker::createToken($user);
    $baseline = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'ghost@example.com', 'token' => 'nope', 'password' => 'a-brand-new-passphrase',
    ]);

    // When the valid token is used
    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'blocked@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ]);

    // Then it fails exactly like a bad token and the password is untouched
    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($response->json())->toBe($baseline->json());
    expect($user->fresh()->password)->toBe($oldHash);
})->with(['suspended', 'deleted']);

it('keeps the reset token usable when the new password is too weak', function () {
    // Given a valid token
    $user = User::factory()->create(['email' => 'amina@example.com']);
    $token = PasswordBroker::createToken($user);

    // When a weak password is submitted and then a good one
    $weak = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => $token, 'password' => 'short',
    ]);
    $good = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ]);

    // Then the first is a validation error and the second succeeds
    $weak->assertUnprocessable()->assertJsonValidationErrors('password');
    $good->assertOk();
});

it('keeps the current browser session when changing the password', function () {
    // Given a member signed in on a browser, with this session and another stored
    $user = User::factory()->create();
    $current = Str::random(40);
    plantSession($user, $current);
    plantSession($user, 'other-session');

    // When they change their password from this browser
    $response = spa()->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), $current)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then only the other session is revoked
    $response->assertNoContent();
    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$current]);
});

it('lets an unverified member change their password', function () {
    // Given an unverified member
    $user = User::factory()->unverified()->create();
    $token = $user->createToken('phone')->plainTextToken;

    // When they change the password
    $response = $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then it works
    $response->assertNoContent();
});

it('blocks a suspended member from changing the password', function () {
    // Given a suspended member with a token
    $token = User::factory()->suspended()->create()->createToken('phone')->plainTextToken;

    // When they try
    $response = $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then they are refused
    $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('requires authentication to change the password', function () {
    // Given no credentials
    // When a guest tries
    $response = $this->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then they are unauthenticated
    $response->assertUnauthorized();
});

it('treats a stored hash the driver cannot read as a wrong current password, not a 500', function (string $hash) {
    // Given a member whose stored hash is foreign or malformed
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
    $token = $user->createToken('phone')->plainTextToken;

    // When they try to change the password
    $response = $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then it is a validation error on current_password
    $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
})->with([
    'garbage' => ['garbage'],
    'foreign algorithm' => ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaGhhc2g'],
]);

dataset('badShapes', function () {
    yield 'empty array' => [[]];
    yield 'list' => [['a']];
    yield 'object' => [['k' => 'v']];
    yield 'null' => [null];
    yield 'empty string' => [''];
    yield 'int' => [123];
    yield 'bool' => [true];
});

it('rejects badly shaped forgot-password input with 422, never 500', function (mixed $value) {
    // Given a member
    Notification::fake();
    User::factory()->create(['email' => 'amina@example.com']);

    // When the email has the wrong shape
    $response = $this->postJson('/api/v1/auth/password/forgot', ['email' => $value]);

    // Then validation rejects it and nothing is sent
    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    Notification::assertNothingSent();
})->with('badShapes');

it('rejects badly shaped reset-password input with 422, never 500', function (string $field, mixed $value) {
    // Given a member and a payload where one field is malformed
    $user = User::factory()->create(['email' => 'amina@example.com']);
    $payload = array_merge([
        'email' => 'amina@example.com', 'token' => PasswordBroker::createToken($user), 'password' => 'a-brand-new-passphrase',
    ], [$field => $value]);

    // When it is posted
    $response = $this->postJson('/api/v1/auth/password/reset', $payload);

    // Then it is a validation error and the password is unchanged
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect(Hash::check('correct-horse-battery', $user->fresh()->password))->toBeTrue();
})->with(function () {
    foreach (['email', 'token', 'password'] as $field) {
        foreach ([[], ['a'], ['k' => 'v'], null, '', 123, true] as $i => $value) {
            yield "$field #$i" => [$field, $value];
        }
    }
});

it('rejects badly shaped change-password input with 422, never 500', function (string $field, mixed $value) {
    // Given a signed-in member and a payload where one field is malformed
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;
    $payload = array_merge([
        'current_password' => 'correct-horse-battery', 'password' => 'a-brand-new-passphrase',
    ], [$field => $value]);

    // When it is sent
    $response = $this->withToken($token)->putJson('/api/v1/me/password', $payload);

    // Then it is a validation error and the password is unchanged
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect(Hash::check('correct-horse-battery', $user->fresh()->password))->toBeTrue();
})->with(function () {
    foreach (['current_password', 'password'] as $field) {
        foreach ([[], ['a'], ['k' => 'v'], null, '', 123, true] as $i => $value) {
            yield "$field #$i" => [$field, $value];
        }
    }
});

function forgotScenario(): void
{
    User::factory()->create(['email' => 'amina@example.com']);
    User::factory()->suspended()->create(['email' => 'banned@example.com']);
    User::factory()->create(['email' => 'gone@example.com', 'deleted_at' => now()]);
}

it('queues exactly one job per forgot request and answers byte-identically', function () {
    // Given a known, a suspended and a deleted account, and a fake queue
    Queue::fake();
    forgotScenario();

    // When forgot-password is requested for those and for an unknown email
    $responses = collect(['amina@example.com', 'banned@example.com', 'gone@example.com', 'ghost@example.com'])
        ->map(fn ($email) => $this->postJson('/api/v1/auth/password/forgot', ['email' => $email]));

    // Then each dispatched exactly one job and the bodies are identical
    Queue::assertPushed(SendPasswordResetLink::class, 4);
    foreach ($responses as $response) {
        $response->assertStatus(202);
        expect($response->getContent())->toBe($responses[0]->getContent());
    }
});

it('does no account lookup, hashing or audit write in the forgot request path', function () {
    // Given the four situations, a fake queue and a spy on the hasher
    Queue::fake();
    forgotScenario();
    Hash::spy();
    $before = AuthEvent::query()->count();

    // When forgot-password is requested for each
    foreach (['amina@example.com', 'banned@example.com', 'gone@example.com', 'ghost@example.com'] as $email) {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])->assertStatus(202);
    }

    // Then nothing was hashed and nothing was written during the requests
    Hash::shouldNotHaveReceived('make');
    expect(AuthEvent::query()->count())->toBe($before);
});

it('does not queue a job for a malformed forgot payload', function (mixed $value) {
    // Given a fake queue
    Queue::fake();

    // When the email is malformed
    $this->postJson('/api/v1/auth/password/forgot', ['email' => $value])->assertUnprocessable();

    // Then nothing is queued
    Queue::assertNothingPushed();
})->with('badShapes');

it('sends the reset link and records the request when the job runs for an eligible member', function () {
    // Given an eligible member
    Notification::fake();
    $user = User::factory()->create(['email' => 'amina@example.com']);

    // When the job runs
    (new SendPasswordResetLink('amina@example.com'))->handle();

    // Then one mail goes out and the request is logged with the member's id
    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::PasswordResetRequested)->count())->toBe(1);
});

it('sends nothing and logs a null user for an unknown email', function () {
    // Given no such account
    Notification::fake();

    // When the job runs
    (new SendPasswordResetLink('ghost@example.com'))->handle();

    // Then nothing is sent and the request is logged without a user
    Notification::assertNothingSent();
    expect(AuthEvent::query()->whereNull('user_id')->where('event', AuthEventType::PasswordResetRequested)->count())->toBe(1);
});

it('sends nothing for a suspended or deleted account when the job runs', function (string $state) {
    // Given a suspended or deleted account
    Notification::fake();
    $user = $state === 'suspended'
        ? User::factory()->suspended()->create(['email' => 'blocked@example.com'])
        : User::factory()->create(['email' => 'blocked@example.com', 'deleted_at' => now()]);

    // When the job runs
    (new SendPasswordResetLink('blocked@example.com'))->handle();

    // Then no mail is sent, but the request is logged with the id
    Notification::assertNothingSent();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::PasswordResetRequested)->exists())->toBeTrue();
})->with(['suspended', 'deleted']);

it('encrypts the queued payload at rest', function () {
    // Given the real database queue
    config(['queue.default' => 'database']);

    // When a forgot-password job is dispatched
    SendPasswordResetLink::dispatch('amina@example.com');

    // Then the stored payload does not contain the email address in clear text
    $payload = (string) DB::table('jobs')->value('payload');
    expect($payload)->not->toBeEmpty()->and($payload)->not->toContain('amina@example.com');
});

it('lets a failing job bubble up and logs nothing until it succeeds', function () {
    // Given an eligible member and a broker that fails once, then works
    $user = User::factory()->create(['email' => 'amina@example.com']);
    PasswordBroker::shouldReceive('sendResetLink')->once()->andThrow(new RuntimeException('mail provider down'));
    PasswordBroker::shouldReceive('sendResetLink')->once()->andReturn(PasswordBroker::RESET_LINK_SENT);
    $job = new SendPasswordResetLink('amina@example.com');

    // When the job runs and fails
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    // Then no audit row exists yet
    expect(AuthEvent::query()->where('event', AuthEventType::PasswordResetRequested)->count())->toBe(0);

    // And the retry succeeds with exactly one audit row
    $job->handle();
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::PasswordResetRequested)->count())->toBe(1);
});

it('treats a throttled broker as done', function () {
    // Given a broker that reports a throttled request
    User::factory()->create(['email' => 'amina@example.com']);
    PasswordBroker::shouldReceive('sendResetLink')->once()->andReturn(PasswordBroker::RESET_THROTTLED);

    // When the job runs
    (new SendPasswordResetLink('amina@example.com'))->handle();

    // Then it does not throw and the request is logged
    expect(AuthEvent::query()->where('event', AuthEventType::PasswordResetRequested)->count())->toBe(1);
});

it('retries with a first backoff longer than the broker throttle and reports final failure', function () {
    // Given the job
    Exceptions::fake();
    $job = new SendPasswordResetLink('amina@example.com');

    // When inspected and failed
    $job->failed(new RuntimeException('gave up'));

    // Then it retries eight times, the first wait exceeds 60s, and the failure is reported
    expect($job->tries)->toBe(8)->and($job->backoff()[0])->toBeGreaterThan(60);
    Exceptions::assertReported(RuntimeException::class);
});

it('always calls the broker first and answers a reset identically for every kind of account', function () {
    // Given unknown, suspended, deleted accounts (with valid tokens) and a bad token
    $suspended = User::factory()->suspended()->create(['email' => 'banned@example.com']);
    $deleted = User::factory()->create(['email' => 'gone@example.com', 'deleted_at' => now()]);
    User::factory()->create(['email' => 'amina@example.com']);
    $cases = [
        ['ghost@example.com', 'nope'],
        ['banned@example.com', PasswordBroker::createToken($suspended)],
        ['gone@example.com', PasswordBroker::createToken($deleted)],
        ['amina@example.com', 'nope'],
    ];

    // And the real broker wrapped so we can count calls and watch for earlier user queries
    $real = app('auth.password')->broker();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $usersQueriesBeforeBroker = [];
    $calls = 0;
    PasswordBroker::partialMock()->shouldReceive('reset')->times(4)->andReturnUsing(
        function (...$args) use ($real, &$queries, &$usersQueriesBeforeBroker, &$calls) {
            $calls++;
            $usersQueriesBeforeBroker[] = collect($queries)->filter(fn ($q) => str_contains($q, 'from "users"'))->count();

            return $real->reset(...$args);
        }
    );

    // When each is submitted (clearing the query log between requests)
    $bodies = [];
    foreach ($cases as [$email, $token]) {
        $queries = [];
        $response = $this->postJson('/api/v1/auth/password/reset', [
            'email' => $email, 'token' => $token, 'password' => 'a-brand-new-passphrase',
        ]);
        $response->assertUnprocessable();
        $bodies[] = $response->getContent();
    }

    // Then the broker ran once per request before any users query, and every body is identical
    expect($calls)->toBe(4)->and($usersQueriesBeforeBroker)->toBe([0, 0, 0, 0]);
    expect(array_unique($bodies))->toHaveCount(1);
});

it('does not consume the token or revoke anything when a suspended member resets', function () {
    // Given a suspended member with a valid token, a session and a device token
    $user = User::factory()->suspended()->create(['email' => 'banned@example.com']);
    plantSession($user);
    $user->createToken('phone');
    $oldHash = $user->password;
    $token = PasswordBroker::createToken($user);

    // When the reset is attempted
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'banned@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ])->assertUnprocessable();

    // Then the token row, the password, the session and the device token are all untouched
    expect(DB::table('password_reset_tokens')->where('email', 'banned@example.com')->exists())->toBeTrue();
    expect($user->fresh()->password)->toBe($oldHash);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
    expect($user->tokens()->count())->toBe(1);
});

it('rejects an expired reset token with the generic error', function () {
    // Given a token issued more than an hour ago
    $user = User::factory()->create(['email' => 'amina@example.com']);
    $token = PasswordBroker::createToken($user);
    $this->travel(61)->minutes();

    // When it is used
    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'amina@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ]);

    // Then it fails like any bad token
    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    expect(Hash::check('correct-horse-battery', $user->fresh()->password))->toBeTrue();
});

it('rejects a valid token used with another member\'s email', function () {
    // Given two members and a token for the first
    $amina = User::factory()->create(['email' => 'amina@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    $token = PasswordBroker::createToken($amina);

    // When it is submitted with the second member's email
    $response = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'other@example.com', 'token' => $token, 'password' => 'a-brand-new-passphrase',
    ]);

    // Then it is the generic error and nobody's password changed
    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    expect(Hash::check('correct-horse-battery', $other->fresh()->password))->toBeTrue();
    expect(Hash::check('correct-horse-battery', $amina->fresh()->password))->toBeTrue();
});

it('caps forgot and reset together at 20 per hour per ip', function () {
    // Given 10 forgot and 10 reset requests for different emails
    Queue::fake();
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => "f{$i}@example.com"])->assertStatus(202);
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => "r{$i}@example.com", 'token' => 'nope', 'password' => 'a-brand-new-passphrase',
        ])->assertUnprocessable();
    }

    // When the 21st arrives on either endpoint
    $forgot = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'late@example.com']);

    // Then it is throttled
    $forgot->assertStatus(429);
});

it('throttles repeated wrong current passwords on change-password', function () {
    // Given a member with a token who guesses wrongly five times
    $token = User::factory()->create()->createToken('phone')->plainTextToken;
    foreach (range(1, 5) as $_) {
        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => 'guess-guess-guess', 'password' => 'a-brand-new-passphrase',
        ])->assertUnprocessable();
    }

    // When a sixth guess arrives
    $response = $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'guess-guess-guess', 'password' => 'a-brand-new-passphrase',
    ]);

    // Then it is throttled
    $response->assertStatus(429);
});
