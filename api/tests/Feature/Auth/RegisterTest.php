<?php

use App\Actions\Auth\RegisterUser;
use App\Enums\AuthEventType;
use App\Listeners\RecordAuthEvent;
use App\Models\AuthEvent;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Rules\Handle;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Amina Rahman',
        'email' => 'Amina@Example.com',
        'password' => 'correct-horse-battery',
        'handle' => 'amina_r',
    ], $overrides);
}

/** A syntactically valid address of exactly $length characters (64-char local part, 60-char labels). */
function emailOfLength(int $length): string
{
    $domain = $length - 65;
    $labels = [];
    while ($domain > 0) {
        $label = min(60, $domain);
        if ($domain - $label === 1) {
            $label--;
        }
        $labels[] = str_repeat('b', $label);
        $domain -= $label + 1;
    }

    return str_repeat('a', 64).'@'.implode('.', $labels);
}

it('registers a member from the web app, signs them in and queues one confirmation email', function () {
    // Given a visitor on the React site
    Notification::fake();

    // When they register
    $response = spa()->postJson('/api/v1/auth/register', registrationPayload());

    // Then the account exists, normalised, hashed, unverified, and they are signed in
    $response->assertCreated()
        ->assertJsonPath('data.handle', 'amina_r')
        ->assertJsonPath('data.email', 'amina@example.com')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.role', 'member')
        ->assertJsonMissingPath('data.password');
    $user = User::query()->where('email', User::normaliseEmail('amina@example.com'))->firstOrFail();
    expect(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);

    // And exactly one confirmation email is queued, and the signup is recorded
    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    expect(AuthEvent::query()->where('user_id', $user->id)->where('event', AuthEventType::Signup)->exists())->toBeTrue();
});

it('registers a mobile client without opening a session', function () {
    // Given a mobile client (no browser session)
    Notification::fake();

    // When it registers
    $response = $this->postJson('/api/v1/auth/register', registrationPayload());

    // Then the account is created but nobody is signed in; the app will call /auth/token
    $response->assertCreated();
    $this->assertGuest();
});

it('rejects invalid registrations', function (array $overrides, string $field) {
    // Given an existing member
    User::factory()->create(['email' => 'taken@example.com', 'handle' => 'taken_handle']);

    // When a registration breaks a rule
    $response = $this->postJson('/api/v1/auth/register', registrationPayload($overrides));

    // Then it is refused with an error on that field
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'duplicate email, any case' => [['email' => 'TAKEN@example.com'], 'email'],
    'duplicate handle' => [['handle' => 'Taken_Handle'], 'handle'],
    'reserved handle' => [['handle' => 'admin'], 'handle'],
    'handle with a hyphen' => [['handle' => 'deleted-abc'], 'handle'],
    'handle too short' => [['handle' => 'ab'], 'handle'],
    'short password' => [['password' => 'elevenchars'], 'password'],
    'missing name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
]);

it('rejects a password found in a known breach', function () {
    // Given the breach check is switched on and the breach service says the password was leaked
    Password::defaults(fn () => Password::min(12)->uncompromised());
    $sha = strtoupper(sha1('correct-horse-battery'));
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(substr($sha, 5).':3861', 200)]);

    // When someone registers with it
    $response = $this->postJson('/api/v1/auth/register', registrationPayload());

    // Then it is refused
    $response->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('limits registrations to three per minute per IP', function () {
    // Given three successful registrations from one IP
    Notification::fake();
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/auth/register', registrationPayload([
            'email' => "user{$i}@example.com", 'handle' => "user_{$i}x",
        ]))->assertCreated();
    }

    // When a fourth arrives within the minute
    $response = $this->postJson('/api/v1/auth/register', registrationPayload([
        'email' => 'user4@example.com', 'handle' => 'user_4x',
    ]));

    // Then it is throttled
    $response->assertTooManyRequests();
});

