<?php

use App\Actions\Email\UnsubscribeFromEmail;
use App\Enums\EmailType;
use App\Models\User;
use App\Support\UnsubscribeLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/** The stored preference of $type for $userId: null (no row), true or false. */
function unsubStored(string $userId, string $type): ?bool
{
    $value = DB::table('email_preferences')->where('user_id', $userId)->where('type', $type)->value('enabled');

    return $value === null ? null : (bool) $value;
}

it('switches the type off for a valid signed link and answers the exact body', function () {
    // Given a member and a signed link for daily_top5
    $user = User::factory()->create();
    $link = UnsubscribeLink::relative($user->id, EmailType::DailyTop5);

    // When the link is POSTed with no sign-in at all
    $response = $this->postJson($link);

    // Then the type is off and only that type changed
    $response->assertOk()->assertExactJson(['type' => 'daily_top5', 'enabled' => false]);
    expect(unsubStored($user->id, 'daily_top5'))->toBeFalse();
    expect(DB::table('email_preferences')->where('user_id', $user->id)->count())->toBe(1);
});

it('is idempotent: a repeat answers 200 and leaves updated_at alone', function () {
    // Given a link used once
    $user = User::factory()->create();
    $link = UnsubscribeLink::relative($user->id, EmailType::WeeklyBest);
    $this->postJson($link)->assertOk();
    $first = DB::table('email_preferences')->where('user_id', $user->id)->value('updated_at');

    // When it is used again
    $second = $this->postJson($link);

    // Then the answer is the same and nothing was written
    $second->assertOk()->assertExactJson(['type' => 'weekly_best', 'enabled' => false]);
    expect(DB::table('email_preferences')->where('user_id', $user->id)->value('updated_at'))->toBe($first);
});

it('refuses a tampered, removed or foreign signature with the framework 403 and writes nothing', function (Closure $tamper) {
    // Given a member, another member and a valid link
    $user = User::factory()->create();
    $other = User::factory()->create();
    $link = UnsubscribeLink::relative($user->id, EmailType::DailyTop5);

    // When the link is changed
    $response = $this->postJson($tamper($link, $user, $other));

    // Then it is the framework's 403 and no row exists
    $response->assertForbidden()->assertJsonPath('message', 'Invalid signature.');
    expect(DB::table('email_preferences')->count())->toBe(0);
})->with([
    'tampered signature' => [fn (string $link) => preg_replace('/signature=(.)/', 'signature=x', $link)],
    'tampered type' => [fn (string $link) => str_replace('type=daily_top5', 'type=weekly_best', $link)],
    'tampered user' => [fn (string $link, User $user, User $other) => str_replace($user->id, $other->id, $link)],
    'removed signature' => [fn (string $link) => (string) preg_replace('/&signature=.*$/', '', $link)],
    'signature of another user' => [fn (string $link, User $user, User $other) => (string) preg_replace('/signature=.*$/', 'signature='.preg_replace('/^.*signature=/', '', UnsubscribeLink::relative($other->id, EmailType::DailyTop5)), $link)],
    'extra parameter' => [fn (string $link) => $link.'&extra=1'],
    'array type appended' => [fn (string $link) => $link.'&type[]=x'],
    'array type only' => [fn (string $link) => '/api/v1/email/unsubscribe?type[]=daily_top5&user[]=x&signature=abc'],
]);

it('answers 422 for a validly signed link of a mandatory type', function (string $type) {
    // Given a link signed by hand for a type that can never be switched off
    $user = User::factory()->create();
    $link = URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'type' => $type], absolute: false);

    // When it is POSTed
    $response = $this->postJson($link);

    // Then it is a 422 and nothing is stored
    $response->assertUnprocessable()->assertJsonValidationErrors('type');
    expect(DB::table('email_preferences')->count())->toBe(0);
})->with(['verify_email', 'reset_password', 'email_change', 'bogus']);

it('answers 422 for a validly signed link with a malformed user id', function () {
    // Given a link signed by hand for a user that is not a ULID
    $link = URL::signedRoute('email.unsubscribe', ['user' => 'not-a-ulid', 'type' => 'daily_top5'], absolute: false);

    // When it is POSTed
    // Then it is a 422
    $this->postJson($link)->assertUnprocessable()->assertJsonValidationErrors('user');
});

