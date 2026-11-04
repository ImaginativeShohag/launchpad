<?php

use App\Actions\Email\QueueDigest;
use App\Actions\Email\QueueLaunchReminders;
use App\Actions\Notification\NotificationDrafts;
use App\Enums\EmailType;
use App\Mail\MailFor;
use App\Mail\UnrenderableMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/MailFixtures.php';

/** Where each optional type is produced: [class, method] */
function emailTypeProducers(): array
{
    return [
        'review_outcome' => [NotificationDrafts::class, 'reviewOutcome'],
        'launch_reminder' => [QueueLaunchReminders::class, 'handle'],
        'product_live' => [NotificationDrafts::class, 'productLive'],
        'new_comment' => [NotificationDrafts::class, 'comment'],
        'daily_top5' => [QueueDigest::class, 'handle'],
        'weekly_best' => [QueueDigest::class, 'handle'],
        'export_ready' => [NotificationDrafts::class, 'exportReady'],
    ];
}

it('treats every email type as auth mail or an optional produced mail with a mailable and a preference slot', function (EmailType $type) {
    // Given a type and a member
    $auth = [EmailType::VerifyEmail, EmailType::ResetPassword, EmailType::EmailChange];
    $message = outboxMessage($type, []);

    // When the type is classified
    // Then auth mail is mandatory and never an outbox mail, and every other type (export_ready included) is optional with a
    // mailable, a producer and a preference slot
    if (in_array($type, $auth, true)) {
        expect($type->isOptional())->toBeFalse()->and(EmailType::preferenceTypes())->not->toContain($type);
        expect(fn () => MailFor::make($message))->toThrow(UnrenderableMail::class, 'is not an outbox mail');
    } else {
        expect($type->isOptional())->toBeTrue()->and(EmailType::preferenceTypes())->toContain($type);
        // A mailable exists: an empty payload is refused as a bad payload, never as "not an outbox mail"
        try {
            MailFor::make($message);
        } catch (UnrenderableMail $e) {
            expect($e->getMessage())->not->toContain('is not an outbox mail');
        }
        [$class, $method] = emailTypeProducers()[$type->value];
        expect(method_exists($class, $method))->toBeTrue();
    }
})->with(EmailType::cases());

it('lists the same optional types in the enum, the database CHECK and the preference API, in one order', function () {
    // Given the enum order, the CHECK of email_preferences and the API list for a member
    $enum = array_map(fn (EmailType $t) => $t->value, EmailType::preferenceTypes());
    $definition = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'email_preferences'::regclass AND conname = 'email_preferences_type_optional'")->def;
    preg_match_all("/'([a-z0-9_]+)'::character varying/", $definition, $matches);
    Sanctum::actingAs(User::factory()->create());
    $api = $this->getJson('/api/v1/me/email-preferences')->assertOk()->json('data.*.type');

    // When they are compared
    // Then the CHECK holds exactly the enum values and the API lists them in the enum's order
    expect($matches[1])->toEqualCanonicalizing($enum)->and($api)->toBe($enum)->and($enum)->toHaveCount(7);
    expect(array_keys(emailTypeProducers()))->toEqualCanonicalizing($enum);
});
