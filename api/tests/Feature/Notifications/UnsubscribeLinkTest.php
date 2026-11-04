<?php

use App\Enums\EmailType;
use App\Models\User;
use App\Support\UnsubscribeLink;
use Illuminate\Support\Facades\DB;

it('builds a relative link without an expiry', function () {
    // Given a member
    $user = User::factory()->create();

    // When the relative link is built
    $link = UnsubscribeLink::relative($user->id, EmailType::DailyTop5);

    // Then it has the three parameters, no expiry, and starts at the API path
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    expect($link)->toStartWith('/api/v1/email/unsubscribe?')->and($query)->toHaveKeys(['signature', 'type', 'user'])->not->toHaveKey('expires');
});

it('builds the API link on app.url and forwards the exact signed query to the web link', function () {
    // Given configured URLs and a member
    config(['app.url' => 'https://api.example.test/', 'launchpad.web_url' => 'https://app.example.test/']);
    $user = User::factory()->create();
    $relative = UnsubscribeLink::relative($user->id, EmailType::WeeklyBest);

    // When the API and web links are built
    // Then the API link is absolute and the web link carries the same query string, with parameters sorted as signed
    expect(UnsubscribeLink::api($user->id, EmailType::WeeklyBest))->toBe('https://api.example.test'.$relative);
    expect(UnsubscribeLink::web($user->id, EmailType::WeeklyBest))->toBe('https://app.example.test/unsubscribe?'.parse_url($relative, PHP_URL_QUERY));
    parse_str((string) parse_url($relative, PHP_URL_QUERY), $query);
    expect(array_keys($query))->toBe(['type', 'user', 'signature']);
});

it('verifies end to end when the web page forwards the query verbatim', function () {
    // Given the web link of a member
    $user = User::factory()->create();
    $web = UnsubscribeLink::web($user->id, EmailType::ProductLive);

    // When the page POSTs its query string to the API
    $this->postJson('/api/v1/email/unsubscribe?'.parse_url($web, PHP_URL_QUERY))->assertOk();

    // Then the type is off
    expect(DB::table('email_preferences')->where('user_id', $user->id)->where('type', 'product_live')->value('enabled'))->toBeFalse();
});
