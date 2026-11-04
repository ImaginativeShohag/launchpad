<?php

use App\Actions\Auth\DeleteAccount;
use App\Actions\Auth\LoginWithGoogle;
use App\Enums\Role;
use App\Exceptions\GoogleIdentityMismatch;
use App\Exceptions\GoogleUnavailable;
use App\Models\AuthEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Google\GoogleIdentity;
use App\Support\Google\GoogleIdentityProvider;
use App\Support\Google\SocialiteGoogleIdentityProvider;
use App\Support\HandleGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

const WEB = 'http://localhost:5173';

/** @return list<string> */
function authEventsOf(User $user): array
{
    return AuthEvent::query()->where('user_id', $user->id)->pluck('event')->map->value->all();
}

it('opens a fresh session and sets the device cookie on a successful callback', function () {
    // Given a browser that already holds some session id, and a member who can sign in with Google
    User::factory()->create(['email' => 'amina@example.com']);
    fakeGoogleUser(sub: 'g-1', email: 'amina@example.com');
    $oldSessionId = Str::random(40);

    // When Google calls back
    $response = $this->withCredentials()->withUnencryptedCookie(config('session.cookie'), $oldSessionId)
        ->get('/api/v1/auth/google/callback');

    // Then the session id was regenerated and the plain HttpOnly device cookie is set
    $response->assertRedirect(WEB.'/');
    expect(session()->getId())->not->toBe($oldSessionId);
    $response->assertCookie('lp_did', null, false);
    expect($response->getCookie('lp_did', false)->isHttpOnly())->toBeTrue();
});

it('sends the browser to Google', function () {
    // Given the fake Google integration
    fakeGoogleUser();

    // When the web app starts the flow
    $response = $this->get('/api/v1/auth/google/redirect');

    // Then the browser is redirected to Google
    $response->assertRedirect('https://accounts.google.test/auth');
});

it('signs in a member whose Google identity is already linked, whatever email Google now reports', function () {
    // Given a member with a linked Google identity
    $user = User::factory()->create(['email' => 'amina@example.com']);
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'g-1']);
    fakeGoogleUser(sub: 'g-1', email: 'new-address@gmail.com');

    // When Google calls back
    $response = $this->get('/api/v1/auth/google/callback');

    // Then they are signed in and no second account is created
    $response->assertRedirect(WEB.'/');
    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
    expect(authEventsOf($user))->toContain('login_succeeded')->not->toContain('google_linked');
});

it('links Google to an existing account with the same email when Google says the email is verified', function () {
    // Given an unverified email/password member
    $user = User::factory()->unverified()->create(['email' => 'amina@example.com']);
    fakeGoogleUser(sub: 'g-1', email: 'Amina@Example.com', verified: true);

    // When they sign in with Google
    $this->get('/api/v1/auth/google/callback')->assertRedirect(WEB.'/');

    // Then Google is linked to the same account, the email counts as confirmed, and both are recorded
    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
    expect($user->socialAccounts()->pluck('provider_user_id')->all())->toBe(['g-1']);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect(authEventsOf($user))->toContain('google_linked')->toContain('email_verified')->toContain('login_succeeded');
});

it('never links an unverified Google email to an existing account', function () {
    // Given a member and a Google identity with the same, unverified email
    $user = User::factory()->create(['email' => 'amina@example.com']);
    fakeGoogleUser(sub: 'g-1', email: 'amina@example.com', verified: false);

    // When the Google callback is called
    $response = $this->get('/api/v1/auth/google/callback');

    // Then the browser is sent back with an error and nothing is linked
    $response->assertRedirect(WEB.'/signin?error=google_email_unverified');
    $this->assertGuest();
    expect($user->socialAccounts()->count())->toBe(0);
});

