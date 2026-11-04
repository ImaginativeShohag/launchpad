<?php

use App\Enums\EmailType;
use App\Enums\ProductStatus;
use App\Mail\MailFor;
use App\Mail\MailTargetGone;
use App\Mail\UnrenderableMail;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use App\Support\Mail\DisplayName;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/MailFixtures.php';

/** The HTML part, the text part and the subject of a built mail. @return array{html: string, text: string, subject: string, email: Symfony\Component\Mime\Email} */
function mailRendered(Mailable $mailable): array
{
    $email = outboxCapture($mailable);

    return ['html' => (string) $email->getHtmlBody(), 'text' => (string) $email->getTextBody(), 'subject' => (string) $email->getSubject(), 'email' => $email];
}

it('renders each type with the right subject, a text part and a web_url link', function (EmailType $type, string $subject, string $path) {
    // Given a product and a payload for the type
    config(['launchpad.web_url' => 'https://app.example.test']);
    // (a reminder is only valid for the scheduled product of the very member it is sent to)
    $product = $type === EmailType::LaunchReminder
        ? Product::factory()->scheduled('2026-10-22')->create(['name' => 'Acme', 'slug' => 'acme'])
        : Product::factory()->live()->create(['name' => 'Acme', 'slug' => 'acme']);
    $payload = match ($type) {
        EmailType::ReviewOutcome => outboxReviewPayload($product->id),
        EmailType::LaunchReminder, EmailType::ProductLive => ['product_id' => $product->id, 'launch_date' => '2026-10-22'],
        EmailType::NewComment => ['product_id' => $product->id, 'kind' => 'comment', 'bucket' => '2026102110'],
        EmailType::DailyTop5 => ['date' => '2026-10-20', 'product_ids' => [$product->id]],
        EmailType::WeeklyBest => ['week_start' => '2026-10-12', 'product_ids' => [$product->id]],
    };
    $path = str_replace(['{id}', '{slug}'], [$product->id, 'acme'], $path);

    // When it is rendered
    $rendered = mailRendered(MailFor::make(outboxMessage($type, $payload, $type === EmailType::LaunchReminder ? $product->maker : null)));

    // Then subject, both parts and the link are right
    expect($rendered['subject'])->toBe($subject)
        ->and($rendered['html'])->toContain('href="https://app.example.test'.$path.'"')
        ->and($rendered['text'])->toContain('https://app.example.test'.$path)
        ->and($rendered['text'])->not->toContain('<');
})->with([
    'review outcome' => [EmailType::ReviewOutcome, 'Your product "Acme" was approved', '/me/products/{id}'],
    'launch reminder' => [EmailType::LaunchReminder, 'Acme launches tomorrow', '/me/products/{id}'],
    'product live' => [EmailType::ProductLive, 'Acme is live', '/products/{slug}'],
    'new comment' => [EmailType::NewComment, 'New comment on Acme', '/products/{slug}'],
    'daily top 5' => [EmailType::DailyTop5, "Yesterday's top 5", '/products/{slug}'],
    'weekly best' => [EmailType::WeeklyBest, "This week's best on Launchpad", '/products/{slug}'],
]);

it('words the three review decisions and the reply kind', function () {
    // Given a live product
    $product = Product::factory()->live()->create(['name' => 'Acme']);

    // When each decision and kind is rendered
    $changes = mailRendered(MailFor::make(outboxMessage(EmailType::ReviewOutcome, outboxReviewPayload($product->id, 'changes_requested'))));
    $rejected = mailRendered(MailFor::make(outboxMessage(EmailType::ReviewOutcome, outboxReviewPayload($product->id, 'rejected'))));
    $reply = mailRendered(MailFor::make(outboxMessage(EmailType::NewComment, ['product_id' => $product->id, 'kind' => 'reply', 'bucket' => '2026102110'])));

    // Then the subjects are the fixed templates
    expect($changes['subject'])->toBe('Your product "Acme" needs changes')
        ->and($rejected['subject'])->toBe('Your product "Acme" was not approved')
        ->and($reply['subject'])->toBe('New reply to your comment');
    // And the reply mail names the product only in its quoted context line, never in the subject or the lead
    expect($reply['subject'])->not->toContain('Acme')->and($reply['html'])->toContain('Product: &quot;Acme&quot;')->and($reply['text'])->toContain('Someone replied to your comment.')->toContain('Product: "Acme"');
});

