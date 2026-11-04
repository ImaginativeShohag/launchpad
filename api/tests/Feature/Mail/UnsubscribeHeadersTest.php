<?php

use App\Enums\EmailType;
use App\Mail\MailFor;
use App\Models\Product;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../../Support/MailFixtures.php';

it('puts the one-click headers and a footer link on every optional mail, and the URL verifies', function (EmailType $type) {
    // Given a member and an optional mail for them
    $user = User::factory()->create();
    $product = $type === EmailType::LaunchReminder
        ? Product::factory()->scheduled('2026-10-22')->create(['maker_id' => $user->id])
        : Product::factory()->live()->create();
    $payload = match ($type) {
        EmailType::ReviewOutcome => outboxReviewPayload($product->id),
        EmailType::LaunchReminder, EmailType::ProductLive => ['product_id' => $product->id, 'launch_date' => '2026-10-22'],
        EmailType::NewComment => ['product_id' => $product->id, 'kind' => 'comment', 'bucket' => '2026102110'],
        EmailType::DailyTop5 => ['date' => '2026-10-20', 'product_ids' => [$product->id]],
        EmailType::WeeklyBest => ['week_start' => '2026-10-12', 'product_ids' => [$product->id]],
        EmailType::ExportReady => ['export_id' => exportFor($product, 'ready', ['user_id' => $user->id])->id, 'product_id' => $product->id],
    };

    // When it is sent through the array mailer
    $email = outboxCapture(MailFor::make(outboxMessage($type, $payload, $user)));

    // Then both headers exist, the URL is absolute on app.url, and POSTing it unsubscribes exactly this type
    $post = $email->getHeaders()->get('List-Unsubscribe-Post');
    $unsubscribe = $email->getHeaders()->get('List-Unsubscribe');
    expect($post?->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
    expect($unsubscribe?->getBodyAsString())->toStartWith('<'.rtrim(config('app.url'), '/').'/api/v1/email/unsubscribe?')->toEndWith('>');
    $url = trim($unsubscribe->getBodyAsString(), '<>');
    $this->postJson((string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk()->assertJsonPath('type', $type->value);
    expect(DB::table('email_preferences')->where('user_id', $user->id)->where('type', $type->value)->value('enabled'))->toBeFalse();

    // And the footer links to the web page with the same signed query string
    expect((string) $email->getHtmlBody())->toContain('/unsubscribe?')->and((string) $email->getTextBody())->toContain('/unsubscribe?');
})->with(array_map(fn (EmailType $type) => [$type], EmailType::preferenceTypes()));

it('puts neither header on a Laravel auth notification mail', function () {
    // Given a member and the verify-email notification (which keeps its own queued path)
    $user = User::factory()->unverified()->create();

    // When the mail it builds is sent
    outboxForgetSent();
    $user->notify(new VerifyEmailNotification);

    // Then there is no unsubscribe header (the queue is sync in the suite)
    $email = outboxSent()->last();
    expect($email)->not->toBeNull();
    expect($email->getHeaders()->has('List-Unsubscribe'))->toBeFalse()->and($email->getHeaders()->has('List-Unsubscribe-Post'))->toBeFalse();
});

it('signs the link relative with no expiry', function () {
    // Given a digest mail
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();

    // When the header is read
    $email = outboxCapture(MailFor::make(outboxMessage(EmailType::DailyTop5, ['date' => '2026-10-20', 'product_ids' => [$product->id]], $user)));
    $url = trim($email->getHeaders()->get('List-Unsubscribe')->getBodyAsString(), '<>');

    // Then there is no expires parameter and the relative signature validates
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toHaveKeys(['user', 'type', 'signature'])->not->toHaveKey('expires');
    expect(URL::hasValidRelativeSignature(Request::create((string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY), 'POST')))->toBeTrue();
});
