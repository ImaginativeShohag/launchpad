<?php

use App\Rules\SafeProfileUrl;

/** Runs the rule directly, so the framework's own `url:https` rule cannot mask it. */
function urlPasses(mixed $url): bool
{
    $failed = false;
    (new SafeProfileUrl)->validate('url', $url, function () use (&$failed) {
        $failed = true;
    });

    return ! $failed;
}

it('accepts public https URLs', function (string $url) {
    // Given a public https URL
    // When the rule checks it
    // Then it passes
    expect(urlPasses($url))->toBeTrue();
})->with([
    'plain' => ['https://amina.dev'],
    'path query fragment' => ['https://github.com/amina/repo?tab=readme#top'],
    'punycode host' => ['https://xn--mnchen-3ya.de/'],
    'punycode tld' => ['https://example.xn--p1ai/'],
    'subdomains' => ['https://blog.amina.example.co.uk/a'],
    'explicit 443' => ['https://amina.dev:443/'],
    'uppercase scheme and host (normalised upstream)' => ['HTTPS://Example.COM/x'],
    // The "@" is in the query, not the authority, so this is a normal URL.
    'at sign in query' => ['https://good.com?e=a@b.c'],
    'at sign in path' => ['https://good.com/@amina'],
    'longest allowed label' => ['https://'.str_repeat('a', 63).'.com/'],
]);

it('rejects unsafe URLs', function (mixed $url) {
    // Given an unsafe or malformed URL
    // When the rule checks it
    // Then it fails
    expect(urlPasses($url))->toBeFalse();
})->with([
    'http' => ['http://amina.dev'],
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,x'],
    'empty' => [''],
    'array' => [['https://example.com']],
    'null' => [null],
    'integer' => [5],
    'credentials' => ['https://user:pass@example.com'],
    'username only' => ['https://user@example.com'],
    'credentials trick' => ['https://example.com@evil.com/'],
    'backslash' => ['https://example.com\\@evil.com'],
    'no host' => ['https:///path'],
    'ipv4' => ['https://127.0.0.1/'],
    'ipv4 private' => ['https://192.168.1.1/'],
    'ipv4 short' => ['https://127.1/'],
    'ipv4 hex dotted' => ['https://0x7f.0x0.0x0.0x1/'],
    'ipv4 hex short' => ['https://0x7f.0x1/'],
    'ipv4 hex two' => ['https://0x7f.1/'],
    'ipv4 octal integer' => ['https://017700000001/'],
    'ipv4 decimal integer' => ['https://2130706433/'],
    'ipv4 octal dotted' => ['https://0177.0.0.1/'],
    'ipv6' => ['https://[::1]/'],
    'ipv6 mapped' => ['https://[::ffff:127.0.0.1]/'],
    'localhost' => ['https://localhost/'],
    'localhost subdomain' => ['https://app.localhost/'],
    'single label' => ['https://intranet/'],
    'one letter tld' => ['https://a.b/'],
    'numeric tld' => ['https://example.123/'],
    'unicode host' => ['https://münchen.de/'],
    'unicode path' => ['https://example.com/ü'],
    'space' => ['https://exa mple.com/'],
    'newline' => ["https://example.com/\nx"],
    'trailing dot' => ['https://example.com./'],
    'empty label' => ['https://a..com/'],
    'odd port' => ['https://example.com:8443/'],
    'label too long' => ['https://'.str_repeat('a', 64).'.com/'],
    'host too long' => ['https://'.implode('.', array_fill(0, 200, 'a')).'.com/'],
    'underscore' => ['https://exa_mple.com/'],
    'leading hyphen' => ['https://-example.com/'],
    ...array_combine(
        array_map(fn (string $s) => 'blocked suffix .'.$s, ['localhost', 'local', 'internal', 'lan', 'home', 'corp', 'intranet', 'test', 'example', 'invalid', 'onion', 'arpa', 'localdomain', 'svc']),
        array_map(fn (string $s) => ['https://service.'.$s.'/'], ['localhost', 'local', 'internal', 'lan', 'home', 'corp', 'intranet', 'test', 'example', 'invalid', 'onion', 'arpa', 'localdomain', 'svc']),
    ),
]);

// DNS names that resolve to private addresses (127.0.0.1.nip.io) cannot be blocked by looking at the text.
// This rule is for links shown to people. Any future server-side fetch of a member URL must resolve the
// name itself and check the resulting IP.
it('documents that DNS names resolving to private IPs pass the syntax check', function () {
    // Given a hostname that resolves to loopback via wildcard DNS
    // When the rule checks it
    // Then it passes, which is why server-side fetches must resolve and check the IP
    expect(urlPasses('https://127.0.0.1.nip.io/'))->toBeTrue();
});
