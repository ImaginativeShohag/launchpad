<?php

use App\Actions\Email\SendEmailMessage;
use App\Enums\EmailType;
use App\Mail\MailFor;
use App\Mail\UnrenderableMail;
use App\Models\Product;
use App\Models\User;
use App\Support\UnsubscribeLink;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../Support/MailFixtures.php';

it('refuses to build any mail link on a localhost or non-https base in production', function (string $key, string $url) {
    // Given production and a bad base URL
    $this->app['env'] = 'production';
    config([$key => $url]);
    $user = User::factory()->create();

    // When the web link, the API link, the footer link and a whole mail are built
    // Then each refuses
    if ($key === 'launchpad.web_url') {
        expect(fn () => MailFor::webUrl('/x'))->toThrow(UnrenderableMail::class);
        expect(fn () => UnsubscribeLink::web($user->id, EmailType::DailyTop5))->toThrow(UnrenderableMail::class);
        $product = Product::factory()->live()->create();
        expect(fn () => MailFor::make(outboxMessage(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'], $user)))->toThrow(UnrenderableMail::class);
    } else {
        expect(fn () => UnsubscribeLink::api($user->id, EmailType::DailyTop5))->toThrow(UnrenderableMail::class);
    }
})->with([
    'web localhost' => ['launchpad.web_url', 'https://localhost:5173'],
    'web 127.0.0.1' => ['launchpad.web_url', 'https://127.0.0.1'],
    'web 127.x' => ['launchpad.web_url', 'https://127.5.5.5'],
    'web http' => ['launchpad.web_url', 'http://app.example.com'],
    'web sub.localhost' => ['launchpad.web_url', 'https://app.localhost'],
    'web empty' => ['launchpad.web_url', ''],
    'api localhost' => ['app.url', 'https://localhost'],
    'api http' => ['app.url', 'http://api.example.com'],
]);

it('builds links on a public https base in production, and allows localhost outside production', function () {
    // Given a public https base in production
    $this->app['env'] = 'production';
    config(['launchpad.web_url' => 'https://app.example.com/', 'app.url' => 'https://api.example.com']);
    $user = User::factory()->create();

    // When the links are built
    // Then they work and carry no double slash
    expect(MailFor::webUrl('/x'))->toBe('https://app.example.com/x');
    expect(UnsubscribeLink::api($user->id, EmailType::DailyTop5))->toStartWith('https://api.example.com/api/v1/email/unsubscribe?');

    // And outside production a local http base is fine
    $this->app['env'] = 'local';
    config(['launchpad.web_url' => 'http://localhost:5173', 'app.url' => 'http://localhost']);
    expect(MailFor::webUrl('/x'))->toBe('http://localhost:5173/x')->and(UnsubscribeLink::api($user->id, EmailType::DailyTop5))->toStartWith('http://localhost/api/');
});

it('fails a mail with a bad production base as unrenderable (permanent, not retried)', function () {
    // Given production with a localhost web base and a claimed row
    $this->app['env'] = 'production';
    config(['launchpad.web_url' => 'http://localhost:5173']);
    outboxTransport(fn () => null);
    $row = outboxReadyClaimed();

    // When it is sent
    $result = app(SendEmailMessage::class)->handle($row->id, 1, CarbonImmutable::now('UTC'));

    // Then it failed for good and nothing was sent
    expect($result->outcome)->toBe('failed')->and(outboxRow($row->id)['last_error'])->toStartWith('App\Mail\UnrenderableMail')->and(outboxAccepted())->toBe(0);
});
