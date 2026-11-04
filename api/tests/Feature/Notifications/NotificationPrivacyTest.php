<?php

use App\Enums\EmailType;
use App\Mail\MailFor;
use App\Models\Comment;
use App\Models\EmailMessage;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/MailFixtures.php';
require_once __DIR__.'/../../Support/NotificationFixtures.php';

/** Everything a rendered mail exposes: HTML, text and all headers. */
function privacyEverything(EmailMessage $message): string
{
    $email = outboxCapture(MailFor::make($message));

    return (string) $email->getHtmlBody()."\n".(string) $email->getTextBody()."\n".$email->getHeaders()->toString();
}

it('shows a member only their own bell rows, counts and mark-read effects', function () {
    // Given two members with notifications that carry distinct product names
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    foreach ([[$ada, 'ADA-PRODUCT'], [$bob, 'BOB-PRODUCT']] as [$user, $name]) {
        Notification::factory()->forUser($user)->create(['data' => ['message_key' => 'notifications.review_approved', 'product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'review_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'params' => ['product_name' => $name], 'href' => '/me/products/1']]);
    }
    $bobRow = DB::table('notifications')->where('user_id', $bob->id)->value('id');

    // When Ada reads her list and count, and tries to mark Bob's row and everything
    Sanctum::actingAs($ada);
    $list = $this->getJson('/api/v1/me/notifications')->assertOk();
    $count = $this->getJson('/api/v1/me/notifications/unread-count')->assertOk()->json('unread_count');
    $this->postJson('/api/v1/me/notifications/read', ['ids' => [$bobRow]])->assertOk()->assertJsonPath('marked', 0);
    $this->postJson('/api/v1/me/notifications/read', ['all' => true])->assertOk()->assertJsonPath('marked', 1);

    // Then she sees only her own row, never Bob's name or id, and Bob's row stayed unread
    expect($list->getContent())->toContain('ADA-PRODUCT')->not->toContain('BOB-PRODUCT')->not->toContain($bobRow)->not->toContain($bob->id);
    expect($count)->toBe(1)->and(DB::table('notifications')->where('id', $bobRow)->value('read_at'))->toBeNull();
});

it('returns only the documented keys: no user id, dedupe key, address or timestamps of internals', function () {
    // Given a member with a bell row and a preference
    $user = User::factory()->create(['email' => 'private-ada@example.com']);
    Notification::factory()->forUser($user)->create();
    Sanctum::actingAs($user);

    // When the list and the preferences are read
    $row = $this->getJson('/api/v1/me/notifications')->assertOk()->json('data.0');
    $prefs = $this->getJson('/api/v1/me/email-preferences')->assertOk();
    $count = $this->getJson('/api/v1/me/notifications/unread-count')->assertOk()->json();
    $marked = $this->postJson('/api/v1/me/notifications/read', ['all' => true])->assertOk()->json();

    // Then each response holds exactly its documented keys and never the address
    expect(array_keys($row))->toBe(['id', 'type', 'data', 'read_at', 'created_at']);
    expect(array_keys($count))->toBe(['unread_count', 'capped'])->and(array_keys($marked))->toBe(['marked', 'unread_count']);
    foreach ($prefs->json('data') as $item) {
        expect(array_keys($item))->toBe(['type', 'enabled']);
    }
    expect($prefs->getContent())->not->toContain($user->id)->not->toContain('private-ada@example.com');
});

it('keeps every mailable free of other members\' data, comment bodies and review notes', function (EmailType $type) {
    // Given a product of another member, a comment and a review note that are secret, and a second product for the digests
    $maker = User::factory()->create(['email' => 'secret-maker@example.com', 'name' => 'Secret Maker', 'handle' => 'secretmaker']);
    $commenter = User::factory()->create(['email' => 'secret-commenter@example.com', 'name' => 'Secret Commenter', 'handle' => 'secretcommenter']);
    $product = Product::factory()->live()->create(['name' => 'Acme', 'maker_id' => $maker->id]);
    DB::table('product_reviews')->insert(['id' => strtolower((string) Str::ulid()), 'product_id' => $product->id, 'reviewer_id' => $commenter->id, 'decision' => 'rejected', 'note' => 'SECRET-NOTE', 'created_at' => now()]);
    Comment::factory()->create(['product_id' => $product->id, 'user_id' => $commenter->id, 'body' => 'SECRET-BODY']);
    $recipient = User::factory()->create();
    $scheduled = Product::factory()->scheduled()->create(['name' => 'Soon', 'maker_id' => $recipient->id]);
    $payload = match ($type) {
        EmailType::ReviewOutcome => outboxReviewPayload($product->id, 'rejected'),
        EmailType::LaunchReminder => ['product_id' => $scheduled->id, 'launch_date' => $scheduled->launch_date->toDateString()],
        EmailType::ProductLive => ['product_id' => $product->id, 'launch_date' => '2026-10-22'],
        EmailType::NewComment => ['product_id' => $product->id, 'kind' => 'reply', 'bucket' => '2026102110'],
        EmailType::DailyTop5 => ['date' => '2026-10-20', 'product_ids' => [$product->id]],
        EmailType::WeeklyBest => ['week_start' => '2026-10-19', 'product_ids' => [$product->id]],
        EmailType::ExportReady => ['export_id' => exportFor($product, 'ready', ['user_id' => $recipient->id])->id, 'product_id' => $product->id],
    };

    // When the mail is rendered for the recipient
    $everything = privacyEverything(outboxMessage($type, $payload, $recipient));

    // Then none of the other members' data or the private text appears anywhere, headers included
    foreach (['SECRET-BODY', 'SECRET-NOTE', 'secret-maker@example.com', 'secret-commenter@example.com', 'Secret Maker', 'Secret Commenter', 'secretmaker', 'secretcommenter', $maker->id, $commenter->id] as $secret) {
        expect($everything)->not->toContain($secret);
    }
    // And the only member id in the unsubscribe link is the recipient's own
    expect($everything)->toContain($recipient->id);
})->with(EmailType::preferenceTypes());

it('never reads a comment body or a review note into a notification draft, a payload or a bell row', function () {
    // Given the producer sources
    // When they are scanned
    // Then neither the comment body nor the reviewer note column is read by the producers or the mailables
    $files = [app_path('Actions/Notification/NotificationDrafts.php'), app_path('Actions/Notification/RecordNotification.php'), ...glob(app_path('Mail/*.php')), ...glob(app_path('Listeners/Notify*.php'))];
    foreach ($files as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toMatch('/->body\b|\[\'body\'\]|->note\b|\[\'note\'\]|"body"|"note"/', $file);
    }
});
