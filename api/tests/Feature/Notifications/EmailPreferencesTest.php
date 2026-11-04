<?php

use App\Actions\Email\UpdateEmailPreferences;
use App\Enums\EmailType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

const EMAIL_PREFS = '/api/v1/me/email-preferences';

/** The full default list: seven optional types, all on, in the documented order. */
function emailPrefsDefault(array $off = []): array
{
    return array_map(fn (EmailType $type) => ['type' => $type->value, 'enabled' => ! in_array($type->value, $off, true)], EmailType::preferenceTypes());
}

it('lists the seven optional types in the fixed order, all on by default', function () {
    // Given a member with no stored preference
    signedInMember();

    // When the preferences are read
    $response = $this->getJson(EMAIL_PREFS);

    // Then the seven types come in the documented order, all enabled, wrapped in data
    $response->assertOk()->assertExactJson(['data' => emailPrefsDefault()]);
    expect(array_column($response->json('data'), 'type'))->toBe(['review_outcome', 'launch_reminder', 'product_live', 'new_comment', 'daily_top5', 'weekly_best', 'export_ready']);
});

it('shows a stored off preference and leaves the others on', function () {
    // Given a member who switched weekly_best off
    $me = signedInMember();
    DB::table('email_preferences')->insert(['user_id' => $me->id, 'type' => 'weekly_best', 'enabled' => false, 'updated_at' => now()]);

    // When the preferences are read
    // Then only that type is off
    $this->getJson(EMAIL_PREFS)->assertOk()->assertExactJson(['data' => emailPrefsDefault(['weekly_best'])]);
});