it('answers 422, never 500, when a field is an array or object', function (string $field, mixed $value) {
    // Given a client that sends structured JSON where text is expected
    // When it registers
    $response = $this->postJson('/api/v1/auth/register', registrationPayload([$field => $value]));

    // Then it is a validation error
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(function () {
    foreach (['email', 'handle', 'name', 'password'] as $field) {
        yield "$field as list" => [$field, ['a', 'b']];
        yield "$field as object" => [$field, ['k' => 'v']];
        yield "$field as empty array" => [$field, []];
    }
});

it('ignores privileged fields in the request body', function () {
    // Given a malicious body with privileged fields
    Notification::fake();

    // When it registers
    $response = $this->postJson('/api/v1/auth/register', registrationPayload([
        'role' => 'admin',
        'email_verified_at' => now()->toIso8601String(),
        'is_admin' => true,
        'suspended_at' => now()->toIso8601String(),
        'risk_score' => 99,
    ]));

    // Then the member is plain, unverified and unflagged
    $response->assertCreated()->assertJsonPath('data.role', 'member');
    $user = User::query()->where('handle', 'amina_r')->firstOrFail();
    expect($user->role->value)->toBe('member')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->suspended_at)->toBeNull()
        ->and($user->risk_score)->toBe(0);
});

it('enforces length boundaries', function (array $overrides, bool $accepted, string $field = 'email') {
    // Given a registration at a size boundary
    Notification::fake();

    // When it is submitted
    $response = $this->postJson('/api/v1/auth/register', registrationPayload($overrides));

    // Then it is accepted or refused accordingly
    $accepted ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    '254-char email' => [['email' => emailOfLength(254)], true],
    // email:rfc,strict enforces the RFC 5321 limit of 254 characters
    '255-char email' => [['email' => emailOfLength(255)], false, 'email'],
    '256-char email' => [['email' => emailOfLength(256)], false, 'email'],
    '200-char password' => [['password' => str_repeat('p', 200)], true],
    '201-char password' => [['password' => str_repeat('p', 201)], false, 'password'],
]);

it('rejects whitespace-only names and look-alike handles', function (array $overrides, string $field) {
    // Given a registration with a sneaky value
    // When it is submitted
    $response = $this->postJson('/api/v1/auth/register', registrationPayload($overrides));

    // Then it is refused
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'whitespace-only name' => [['name' => "   \t "], 'name'],
    'fullwidth handle' => [['handle' => 'ＡＤＭＩＮ'], 'handle'],
    'non-ascii handle' => [['handle' => 'amína_r'], 'handle'],
]);

it('rejects a trailing newline in the Handle rule itself', function () {
    // Given the rule used without any trimming
    $rule = new Handle;
    $failures = [];

    // When it validates a value ending in a newline
    $rule->validate('handle', "amina_r\n", function (string $message) use (&$failures) {
        $failures[] = $message;
    });

    // Then it fails
    expect($failures)->toHaveCount(1);
});

it('turns a unique-constraint race into a validation error naming the real field', function (array $overrides, array $keys) {
    // Given an existing member and an action call that bypasses the form request
    Notification::fake();
    User::factory()->create(['email' => 'taken@example.com', 'handle' => 'taken_handle']);
    User::factory()->create(['email' => 'users_handle_unique@example.com', 'handle' => 'other_one']);

    // When the action hits the database constraint
    $call = fn () => app(RegisterUser::class)->handle([
        'name' => 'Race', 'email' => 'fresh@example.com', 'password' => 'correct-horse-battery', 'handle' => 'fresh_one',
        ...$overrides,
    ]);

    // Then a ValidationException names exactly the duplicated fields
    try {
        $call();
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe($keys);
    }

    // And nothing was half-created or sent
    expect(User::query()->where('handle', 'fresh_one')->doesntExist())->toBeTrue();
    Notification::assertNothingSent();
})->with([
    'email' => [['email' => 'taken@example.com'], ['email']],
    'handle' => [['handle' => 'taken_handle'], ['handle']],
    'both' => [['email' => 'taken@example.com', 'handle' => 'taken_handle'], ['email', 'handle']],
    'email that merely contains a constraint name' => [['email' => 'users_handle_unique@example.com'], ['email']],
]);

it('still registers when the auth log cannot be written, and reports the failure', function () {
    // Given hashing the request IP will throw, and exceptions are captured
    Notification::fake();
    Exceptions::fake();
    config(['launchpad.hmac_key' => 'short']);

    // When someone registers
    $response = $this->postJson('/api/v1/auth/register', registrationPayload());

    // Then the account exists, the response is 201 and the failure was reported
    $response->assertCreated();
    expect(User::query()->where('handle', 'amina_r')->exists())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'LAUNCHPAD_HMAC_KEY'));
});

it('survives a SQL error inside the auth-event listener during registration', function () {
    // Given the auth-event insert fails with a database error and exceptions are captured
    Notification::fake();
    Exceptions::fake();
    AuthEvent::creating(fn () => DB::statement('select 1/0'));

    // When someone registers
    $response = $this->postJson('/api/v1/auth/register', registrationPayload());

    // Then the surrounding transaction is intact: 201, the user exists, the failure is reported
    $response->assertCreated();
    expect(User::query()->where('handle', 'amina_r')->exists())->toBeTrue();
    Exceptions::assertReported(QueryException::class);
});

it('still stamps last_login_at when the login audit insert fails', function () {
    // Given a member and a failing audit insert
    Exceptions::fake();
    $user = User::factory()->create(['last_login_at' => null]);
    AuthEvent::creating(fn () => DB::statement('select 1/0'));

    // When the Login event is handled
    app(RecordAuthEvent::class)->handleLogin(new Login('web', $user, false));

    // Then the timestamp is set anyway and the failure was reported
    expect($user->refresh()->last_login_at)->not->toBeNull();
    Exceptions::assertReported(QueryException::class);
});

it('rejects quoted local parts and IP-literal domains', function (string $email) {
    // Given a visitor registering with an exotic but RFC-valid address
    Notification::fake();

    // When it is submitted
    $response = $this->postJson('/api/v1/auth/register', registrationPayload(['email' => $email]));

    // Then it is refused
    $response->assertUnprocessable()->assertJsonValidationErrors('email');
})->with(['"<script>"@example.com', 'a@[127.0.0.1]']);