it('creates a verified, password-less member for a new Google email', function () {
    // Given nobody has this email yet
    Notification::fake();
    fakeGoogleUser(sub: 'g-9', email: 'New.Person@Gmail.com', name: 'New Person');

    // When they sign in with Google
    $this->get('/api/v1/auth/google/callback')->assertRedirect(WEB.'/');

    // Then a confirmed account exists with no password, a handle from the name, and no email is sent
    $user = User::query()->where('email', 'new.person@gmail.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect($user->hasPassword())->toBeFalse();
    expect($user->handle)->toBe('new_person');
    expect($user->role)->toBe(Role::Member);
    expect($user->socialAccounts()->count())->toBe(1);
    expect(authEventsOf($user))->toContain('signup')->toContain('google_linked')->toContain('login_succeeded');
    Notification::assertNothingSent();
});

it('does not create an account from an unverified Google email', function () {
    // Given a Google identity with an unverified email nobody has registered
    fakeGoogleUser(sub: 'g-9', email: 'new@gmail.com', verified: false);

    // When they sign in
    $response = $this->get('/api/v1/auth/google/callback');

    // Then they are refused and no account exists
    $response->assertRedirect(WEB.'/signin?error=google_email_unverified');
    expect(User::query()->count())->toBe(0);
});

it('keeps suspended members out', function () {
    // Given a suspended member with a linked Google identity
    $user = User::factory()->suspended()->create();
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'g-1']);
    fakeGoogleUser(sub: 'g-1');

    // When they sign in with Google
    $response = $this->get('/api/v1/auth/google/callback');

    // Then they are redirected with an error and not signed in
    $response->assertRedirect(WEB.'/signin?error=account_unavailable');
    $this->assertGuest();
});

it('keeps deleted members out, even through a matching email', function () {
    // Given a deleted member with the same email as the Google identity
    User::factory()->create(['email' => 'amina@example.com', 'deleted_at' => now()]);
    fakeGoogleUser(email: 'amina@example.com');

    // When they sign in with Google
    $response = $this->get('/api/v1/auth/google/callback');

    // Then the account is unavailable and nothing is linked
    $response->assertRedirect(WEB.'/signin?error=account_unavailable');
    $this->assertGuest();
    expect(SocialAccount::query()->count())->toBe(0);
});

it('lets a deleted member sign up again with the same real email, as a brand-new account', function () {
    // Given a member who deleted their account. The real DeleteAccount must keep deleting
    // socialAccounts() for this to hold.
    $old = User::factory()->create(['email' => 'amina@example.com']);
    SocialAccount::factory()->for($old)->create(['provider_user_id' => 'g-1']);
    app(DeleteAccount::class)->handle($old);
    fakeGoogleUser(sub: 'g-1', email: 'amina@example.com');

    // When they sign in with Google again
    $response = $this->get('/api/v1/auth/google/callback');

    // Then a new account is created and they are signed in
    $response->assertRedirect(WEB.'/');
    $new = User::query()->where('email', 'amina@example.com')->firstOrFail();
    expect($new->id)->not->toBe($old->id);
    $this->assertAuthenticatedAs($new);
    expect(User::query()->count())->toBe(2);
});

it('sends the browser back with a generic error when Google fails', function () {
    // Given Google rejects the callback
    fakeGoogleFailure();

    // When the callback is called
    $response = $this->get('/api/v1/auth/google/callback');

    // Then the browser is redirected with a generic error and nothing else in the URL
    $response->assertRedirect(WEB.'/signin?error=google_failed');
    $this->assertGuest();
});

it('issues a device token for a valid Google ID token (mobile)', function () {
    // Given a Google identity for a brand-new member
    fakeGoogleUser(sub: 'g-5', email: 'phone@gmail.com', name: 'Phone User');

    // When the phone app posts the ID token
    $response = $this->postJson('/api/v1/auth/google/token', ['id_token' => 'good-token', 'device_name' => 'Pixel']);

    // Then it receives a token and the user, and no browser session is opened
    $response->assertCreated()->assertJsonPath('data.user.email', 'phone@gmail.com');
    expect($response->json('data.token'))->toMatch('/^\d+\|lp_/');
    expect(DB::table('personal_access_tokens')->count())->toBe(1);
    $this->assertGuest();
    $user = User::query()->where('email', 'phone@gmail.com')->firstOrFail();
    expect(authEventsOf($user))->toContain('signup')->toContain('google_linked')->toContain('login_succeeded')->toContain('token_created');
});