it('lets an unverified member read and change preferences, and blocks a suspended one', function () {
    // Given an unverified member and a suspended member
    signedInMember(['email_verified_at' => null]);

    // When the unverified member reads and patches
    $read = $this->getJson(EMAIL_PREFS);
    $patch = $this->patchJson(EMAIL_PREFS, ['preferences' => ['daily_top5' => false]]);

    // Then both work
    $read->assertOk();
    $patch->assertOk();

    // And a suspended member gets the account guard's 403 on both
    signedInMember(['suspended_at' => now()]);
    $this->getJson(EMAIL_PREFS)->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    $this->patchJson(EMAIL_PREFS, ['preferences' => ['daily_top5' => false]])->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('answers a guest 401 and marks the responses no-store, private', function () {
    // Given no sign-in
    // When the routes are called
    // Then both are 401, and a signed-in answer is not cacheable
    $this->getJson(EMAIL_PREFS)->assertUnauthorized();
    $this->patchJson(EMAIL_PREFS, ['preferences' => ['daily_top5' => false]])->assertUnauthorized();
    signedInMember();
    expect($this->getJson(EMAIL_PREFS)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('turns one type off, stores a row, and returns the full list', function () {
    // Given a member
    $me = signedInMember();

    // When one type is switched off
    $response = $this->patchJson(EMAIL_PREFS, ['preferences' => ['daily_top5' => false]]);

    // Then the full list shows it off and one row is stored
    $response->assertOk()->assertExactJson(['data' => emailPrefsDefault(['daily_top5'])]);
    expect(DB::table('email_preferences')->where('user_id', $me->id)->get(['type', 'enabled'])->map(fn ($r) => [$r->type, $r->enabled])->all())->toBe([['daily_top5', false]]);
});

it('updates partially and leaves the other types as they were', function () {
    // Given a member who switched weekly_best off
    $me = signedInMember();
    $this->patchJson(EMAIL_PREFS, ['preferences' => ['weekly_best' => false]])->assertOk();

    // When another type is switched off
    $response = $this->patchJson(EMAIL_PREFS, ['preferences' => ['new_comment' => false]]);

    // Then both are off and the rest on
    $response->assertOk()->assertExactJson(['data' => emailPrefsDefault(['weekly_best', 'new_comment'])]);
});

it('turns a type back on by writing true, keeping the row and moving updated_at', function () {
    // Given a type switched off a while ago
    $me = signedInMember();
    DB::table('email_preferences')->insert(['user_id' => $me->id, 'type' => 'product_live', 'enabled' => false, 'updated_at' => '2026-01-01 10:00:00+00']);

    // When it is switched on again
    $this->patchJson(EMAIL_PREFS, ['preferences' => ['product_live' => true]])->assertOk();

    // Then the row still exists with enabled = true and a newer updated_at
    $row = DB::table('email_preferences')->where('user_id', $me->id)->where('type', 'product_live')->first();
    expect($row->enabled)->toBeTrue()->and(strtotime($row->updated_at))->toBeGreaterThan(strtotime('2026-01-02'));
});

it('does not touch updated_at when the value is unchanged', function () {
    // Given a stored off preference
    $me = signedInMember();
    DB::table('email_preferences')->insert(['user_id' => $me->id, 'type' => 'product_live', 'enabled' => false, 'updated_at' => '2026-01-01 10:00:00+00']);
    $before = DB::table('email_preferences')->where('user_id', $me->id)->value('updated_at');

    // When the same value is sent again
    $this->patchJson(EMAIL_PREFS, ['preferences' => ['product_live' => false]])->assertOk();

    // Then nothing was written
    expect(DB::table('email_preferences')->where('user_id', $me->id)->value('updated_at'))->toBe($before);
});

it('stores all seven at once', function () {
    // Given a member
    $me = signedInMember();
    $all = array_fill_keys(array_map(fn (EmailType $t) => $t->value, EmailType::preferenceTypes()), false);

    // When every type is switched off in one request
    $response = $this->patchJson(EMAIL_PREFS, ['preferences' => $all]);

    // Then seven rows exist and the list shows all off
    $response->assertOk();
    expect(array_unique(array_column($response->json('data'), 'enabled')))->toBe([false])
        ->and(DB::table('email_preferences')->where('user_id', $me->id)->count())->toBe(7);
});

it('refuses mandatory and unknown type keys', function (string $key) {
    // Given a member
    $me = signedInMember();

    // When the key is sent
    $response = $this->patchJson(EMAIL_PREFS, ['preferences' => [$key => false]]);

    // Then it is a 422 and nothing is stored
    $response->assertUnprocessable()->assertJsonValidationErrors('preferences');
    expect(DB::table('email_preferences')->count())->toBe(0);
})->with(['verify_email', 'reset_password', 'email_change', 'bogus', 'Daily_Top5', '0']);

it('rejects every odd payload with 422 and never 500', function (mixed $payload) {
    // Given a member
    signedInMember();

    // When the odd payload is patched
    $response = $this->patchJson(EMAIL_PREFS, $payload);

    // Then it is a 422 and nothing is stored
    $response->assertUnprocessable();
    expect(DB::table('email_preferences')->count())->toBe(0);
})->with([
    'empty object' => [[]],
    'preferences empty array' => [['preferences' => []]],
    'preferences a list of bool' => [['preferences' => [true]]],
    'preferences a list of names' => [['preferences' => ['daily_top5']]],
    'preferences a string' => [['preferences' => 'x']],
    'preferences null' => [['preferences' => null]],
    'preferences a number' => [['preferences' => 7]],
    'preferences a bool' => [['preferences' => true]],
    'value string false' => [['preferences' => ['daily_top5' => 'false']]],
    'value 1' => [['preferences' => ['daily_top5' => 1]]],
    'value 0' => [['preferences' => ['daily_top5' => 0]]],
    'value "1"' => [['preferences' => ['daily_top5' => '1']]],
    'value null' => [['preferences' => ['daily_top5' => null]]],
    'value empty array' => [['preferences' => ['daily_top5' => []]]],
    'value empty object' => [['preferences' => ['daily_top5' => (object) []]]],
    'value nested' => [['preferences' => ['daily_top5' => ['enabled' => true]]]],
    'one good, one bad value' => [['preferences' => ['daily_top5' => false, 'weekly_best' => 'no']]],
    'one good, one unknown key' => [['preferences' => ['daily_top5' => false, 'other' => true]]],
    'stray field only' => [['other' => true]],
]);

it('rejects a body that is not an object at all', function (string $raw) {
    // Given a member
    signedInMember();

    // When a raw JSON scalar or list is patched
    $response = $this->call('PATCH', EMAIL_PREFS, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $raw);

    // Then it is a 422, not a 500
    $response->assertUnprocessable();
})->with(['null' => 'null', 'a list' => '[1,2]', 'a string' => '"x"', 'a number' => '5', 'true' => 'true']);

it('authorises before it validates: an invalid body from a suspended member is a 403', function () {
    // Given a suspended member
    signedInMember(['suspended_at' => now()]);

    // When an invalid body is sent
    // Then the guard answers 403 before any validation
    $this->patchJson(EMAIL_PREFS, ['preferences' => 'x'])->assertForbidden();
});

it('throttles the 21st preference change of a minute', function () {
    // Given a member who changed their preferences 20 times
    signedInMember();
    foreach (range(1, 20) as $i) {
        $this->patchJson(EMAIL_PREFS, ['preferences' => ['daily_top5' => $i % 2 === 0]])->assertOk();
    }

    // When they change it once more
    $throttled = $this->patchJson(EMAIL_PREFS, ['preferences' => ['daily_top5' => false]]);

    // Then it is a 429 with Retry-After, and the read is not throttled by this limiter
    $throttled->assertStatus(429)->assertHeader('Retry-After');
    $this->getJson(EMAIL_PREFS)->assertOk();
});

it('refuses a member who was suspended or deleted after the request was authorised', function (array $state) {
    // Given a stale model of a member who is now suspended or deleted in the database
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update($state);

    // When the action runs with the stale model
    // Then it refuses and writes nothing
    expect(fn () => app(UpdateEmailPreferences::class)->handle($user, ['daily_top5' => false]))->toThrow(AuthorizationException::class);
    expect(DB::table('email_preferences')->count())->toBe(0);
})->with([
    'suspended' => [['suspended_at' => '2026-10-21 10:00:00+00']],
    'deleted' => [['deleted_at' => '2026-10-21 10:00:00+00']],
]);

it('takes the actor row FOR SHARE', function () {
    // Given a member
    $user = User::factory()->create();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When the action runs
    app(UpdateEmailPreferences::class)->handle($user, ['daily_top5' => false]);

    // Then the user row was read with a share lock
    expect(collect($sql)->contains(fn (string $s) => str_contains($s, 'from "users"') && str_contains($s, 'for share')))->toBeTrue();
});

it('rejects a non-boolean value and a mandatory type straight from the action', function () {
    // Given a member
    $user = User::factory()->create();

    // When the action gets a non-boolean value
    // Then it refuses (the request validates first; this is the programming-error guard)
    expect(fn () => app(UpdateEmailPreferences::class)->handle($user, ['daily_top5' => 1]))->toThrow(InvalidArgumentException::class);
});
