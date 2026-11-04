<?php

use App\Actions\Email\SendEmailMessage;
use App\Actions\Email\SendResult;
use App\Enums\EmailType;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/MailFixtures.php';

function sendNow(): CarbonImmutable
{
    return CarbonImmutable::now('UTC');
}

/** Runs the sender for a claimed row. */
function sendClaimed(EmailMessage $row, int $attempts = 1): SendResult
{
    return app(SendEmailMessage::class)->handle($row->id, $attempts, sendNow());
}

beforeEach(function () {
    outboxTransport(fn () => null);
});

it('sends, records the result and sets the Message-ID to the row id', function () {
    // Given a claimed row for a verified member
    config(['mail.from.address' => 'hello@launchpad.test']);
    $row = outboxReadyClaimed();

    // When it is sent
    $result = sendClaimed($row);

    // Then the row is sent with its bookkeeping, and the one message carries the row id as Message-ID
    expect($result->outcome)->toBe('sent')->and($result->transientFailure)->toBeFalse();
    $stored = outboxRow($row->id);
    expect($stored['status'])->toBe('sent')->and($stored['sent_at'])->not->toBeNull()->and($stored['next_attempt_at'])->toBeNull()
        ->and($stored['last_error'])->toBeNull()->and($stored['provider_message_id'])->toBe($row->id.'@launchpad.test');
    expect(outboxAccepted())->toBe(1);
    $message = app('outbox.accepted')[0]->getOriginalMessage();
    expect($message->getHeaders()->get('Message-ID')->getBodyAsString())->toBe('<'.$row->id.'@launchpad.test>');
});

it('sends to the current address of the member and stores it', function () {
    // Given a row queued for the old address, and a member who has since changed it
    $user = User::factory()->create(['email' => 'old@example.com']);
    $row = outboxReady($user);
    DB::table('users')->where('id', $user->id)->update(['email' => 'new@example.com']);
    outboxClaimed($row);

    // When it is sent
    sendClaimed($row);

    // Then the message went to the new address and the row says so
    $to = app('outbox.accepted')[0]->getOriginalMessage()->getTo();
    expect($to[0]->getAddress())->toBe('new@example.com')->and(outboxRow($row->id)['to_email'])->toBe('new@example.com');
});

it('suppresses without a transport call for every send-time reason and gives the attempt back', function (string $reason, Closure $arrange) {
    // Given a claimed row whose member now fails the send-time rule
    $user = User::factory()->create();
    $row = outboxReady($user);
    outboxClaimed($row);
    $arrange($user);

    // When it is sent
    $result = sendClaimed($row);

    // Then it is suppressed with the reason, the attempt is not counted, and no message was handed to the transport
    expect($result->outcome)->toBe('suppressed')->and($result->transientFailure)->toBeFalse();
    $stored = outboxRow($row->id);
    expect($stored['status'])->toBe('suppressed')->and($stored['last_error'])->toBe($reason)->and((int) $stored['attempts'])->toBe(0)->and($stored['next_attempt_at'])->toBeNull();
    expect(outboxAccepted())->toBe(0);
})->with([
    'deleted' => ['user_deleted', fn (User $u) => DB::table('users')->where('id', $u->id)->update(['deleted_at' => now()])],
    'suspended' => ['user_suspended', fn (User $u) => DB::table('users')->where('id', $u->id)->update(['suspended_at' => now()])],
    'unverified' => ['email_unverified', fn (User $u) => DB::table('users')->where('id', $u->id)->update(['email_verified_at' => null])],
    'opted out' => ['opted_out', fn (User $u) => DB::table('email_preferences')->insert(['user_id' => $u->id, 'type' => 'product_live', 'enabled' => false, 'updated_at' => now()])],
]);

it('honours a preference switched off between enqueue and send, and a later switch-on does not resurrect the row', function () {
    // Given a queued row for a member who has not opted out yet
    $user = User::factory()->create();
    $row = outboxReady($user);
    outboxClaimed($row);

    // When the member switches the type off before the send, and the sender runs
    DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'product_live', 'enabled' => false, 'updated_at' => now()]);
    $result = sendClaimed($row);

    // Then the row is suppressed and nothing was sent
    expect($result->outcome)->toBe('suppressed')->and(outboxAccepted())->toBe(0);

    // And switching the type on again later does not bring it back
    DB::table('email_preferences')->where('user_id', $user->id)->update(['enabled' => true]);
    expect(sendClaimed($row)->outcome)->toBe('skipped');
    expect(outboxRow($row->id)['status'])->toBe('suppressed')->and(outboxAccepted())->toBe(0);
});

