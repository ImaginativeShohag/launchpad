<?php

use App\Rules\Utf8;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    viewRedisReset();
    $this->travelTo(new DateTimeImmutable(VIEW_NOON.' UTC'));
});

it('answers 422 for a wrongly typed referrer and counts nothing', function (mixed $referrer) {
    // Given a product
    $product = viewVisibleProduct();

    // When the referrer is an array, an object, a number, a bool or nested
    $response = viewBeacon($product->id, ['referrer' => $referrer], viewDevice('device-a'));

    // Then it is the standard validation answer and nothing is counted
    $response->assertStatus(422)->assertJsonValidationErrors('referrer')->assertJsonStructure(['message', 'errors' => ['referrer']]);
    expect(viewPendingHash())->toBe([]);
})->with([
    'array' => [['https://t.co']], 'empty array' => [[]], 'object' => [['a' => 'https://t.co']], 'number' => [5], 'float' => [1.5],
    'true' => [true], 'false' => [false], 'nested' => [[['x']]],
]);

it('treats null, a missing key, an empty string and spaces as no referrer (direct)', function (array $payload) {
    // Given a product
    $product = viewVisibleProduct();

    // When the beacon carries no usable referrer
    $response = viewBeacon($product->id, $payload, viewDevice('device-a'));

    // Then it is 204 and counted as direct
    $response->assertNoContent();
    expect(viewCountedSources())->toBe(['direct']);
})->with([
    'missing' => [[]], 'null' => [['referrer' => null]], 'empty' => [['referrer' => '']], 'spaces' => [['referrer' => '   ']],
]);

it('accepts a 2048 character referrer and refuses 2049', function () {
    // Given a product
    $product = viewVisibleProduct();
    $url = fn (int $length) => 'https://blog.example/'.str_repeat('a', $length - strlen('https://blog.example/'));

    // When a 2048 and a 2049 character referrer are sent
    $ok = viewBeacon($product->id, ['referrer' => $url(2048)], viewDevice('device-a'));
    $tooLong = viewBeacon($product->id, ['referrer' => $url(2049)], viewDevice('device-b'));

    // Then 2048 is accepted (and reduced to its host) and 2049 is a 422 that counted nothing
    $ok->assertNoContent();
    $tooLong->assertStatus(422)->assertJsonValidationErrors('referrer');
    expect(viewCountedSources())->toBe(['blog.example']);
});

it('ignores a query string referrer', function () {
    // Given a product
    $product = viewVisibleProduct();

    // When the referrer is only in the query string
    viewRaw($product->id, '{}', query: '?referrer=https://t.co/x')->assertNoContent();

    // Then it is counted as direct
    expect(viewCountedSources())->toBe(['direct']);
});

it('ignores a form-encoded body and a text body', function (string $contentType, string $content) {
    // Given a product
    $product = viewVisibleProduct();

    // When the referrer arrives in a body that is not JSON
    viewRaw($product->id, $content, $contentType)->assertNoContent();

    // Then it is counted as direct
    expect(viewCountedSources())->toBe(['direct']);
})->with([
    'form' => ['application/x-www-form-urlencoded', 'referrer=https%3A%2F%2Ft.co%2Fx'],
    'text' => ['text/plain', '{"referrer":"https://t.co/x"}'],
    'form with array' => ['application/x-www-form-urlencoded', 'referrer[]=x'],
]);

it('ignores a JSON body that is not an object', function (string $body) {
    // Given a product
    $product = viewVisibleProduct();

    // When the whole body is a list, a string, a number, null, or broken JSON
    viewRaw($product->id, $body)->assertNoContent();

    // Then it is 204 and counted as direct
    expect(viewCountedSources())->toBe(['direct']);
})->with(['[]', '[1]', '["https://t.co/x"]', '"x"', '5', 'null', 'true', '', '{broken', "{\"referrer\":\"\xFF\"}"]);

it('ignores a stray extra field', function () {
    // Given a product
    $product = viewVisibleProduct();

    // When the body carries more than the referrer
    viewBeacon($product->id, ['referrer' => 'https://t.co/x', 'is_bot' => false, 'product_id' => 'other', 'user' => ['id' => 1]], viewDevice('device-a'))->assertNoContent();

    // Then only the referrer is used
    expect(viewCountedSources())->toBe(['x']);
});

it('validates the body before looking up the product', function () {
    // Given an unknown product id
    $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When the body is invalid, and when it is valid
    $invalid = viewBeacon($unknown, ['referrer' => ['x']], viewDevice('device-a'));
    $valid = viewBeacon($unknown, ['referrer' => 'https://t.co/x'], viewDevice('device-a'));

    // Then invalid is 422 and valid is the 404
    $invalid->assertStatus(422);
    $valid->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('refuses invalid UTF-8 text in the rule itself', function () {
    // Given the referrer rule
    // When a string with invalid UTF-8 is validated (a JSON body cannot carry one: it fails to decode and is ignored)
    $bad = Validator::make(['referrer' => "https://\xFF.example"], ['referrer' => [new Utf8]]);
    $good = Validator::make(['referrer' => 'https://bücher.example'], ['referrer' => [new Utf8]]);

    // Then the rule fails the first and passes the second
    expect($bad->fails())->toBeTrue()->and($good->fails())->toBeFalse();
});

it('answers 405 to GET on the beacon path', function () {
    // Given a product
    $product = viewVisibleProduct();

    // When the path is read with GET
    // Then it is 405 and counts nothing (a GET never writes)
    $this->getJson("/api/v1/products/{$product->id}/view", viewDevice('device-a'))->assertStatus(405);
    expect(viewPendingHash())->toBe([]);
});