it('escapes a hostile product name in the HTML and keeps it as plain text in the text part', function () {
    // Given a product whose name is a script tag
    $product = Product::factory()->live()->create(['name' => '<script>alert(1)</script>', 'slug' => 'hostile']);
    $soon = Product::factory()->scheduled('2026-10-22')->create(['name' => '<script>alert(1)</script>', 'slug' => 'hostile-soon']);

    // When each mail type is rendered
    foreach ([
        [EmailType::ReviewOutcome, outboxReviewPayload($product->id), null],
        [EmailType::LaunchReminder, ['product_id' => $soon->id, 'launch_date' => '2026-10-22'], $soon->maker],
        [EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'], null],
        [EmailType::NewComment, ['product_id' => $product->id, 'kind' => 'comment', 'bucket' => '2026102110'], null],
        [EmailType::DailyTop5, ['date' => '2026-10-20', 'product_ids' => [$product->id]], null],
        [EmailType::WeeklyBest, ['week_start' => '2026-10-12', 'product_ids' => [$product->id]], null],
    ] as [$type, $payload, $recipient]) {
        $rendered = mailRendered(MailFor::make(outboxMessage($type, $payload, $recipient)));

        // Then the HTML never holds the tag, only its escaped text (the new-comment mail does not print the name at all)
        expect($rendered['html'])->not->toContain('<script>')
            ->and($type === EmailType::NewComment || str_contains($rendered['html'], '&lt;script&gt;alert(1)&lt;/script&gt;'))->toBeTrue($type->value);
    }

    // And the plain part shows the name as text, not as an HTML entity
    $text = mailRendered(MailFor::make(outboxMessage(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'])))['text'];
    expect($text)->toContain('<script>alert(1)</script> is now live')->not->toContain('&lt;');
});

it('keeps an ampersand in a link intact in the text part', function () {
    // Given a product and a mail with a signed footer link (it has & between its parameters)
    $product = Product::factory()->live()->create();

    // When it is rendered
    $rendered = mailRendered(MailFor::make(outboxMessage(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'])));

    // Then the text link is usable (no &amp;) and the HTML one is entity-escaped
    expect($rendered['text'])->toContain('&signature=')->not->toContain('&amp;')
        ->and($rendered['html'])->toContain('&amp;signature=');
});

it('cannot split a header with a control character in the name, and cuts a long name', function () {
    // Given a product whose name carries CR, LF and BEL (stored raw)
    $product = Product::factory()->live()->create(['name' => "Evil\r\nBcc: x@y.z\x07Name"]);

    // When the mail is built and sent
    $rendered = mailRendered(MailFor::make(outboxMessage(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'])));

    // Then the subject has no control characters and no Bcc header exists
    expect($rendered['subject'])->toBe('EvilBcc: x@y.zName is live')->and($rendered['subject'])->not->toMatch('/[\x00-\x1f]/');
    expect($rendered['email']->getBcc())->toBe([]);

    // And the display name is cut at 120 characters
    expect(mb_strlen(DisplayName::of(str_repeat('é', 200))))->toBe(120)->and(DisplayName::of(123))->toBe('');
});

it('never prints a comment body, a review note, or another member address', function () {
    // Given a product with a comment full of a secret and a review whose note is secret, and another member
    $other = User::factory()->create(['email' => 'secret-other@example.com', 'name' => 'Other Person', 'handle' => 'othersecret']);
    $product = Product::factory()->live()->create(['name' => 'Acme', 'maker_id' => $other->id]);
    DB::table('product_reviews')->insert(['id' => strtolower((string) Str::ulid()), 'product_id' => $product->id, 'reviewer_id' => $other->id, 'decision' => 'rejected', 'note' => 'SECRET-NOTE', 'created_at' => now()]);
    Comment::factory()->create(['product_id' => $product->id, 'body' => 'SECRET-BODY']);

    // When every type is rendered
    foreach ([
        [EmailType::ReviewOutcome, outboxReviewPayload($product->id, 'rejected')],
        [EmailType::NewComment, ['product_id' => $product->id, 'kind' => 'comment', 'bucket' => '2026102110']],
        [EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22']],
        [EmailType::DailyTop5, ['date' => '2026-10-20', 'product_ids' => [$product->id]]],
    ] as [$type, $payload]) {
        $rendered = mailRendered(MailFor::make(outboxMessage($type, $payload)));
        $everything = $rendered['html']."\n".$rendered['text']."\n".$rendered['email']->getHeaders()->toString();

        // Then none of it appears anywhere, headers included
        foreach (['SECRET-BODY', 'SECRET-NOTE', 'secret-other@example.com', 'Other Person', 'othersecret'] as $secret) {
            expect($everything)->not->toContain($secret);
        }
    }
});

it('builds links from web_url and ids only, never from a stored URL', function () {
    // Given a product whose website_url is a stored link
    config(['launchpad.web_url' => 'https://app.example.test']);
    $product = Product::factory()->live()->create(['website_url' => 'https://evil.example.org/phish']);
    $soon = Product::factory()->scheduled('2026-10-22')->create(['website_url' => 'https://evil.example.org/phish']);

    // When the mails are rendered
    foreach ([[EmailType::ProductLive, $product, null], [EmailType::LaunchReminder, $soon, $soon->maker]] as [$type, $target, $recipient]) {
        $rendered = mailRendered(MailFor::make(outboxMessage($type, ['product_id' => $target->id, 'launch_date' => '2026-10-22'], $recipient)));

        // Then the stored URL never appears
        expect($rendered['html'].$rendered['text'])->not->toContain('evil.example.org');
    }
});

it('classifies an unusable row as UnrenderableMail', function (array $payload, EmailType $type) {
    // Given a row with a bad payload or a type outside the outbox set
    // When it is built
    // Then it is unrenderable
    expect(fn () => MailFor::make(outboxMessage($type, $payload)))->toThrow(UnrenderableMail::class);
})->with(function () {
    $id = strtolower((string) Str::ulid());

    return [
        'mandatory type' => [['product_id' => $id], EmailType::VerifyEmail],
        'export type with the wrong payload' => [['product_id' => $id], EmailType::ExportReady],
        'empty payload' => [[], EmailType::ProductLive],
        'missing key' => [['product_id' => $id], EmailType::ProductLive],
        'extra key' => [['product_id' => $id, 'launch_date' => '2026-10-22', 'x' => 1], EmailType::ProductLive],
        'id is an int' => [['product_id' => 5, 'launch_date' => '2026-10-22'], EmailType::ProductLive],
        'id is not a ulid' => [['product_id' => 'nope', 'launch_date' => '2026-10-22'], EmailType::ProductLive],
        'id is an array' => [['product_id' => [$id], 'launch_date' => '2026-10-22'], EmailType::ProductLive],
        'date not a date' => [['product_id' => $id, 'launch_date' => '2026-02-31'], EmailType::ProductLive],
        'date is null' => [['product_id' => $id, 'launch_date' => null], EmailType::LaunchReminder],
        'decision unknown' => [['review_id' => $id, 'product_id' => $id, 'decision' => 'maybe'], EmailType::ReviewOutcome],
        'kind unknown' => [['product_id' => $id, 'kind' => 'shout', 'bucket' => '2026102110'], EmailType::NewComment],
        'bucket malformed' => [['product_id' => $id, 'kind' => 'comment', 'bucket' => '20261021'], EmailType::NewComment],
        'digest ids not a list' => [['date' => '2026-10-20', 'product_ids' => ['a' => $id]], EmailType::DailyTop5],
        'digest ids empty' => [['date' => '2026-10-20', 'product_ids' => []], EmailType::DailyTop5],
        'digest six ids' => [['week_start' => '2026-10-12', 'product_ids' => array_fill(0, 6, $id)], EmailType::WeeklyBest],
        'digest id is bad' => [['date' => '2026-10-20', 'product_ids' => ['x']], EmailType::DailyTop5],
    ];
});

it('classifies a vanished target as MailTargetGone', function () {
    // Given products in the states that make a mail pointless
    $deleted = Product::factory()->live()->create();
    $deleted->delete();
    $takenDown = Product::factory()->takenDown()->create();
    $deletedMaker = Product::factory()->live()->create();
    $deletedMaker->maker->forceFill(['deleted_at' => now()])->save();
    $unknown = strtolower((string) Str::ulid());

    // When the mails are built
    // Then a soft-deleted product has no review mail, and a taken-down or unknown or deleted-maker one has no public mail
    expect(fn () => MailFor::make(outboxMessage(EmailType::ReviewOutcome, outboxReviewPayload($deleted->id))))->toThrow(MailTargetGone::class);
    expect(fn () => MailFor::make(outboxMessage(EmailType::LaunchReminder, ['product_id' => $deleted->id, 'launch_date' => '2026-10-22'])))->toThrow(MailTargetGone::class);
    expect(fn () => MailFor::make(outboxMessage(EmailType::ReviewOutcome, outboxReviewPayload($unknown))))->toThrow(MailTargetGone::class);
    foreach ([$takenDown, $deletedMaker] as $product) {
        expect(fn () => MailFor::make(outboxMessage(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'])))->toThrow(MailTargetGone::class);
        expect(fn () => MailFor::make(outboxMessage(EmailType::NewComment, ['product_id' => $product->id, 'kind' => 'comment', 'bucket' => '2026102110'])))->toThrow(MailTargetGone::class);
    }
});

it('renders a taken-down product review mail to its maker (the owner page still exists)', function () {
    // Given a taken-down product
    $product = Product::factory()->takenDown()->create(['name' => 'Acme']);

    // When its review mail is built
    $rendered = mailRendered(MailFor::make(outboxMessage(EmailType::ReviewOutcome, outboxReviewPayload($product->id, 'rejected'))));

    // Then it renders
    expect($rendered['subject'])->toBe('Your product "Acme" was not approved');
});

it('renders the visible digest products in payload order and drops the hidden ones', function () {
    // Given five products, two of them hidden
    $products = collect(range(1, 5))->map(fn (int $i) => Product::factory()->live()->create(['name' => "Product {$i}", 'slug' => "product-{$i}"]));
    $products[1]->delete();
    $products[3]->forceFill(['status' => ProductStatus::TakenDown])->save();
    $ids = [$products[4]->id, $products[3]->id, $products[0]->id, $products[1]->id, $products[2]->id];

    // When the digest is rendered
    $rendered = mailRendered(MailFor::make(outboxMessage(EmailType::DailyTop5, ['date' => '2026-10-20', 'product_ids' => $ids])));

    // Then three products show, in the payload's order
    expect($rendered['text'])->toContain('1. Product 5')->toContain('2. Product 1')->toContain('3. Product 3')->not->toContain('Product 2')->not->toContain('Product 4');
    expect(substr_count($rendered['html'], '<li>'))->toBe(3);
});

it('classifies a digest with every product hidden as MailTargetGone', function () {
    // Given a digest whose products are all gone
    $gone = Product::factory()->takenDown()->create();

    // When it is built
    // Then nothing is left to send
    expect(fn () => MailFor::make(outboxMessage(EmailType::DailyTop5, ['date' => '2026-10-20', 'product_ids' => [$gone->id, strtolower((string) Str::ulid())]])))->toThrow(MailTargetGone::class);
});

it('keeps the HTML views free of unescaped output', function () {
    // Given the mail views
    // When their sources are scanned
    $html = collect(glob(resource_path('views/mail/*.blade.php')));

    // Then none of the HTML views prints unescaped (only the text views do, because text/plain must not be entity-encoded)
    expect($html)->not->toBeEmpty();
    foreach ($html as $path) {
        expect(file_get_contents($path))->not->toContain('{!!');
    }
});

it('renders a launch reminder only while the product is still the recipient\'s, scheduled, and launching that day', function (string $change, bool $renders) {
    // Given a scheduled product of the recipient launching on 2026-10-22, and a reminder row for it
    $maker = User::factory()->create();
    $product = Product::factory()->scheduled('2026-10-22')->create(['maker_id' => $maker->id, 'name' => 'Acme']);
    $message = outboxMessage(EmailType::LaunchReminder, ['product_id' => $product->id, 'launch_date' => '2026-10-22'], $maker);

    // When the product changes after the reminder was queued
    match ($change) {
        'nothing' => null,
        'withdrawn' => DB::table('products')->where('id', $product->id)->update(['status' => 'draft', 'launch_date' => null]),
        'taken down' => DB::table('products')->where('id', $product->id)->update(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'x']),
        'went live' => DB::table('products')->where('id', $product->id)->update(['status' => 'live', 'launched_at' => now()]),
        'rescheduled' => DB::table('products')->where('id', $product->id)->update(['launch_date' => '2026-10-23']),
        'someone else\'s' => DB::table('products')->where('id', $product->id)->update(['maker_id' => User::factory()->create()->id]),
        'deleted' => DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]),
    };

    // Then it renders only when nothing changed; otherwise the row is a vanished target (suppressed at send time)
    if ($renders) {
        expect(mailRendered(MailFor::make($message))['subject'])->toBe('Acme launches tomorrow');
    } else {
        expect(fn () => MailFor::make($message))->toThrow(MailTargetGone::class);
    }
})->with([['nothing', true], ['withdrawn', false], ['taken down', false], ['went live', false], ['rescheduled', false], ['someone else\'s', false], ['deleted', false]]);