it('still sends a type that is not optional (the opt-out guard follows isOptional)', function () {
    // Given a claimed mandatory-type row for a member who has a (impossible for that type, so unrelated) preference row
    $user = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'product_live', 'enabled' => false, 'updated_at' => now()]);
    $row = outboxClaimed(outboxQueued(EmailType::VerifyEmail, ['x' => 'y'], $user));

    // When it is sent
    $result = sendClaimed($row);

    // Then it is not suppressed for opting out (it cannot be rendered by the outbox, so it fails as unrenderable instead)
    expect($result->outcome)->toBe('failed');
    expect(outboxRow($row->id)['last_error'])->toStartWith('App\Mail\UnrenderableMail');
});

it('suppresses a mail whose target is gone, as target_gone, without counting the attempt', function () {
    // Given a claimed product_live row whose product was taken down
    $product = Product::factory()->takenDown()->create();
    $row = outboxClaimed(outboxQueued(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22']));

    // When it is sent
    $result = sendClaimed($row);

    // Then it is suppressed as target_gone
    expect($result->outcome)->toBe('suppressed');
    $stored = outboxRow($row->id);
    expect($stored['status'])->toBe('suppressed')->and($stored['last_error'])->toBe('target_gone')->and((int) $stored['attempts'])->toBe(0);
    expect(outboxAccepted())->toBe(0);
});

it('fails an unrenderable row at once, with the class name and no retry', function () {
    // Given a claimed row with a payload the mailer cannot read
    $row = outboxClaimed(outboxQueued(EmailType::ProductLive, ['nope' => 1]));

    // When it is sent
    $result = sendClaimed($row);

    // Then it is failed for good, not a transient failure
    expect($result->outcome)->toBe('failed')->and($result->transientFailure)->toBeFalse();
    $stored = outboxRow($row->id);
    expect($stored['status'])->toBe('failed')->and($stored['next_attempt_at'])->toBeNull()->and($stored['last_error'])->toStartWith('App\Mail\UnrenderableMail');
});

it('skips a row that is not our claim: another attempt count, or no longer sending', function (Closure $arrange) {
    // Given a claimed row that changed under us
    $row = outboxReadyClaimed();
    $arrange($row);

    // When it is sent with the claim we hold
    $result = sendClaimed($row, 1);

    // Then nothing is sent or written
    expect($result->outcome)->toBe('skipped')->and(outboxAccepted())->toBe(0);
})->with([
    'attempts moved on (re-claimed)' => [fn (EmailMessage $r) => DB::table('email_messages')->where('id', $r->id)->update(['attempts' => 2])],
    'scrubbed to suppressed' => [fn (EmailMessage $r) => DB::table('email_messages')->where('id', $r->id)->update(['status' => 'suppressed', 'next_attempt_at' => null])],
    'row gone' => [fn (EmailMessage $r) => DB::table('email_messages')->where('id', $r->id)->delete()],
]);

it('does not record a result when the claim was lost during the send', function () {
    // Given a claimed row, and a transport during which another worker re-claims the row
    $row = outboxReadyClaimed();
    outboxTransportDo(fn () => DB::table('email_messages')->where('id', $row->id)->update(['attempts' => 2]));

    // When it is sent
    $result = sendClaimed($row, 1);

    // Then the result is not written over the new claim
    expect($result->outcome)->toBe('skipped');
    expect(outboxRow($row->id)['status'])->toBe('sending')->and((int) outboxRow($row->id)['attempts'])->toBe(2);
});

it('takes the row lock twice and never calls the transport inside a transaction it opened', function () {
    // Given a claimed row
    $row = outboxReadyClaimed();
    $level = DB::transactionLevel();
    $seen = [];
    outboxTransportDo(function () use (&$seen) {
        $seen[] = DB::transactionLevel();
    });
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When it is sent
    sendClaimed($row);

    // Then the transport ran at the transaction level of the caller (no transaction of the sender is open around the network call)
    expect($seen)->toBe([$level]);
    // And the email_messages row was locked FOR UPDATE exactly twice
    expect(collect($sql)->filter(fn (string $s) => str_contains($s, 'from "email_messages"') && str_contains($s, 'for update'))->count())->toBe(2);
});

it('refreshes nothing for a user it only reads: users are read with plain selects', function () {
    // Given a claimed row
    $row = outboxReadyClaimed();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When it is sent
    sendClaimed($row);

    // Then no statement on users locks a row
    expect(collect($sql)->filter(fn (string $s) => str_contains($s, 'from "users"') && (str_contains($s, 'for update') || str_contains($s, 'for share')))->count())->toBe(0);
});