it('rejects invalid, unverified and suspended cases on the mobile endpoint', function () {
    // Given a bad token
    fakeGoogleUser();
    // When it is posted
    // Then it is a validation error
    $this->postJson('/api/v1/auth/google/token', ['id_token' => 'bad-token', 'device_name' => 'Pixel'])
        ->assertUnprocessable()->assertJsonValidationErrors('id_token');

    // Given an unverified Google email that matches an existing member
    User::factory()->create(['email' => 'amina@example.com']);
    fakeGoogleUser(email: 'amina@example.com', verified: false);
    // Then it is a 409 with a machine-readable code
    $this->postJson('/api/v1/auth/google/token', ['id_token' => 'ok', 'device_name' => 'Pixel'])
        ->assertStatus(409)->assertJsonPath('code', 'google_email_unverified');

    // Given a suspended member
    $suspended = User::factory()->suspended()->create();
    SocialAccount::factory()->for($suspended)->create(['provider_user_id' => 'g-77']);
    fakeGoogleUser(sub: 'g-77');
    // Then it is a 403
    $this->postJson('/api/v1/auth/google/token', ['id_token' => 'ok', 'device_name' => 'Pixel'])
        ->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('rejects array, object, null and empty mobile fields with 422, never 500', function (string $field, mixed $value) {
    // Given a working Google identity and a payload where one field has the wrong shape
    fakeGoogleUser();
    $payload = array_merge(['id_token' => 'good-token', 'device_name' => 'Pixel'], [$field => $value]);

    // When it is posted
    $response = $this->postJson('/api/v1/auth/google/token', $payload);

    // Then validation rejects it and nothing was created
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect(User::query()->count())->toBe(0);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->with(function () {
    foreach (['id_token', 'device_name'] as $field) {
        foreach ([[], ['a'], ['k' => 'v'], null, '', 123, true] as $i => $value) {
            yield "$field #$i" => [$field, $value];
        }
    }
    yield 'device_name whitespace only' => ['device_name', " \t\n  "];
    yield 'device_name control chars only' => ['device_name', "\x00\x07\x1b"];
    yield 'device_name invisible only' => ['device_name', "\u{00A0}\u{200B}"];
    yield 'device_name too long' => ['device_name', str_repeat('a', 101)];
    yield 'id_token too long' => ['id_token', str_repeat('a', 4097)];
});

it('cleans the device name like the password token endpoint does', function () {
    // Given a working Google identity
    fakeGoogleUser();

    // When the device name has control characters and padding
    $this->postJson('/api/v1/auth/google/token', ['id_token' => 'ok', 'device_name' => "  Pi\x00xel\n "])->assertCreated();

    // Then the stored name is cleaned
    expect(DB::table('personal_access_tokens')->value('name'))->toBe('Pixel');
});

it('throttles the Google endpoints per IP without a global bucket shared by everyone', function () {
    // Given a working Google identity
    fakeGoogleUser();

    // When one client posts twenty tokens within a minute and then a 21st
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/v1/auth/google/token', ['id_token' => 'ok', 'device_name' => 'p'])->assertCreated();
    }

    // Then the 21st is limited
    $this->postJson('/api/v1/auth/google/token', ['id_token' => 'ok', 'device_name' => 'p'])->assertTooManyRequests();
});

it('strips a pre-registered password and sessions when Google proves ownership of an unverified email', function () {
    // Given an attacker registered the victim's email with their own password and is signed in everywhere
    $attacker = User::factory()->unverified()->create([
        'email' => 'victim@example.com',
        'password' => 'attacker-secret-pass',
        'pending_email' => 'attacker@example.com',
        'pending_email_expires_at' => now()->addHour(),
        'remember_token' => 'attacker-remember-token',
    ]);
    DB::table('password_reset_tokens')->insert(['email' => 'victim@example.com', 'token' => 'x', 'created_at' => now()]);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $attacker->id, 'payload' => 'x', 'last_activity' => time()]);
    $attacker->createToken('attacker-phone');
    fakeGoogleUser(sub: 'g-victim', email: 'victim@example.com', verified: true);

    // When the real owner signs in with Google
    $this->get('/api/v1/auth/google/callback')->assertRedirect(WEB.'/');

    // Then the account is Google-only: no password, no other sessions or tokens, no pending email
    $fresh = $attacker->fresh();
    expect($fresh->hasPassword())->toBeFalse();
    expect($fresh->hasVerifiedEmail())->toBeTrue();
    expect($fresh->pending_email)->toBeNull();
    expect($fresh->pending_email_expires_at)->toBeNull();
    expect($fresh->remember_token)->not->toBe('attacker-remember-token')->not->toBeEmpty();
    expect(DB::table('password_reset_tokens')->where('email', 'victim@example.com')->exists())->toBeFalse();
    expect(DB::table('sessions')->where('id', 's1')->exists())->toBeFalse();
    expect($fresh->tokens()->count())->toBe(0);
    $this->assertAuthenticatedAs($fresh);

    // And the attacker can no longer log in with the old password
    auth()->guard('web')->logout();
    spa()->postJson('/api/v1/auth/login', ['email' => 'victim@example.com', 'password' => 'attacker-secret-pass'])
        ->assertUnprocessable();
    $this->postJson('/api/v1/auth/token', ['email' => 'victim@example.com', 'password' => 'attacker-secret-pass', 'device_name' => 'x'])
        ->assertUnprocessable();
});

it('keeps the password and sessions of an account whose email was already verified', function () {
    // Given a verified email/password member with a session and a token
    $user = User::factory()->create(['email' => 'amina@example.com', 'password' => 'correct-horse-battery']);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    $user->createToken('phone');
    fakeGoogleUser(sub: 'g-1', email: 'amina@example.com', verified: true);

    // When they link Google by signing in
    $this->get('/api/v1/auth/google/callback')->assertRedirect(WEB.'/');

    // Then nothing was taken away from them
    $fresh = $user->fresh();
    expect($fresh->hasPassword())->toBeTrue();
    expect(Hash::check('correct-horse-battery', (string) $fresh->password))->toBeTrue();
    expect(DB::table('sessions')->where('id', 's1')->exists())->toBeTrue();
    expect($fresh->tokens()->count())->toBe(1);
    expect($fresh->socialAccounts()->count())->toBe(1);
});

it('returns the same account when the same identity signs in twice', function () {
    // Given a brand-new Google identity
    $identity = new GoogleIdentity('g-3', 'twice@example.com', true, 'Twice Over');

    // When the action runs twice
    $first = app(LoginWithGoogle::class)->handle($identity);
    $second = app(LoginWithGoogle::class)->handle($identity);

    // Then both calls resolve to one account with one social row
    expect($second->is($first))->toBeTrue();
    expect(User::query()->count())->toBe(1);
    expect(SocialAccount::query()->count())->toBe(1);
});

it('resolves a lost first-sign-in race to the winner instead of failing', function (bool $sameSub) {
    // Given a competing request on another connection that commits the same account while ours is mid-flight
    $racer = DB::connectUsing('racer', config('database.connections.'.config('database.default')));
    $winnerId = '01JAAAAAAAAAAAAAAAAAAAAAAA';
    $handles = new class($racer, $winnerId, $sameSub) extends HandleGenerator
    {
        private bool $raced = false;

        public function __construct(private ConnectionInterface $racer, private string $winnerId, private bool $sameSub) {}

        public function for(string $name, string $email): string
        {
            if (! $this->raced) {
                $this->raced = true;
                $this->racer->table('users')->insert([
                    'id' => $this->winnerId, 'handle' => 'race_winner', 'name' => 'Winner', 'email' => 'race@example.com',
                    'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->racer->table('social_accounts')->insert([
                    'id' => '01JBBBBBBBBBBBBBBBBBBBBBBB', 'user_id' => $this->winnerId, 'provider' => 'google',
                    'provider_user_id' => $this->sameSub ? 'g-race' : 'g-other', 'provider_email' => 'race@example.com',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return parent::for($name, $email);
        }
    };

    try {
        // When our request, which saw nothing yet, tries to create the same member
        $attempt = fn () => (new LoginWithGoogle($handles))->handle(new GoogleIdentity('g-race', 'race@example.com', true, 'Racer'));

        // Then the same sub resolves to the winner's account, and another sub is refused cleanly
        if ($sameSub) {
            $user = $attempt();
            expect($user->getKey())->toBe($winnerId);
            expect($user->hasVerifiedEmail())->toBeTrue();
        } else {
            expect($attempt)->toThrow(GoogleIdentityMismatch::class);
        }
        expect(User::query()->where('email', 'race@example.com')->count())->toBe(1);
        expect(SocialAccount::query()->where('user_id', $winnerId)->count())->toBe(1);
    } finally {
        // The test transaction holds row locks on the winner (foreign keys); release them before cleaning up.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $racer->table('social_accounts')->where('user_id', $winnerId)->delete();
        $racer->table('users')->where('id', $winnerId)->delete();
        DB::purge('racer');
    }
})->with([
    'winner used the same sub' => [true],
    'winner used another sub, same email (refused)' => [false],
]);

it('refuses a second Google sub on an account that already has a Google link', function () {
    // Given a member linked to Google sub A, and a different Google account (sub B) with the same verified email
    $user = User::factory()->create(['email' => 'amina@example.com', 'password' => 'correct-horse-battery']);
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'sub-A']);
    fakeGoogleUser(sub: 'sub-B', email: 'amina@example.com', verified: true);

    // When sub B signs in on the web and on mobile
    $web = $this->get('/api/v1/auth/google/callback');
    $mobile = $this->postJson('/api/v1/auth/google/token', ['id_token' => 'ok', 'device_name' => 'Pixel']);

    // Then both are refused with a machine-readable code and nothing changes
    $web->assertRedirect(WEB.'/signin?error=google_identity_mismatch');
    $this->assertGuest();
    $mobile->assertStatus(409)->assertJsonPath('code', 'google_identity_mismatch')->assertJsonStructure(['message', 'code']);
    expect(SocialAccount::query()->where('user_id', $user->id)->pluck('provider_user_id')->all())->toBe(['sub-A']);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    expect($user->fresh()->hasPassword())->toBeTrue();
});

it('still signs in the original Google sub, and links a case-variant email', function () {
    // Given a member linked to sub A
    $user = User::factory()->create(['email' => 'amina@example.com']);
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'sub-A']);
    fakeGoogleUser(sub: 'sub-A', email: 'amina@example.com');

    // When sub A signs in
    $this->get('/api/v1/auth/google/callback')->assertRedirect(WEB.'/');

    // Then it works
    $this->assertAuthenticatedAs($user);

    // And a case-variant email links an unlinked member
    auth()->guard('web')->logout();
    $other = User::factory()->create(['email' => 'bola@example.com']);
    fakeGoogleUser(sub: 'sub-C', email: 'Bola@Example.COM');
    $this->get('/api/v1/auth/google/callback')->assertRedirect(WEB.'/');
    expect($other->socialAccounts()->pluck('provider_user_id')->all())->toBe(['sub-C']);
});