it('answers 422 for a validly signed link with an array type or a missing parameter', function (array $params) {
    // Given a hand-signed link with odd parameters
    $link = URL::signedRoute('email.unsubscribe', $params, absolute: false);

    // When it is POSTed
    $response = $this->postJson($link);

    // Then it is a 422, never a 500
    $response->assertUnprocessable();
})->with([
    'array type' => [['user' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'type' => ['daily_top5']]],
    'array user' => [['user' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'], 'type' => 'daily_top5']],
    'no type' => [['user' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']],
    'no user' => [['type' => 'daily_top5']],
]);

it('answers the same 200 for an unknown member and a deleted member, and writes no row', function () {
    // Given a signed link for an id that exists nowhere, and one for a deleted member
    $deleted = User::factory()->create(['deleted_at' => now()]);
    $known = User::factory()->create();

    // When each is POSTed
    $unknown = $this->postJson(UnsubscribeLink::relative('01ARZ3NDEKTSV4RRFFQ69G5FAV', EmailType::DailyTop5));
    $gone = $this->postJson(UnsubscribeLink::relative($deleted->id, EmailType::DailyTop5));
    $live = $this->postJson(UnsubscribeLink::relative($known->id, EmailType::DailyTop5));

    // Then the three answers are byte-identical and only the live member has a row
    expect($unknown->getContent())->toBe($gone->getContent())->and($gone->getContent())->toBe($live->getContent());
    $unknown->assertOk();
    expect(DB::table('email_preferences')->count())->toBe(1)->and(unsubStored($known->id, 'daily_top5'))->toBeFalse();
});

it('works for a suspended member', function () {
    // Given a suspended member
    $user = User::factory()->create(['suspended_at' => now()]);

    // When they follow the link
    $this->postJson(UnsubscribeLink::relative($user->id, EmailType::ProductLive))->assertOk();

    // Then the type is off (a valid signature is enough)
    expect(unsubStored($user->id, 'product_live'))->toBeFalse();
});

it('never expires', function () {
    // Given a link built today
    $user = User::factory()->create();
    $link = UnsubscribeLink::relative($user->id, EmailType::NewComment);

    // When it is used two years later
    $this->travel(2)->years();
    $response = $this->postJson($link);

    // Then it still works
    $response->assertOk();
    expect(unsubStored($user->id, 'new_comment'))->toBeFalse();
});

it('works without an Origin, from the SPA origin and with the one-click body', function () {
    // Given three members with a link each
    $a = User::factory()->create();
    $b = User::factory()->create();
    $c = User::factory()->create();

    // When the links are POSTed bare, from the SPA's stateful origin, and with a mail client's one-click body
    $bare = $this->postJson(UnsubscribeLink::relative($a->id, EmailType::DailyTop5));
    $stateful = spa()->postJson(UnsubscribeLink::relative($b->id, EmailType::DailyTop5));
    $oneClick = $this->call('POST', UnsubscribeLink::relative($c->id, EmailType::DailyTop5), ['List-Unsubscribe' => 'One-Click']);

    // Then all answer 200 (never a 419)
    $bare->assertOk();
    $stateful->assertOk();
    $oneClick->assertOk();
    foreach ([$a, $b, $c] as $user) {
        expect(unsubStored($user->id, 'daily_top5'))->toBeFalse();
    }
});

it('throttles the 21st request of a minute from one IP, forged links included', function () {
    // Given 20 forged links from one address
    foreach (range(1, 20) as $_) {
        $this->postJson('/api/v1/email/unsubscribe?user=x&type=daily_top5&signature=bad')->assertForbidden();
    }

    // When a 21st arrives, even a valid one
    $user = User::factory()->create();
    $throttled = $this->postJson(UnsubscribeLink::relative($user->id, EmailType::DailyTop5));

    // Then it is a 429 with Retry-After, and the forged ones were counted
    $throttled->assertStatus(429)->assertHeader('Retry-After');
});

it('is not cacheable', function () {
    // Given a valid link and a forged one
    $user = User::factory()->create();

    // When each is POSTed
    $ok = $this->postJson(UnsubscribeLink::relative($user->id, EmailType::DailyTop5));
    $forged = $this->postJson('/api/v1/email/unsubscribe?user=x&type=daily_top5&signature=bad');

    // Then both responses are no-store, private
    foreach ([$ok, $forged] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('only answers POST', function () {
    // Given a valid link
    $user = User::factory()->create();
    $link = UnsubscribeLink::relative($user->id, EmailType::DailyTop5);

    // When it is requested with GET
    // Then it is not a route (405) and nothing changed
    $this->getJson($link)->assertStatus(405);
    expect(DB::table('email_preferences')->count())->toBe(0);
});

it('takes the member row FOR SHARE before it writes', function () {
    // Given a member
    $user = User::factory()->create();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When the action runs
    app(UnsubscribeFromEmail::class)->handle($user->id, EmailType::DailyTop5);

    // Then the user row was read with a share lock
    expect(collect($sql)->contains(fn (string $s) => str_contains($s, 'from "users"') && str_contains($s, 'for share')))->toBeTrue();
});

it('switches export_ready off through a signed link', function () {
    // Given a member and a signed export_ready link
    $user = User::factory()->create();
    $link = UnsubscribeLink::relative($user->id, EmailType::ExportReady);

    // When it is POSTed
    $this->postJson($link)->assertSuccessful();

    // Then the preference is off
    expect(DB::table('email_preferences')->where('user_id', $user->id)->where('type', 'export_ready')->value('enabled'))->toBeFalse();
});
