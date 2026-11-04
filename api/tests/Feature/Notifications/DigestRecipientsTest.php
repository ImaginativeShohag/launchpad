<?php

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/DigestFixtures.php';

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-26 07:00:00 UTC'));
    // One launch on the 25th (daily) that is also in the week of the 19th (weekly); its maker is created here and removed
    // from the recipients table below by name, so each test states exactly who should get mail.
    $this->product = digestProduct('2026-10-25', ['votes_count' => 3]);
    digestFreeze('2026-10-25', [$this->product]);
    DB::table('users')->where('id', $this->product->maker_id)->update(['email_verified_at' => null]);
});

/** @return list<string> user ids that got a mail of $type */
function digestRecipients(string $type): array
{
    return DB::table('email_messages')->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
}

function digestRunNow(): array
{
    return digestAction()->handle(CarbonImmutable::parse('2026-10-26 07:00:00', 'UTC'));
}

it('queues the digest for verified, active, opted-in members of every role and nobody else', function () {
    // Given eligible members of each role and members who must be left out
    $member = User::factory()->create();
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $admin = User::factory()->role(Role::Admin)->create();
    $explicitOn = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $explicitOn->id, 'type' => 'daily_top5', 'enabled' => true, 'updated_at' => now()]);
    User::factory()->unverified()->create();
    User::factory()->suspended()->create();
    $deleted = User::factory()->create();
    DB::table('users')->where('id', $deleted->id)->update(['deleted_at' => now()]);
    $optedOut = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $optedOut->id, 'type' => 'daily_top5', 'enabled' => false, 'updated_at' => now()]);

    // When the digests are queued
    digestRunNow();

    // Then exactly the eligible members got the daily digest, each once, with no in-site notification
    expect(digestRecipients('daily_top5'))->toEqualCanonicalizing([$member->id, $reviewer->id, $admin->id, $explicitOn->id]);
    expect(DB::table('notifications')->count())->toBe(0);
});

it('applies each type\'s own preference: opting out of the weekly best keeps the daily top five', function () {
    // Given a member opted out of the weekly best only, and one opted out of the daily top five only
    $noWeekly = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $noWeekly->id, 'type' => 'weekly_best', 'enabled' => false, 'updated_at' => now()]);
    $noDaily = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $noDaily->id, 'type' => 'daily_top5', 'enabled' => false, 'updated_at' => now()]);
    // And an opt-out of an unrelated type, which must not matter
    $noComments = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $noComments->id, 'type' => 'new_comment', 'enabled' => false, 'updated_at' => now()]);

    // When both digests are queued (Monday)
    digestRunNow();

    // Then each digest honours only its own preference
    expect(digestRecipients('daily_top5'))->toEqualCanonicalizing([$noWeekly->id, $noComments->id]);
    expect(digestRecipients('weekly_best'))->toEqualCanonicalizing([$noDaily->id, $noComments->id]);
});

it('stores the member\'s address, ids only in the payload, and a key per period and member', function () {
    // Given one member with a known address
    $member = User::factory()->create(['email' => 'ada@example.com']);

    // When the daily digest is queued
    digestRunNow();

    // Then the row has the address, the key and a payload without personal data
    $row = DB::table('email_messages')->where('type', 'daily_top5')->where('user_id', $member->id)->first();
    expect($row->to_email)->toBe('ada@example.com')->and($row->idempotency_key)->toBe('daily_top5:2026-10-25:'.$member->id)->and($row->status)->toBe('queued');
    expect(json_decode($row->payload, true))->toBe(['date' => '2026-10-25', 'product_ids' => [$this->product->id]]);
    expect($row->payload)->not->toContain('ada@example.com')->not->toContain('Ada');
});

it('skips a member who is deleted between the cursor and the lock: the locked read decides', function () {
    // Given three eligible members
    [$a, $b, $c] = collect(range(1, 3))->map(fn () => User::factory()->create())->sortBy('id')->values()->all();

    // And the second one is deleted after the first chunk was committed (chunk of one)
    $action = digestAction(1, function () use ($b) {
        DB::table('users')->where('id', $b->id)->update(['deleted_at' => now()]);
    });

    // When the digest is queued
    $action->handle(CarbonImmutable::parse('2026-10-26 07:00:00', 'UTC'));

    // Then the deleted member got nothing and the others did
    expect(digestRecipients('daily_top5'))->toEqualCanonicalizing([$a->id, $c->id]);
});