it('has a database backstop: one Google link per account', function () {
    // Given a member with a Google link
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'sub-A']);

    // When a second Google row is inserted for the same member
    // Then the database refuses it
    expect(fn () => DB::transaction(fn () => SocialAccount::factory()->for($user)->create(['provider_user_id' => 'sub-B'])))
        ->toThrow(QueryException::class, 'social_accounts_user_id_provider_unique');
});

it('removes any other social rows of an account whose email was never verified', function () {
    // Given an unverified account that somehow holds a social row for another provider
    $user = User::factory()->unverified()->create(['email' => 'victim@example.com']);
    SocialAccount::factory()->for($user)->create(['provider' => 'github', 'provider_user_id' => 'gh-1']);
    fakeGoogleUser(sub: 'g-v', email: 'victim@example.com');

    // When Google proves ownership
    $this->get('/api/v1/auth/google/callback')->assertRedirect(WEB.'/');

    // Then only the Google link remains
    expect($user->socialAccounts()->pluck('provider')->all())->toBe(['google']);
});

it('answers 503 with a code when Google cannot be reached on the mobile endpoint', function () {
    // Given Google's certificate fetch fails
    app()->instance(GoogleIdentityProvider::class, new class implements GoogleIdentityProvider
    {
        public function redirect(): RedirectResponse
        {
            throw new RuntimeException;
        }

        public function fromCallback(): GoogleIdentity
        {
            throw new RuntimeException;
        }

        public function fromIdToken(string $idToken): ?GoogleIdentity
        {
            throw new GoogleUnavailable;
        }
    });

    // When the phone posts a token
    $response = $this->postJson('/api/v1/auth/google/token', ['id_token' => 'x', 'device_name' => 'p']);

    // Then it is a retryable 503, not a 422
    $response->assertStatus(503)->assertJsonPath('code', 'google_unavailable')->assertJsonStructure(['message', 'code']);
});

it('rejects a callback with a bad state using the real Socialite provider, without any network call', function () {
    // Given the real provider and no stored OAuth state
    app()->bind(GoogleIdentityProvider::class, SocialiteGoogleIdentityProvider::class);
    config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'secret']);

    // When Google "calls back" with a state that was never issued
    $response = $this->get('/api/v1/auth/google/callback?state=bad&code=x');

    // Then the browser is sent back with a generic error and nothing was created
    $response->assertRedirect(WEB.'/signin?error=google_failed');
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});
