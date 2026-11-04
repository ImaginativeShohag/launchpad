<?php

use App\Actions\Views\ReferrerSource;

it('maps allowlisted hosts to their keys', function (string $url, string $expected) {
    // Given a referrer URL from the allowlist
    // When it is reduced
    // Then the configured key comes back
    expect(ReferrerSource::from($url))->toBe($expected);
})->with([
    ['https://www.google.com/search?q=x', 'google'],
    ['https://t.co/abc', 'x'],
    ['https://x.com/y', 'x'],
    ['https://twitter.com/z', 'x'],
    ['https://news.ycombinator.com/item?id=1', 'hacker_news'],
    ['https://l.facebook.com/l.php?u=', 'facebook'],
    ['https://m.youtube.com/watch', 'youtube'],
    ['HTTPS://WWW.GOOGLE.COM./x', 'google'],
    ['https://www.google.co.uk/', 'google'],
    ['https://search.yahoo.com/search', 'yahoo'],
    ['https://duckduckgo.com/?q=a', 'duckduckgo'],
    ['https://github.com/a/b', 'github'],
]);

it('does not match an allowlist suffix across a label boundary', function (string $url, string $expected) {
    // Given look-alike hosts
    // When they are reduced
    // Then they stay their own host
    expect(ReferrerSource::from($url))->toBe($expected);
})->with([
    ['https://evilgoogle.com/', 'evilgoogle.com'],
    ['https://google.com.evil.example/', 'google.com.evil.example'],
    ['https://notx.com/', 'notx.com'],
    ['https://nott.co/', 'nott.co'],
]);

it('maps the own web hosts to launchpad', function () {
    // Given a configured web origin and stateful domain
    config(['launchpad.web_url' => 'https://launchpad.example', 'sanctum.stateful' => ['launchpad.example:443']]);

    // When referrers from them are reduced
    // Then they are launchpad, and a look-alike is not
    expect(ReferrerSource::from('https://launchpad.example/feed'))->toBe('launchpad')
        ->and(ReferrerSource::from('https://www.launchpad.example/'))->toBe('launchpad')
        ->and(ReferrerSource::from('https://launchpad.example.evil.test/'))->toBe('launchpad.example.evil.test');
});

it('treats localhost as launchpad under the test config', function () {
    // Given the test environment's web origin
    // When the dev server is the referrer
    // Then it is launchpad
    expect(ReferrerSource::from('http://localhost:5173/today'))->toBe('launchpad');
});

it('answers direct for anything that is not an http(s) URL with a host', function (mixed $input) {
    // Given an unusable referrer
    // When it is reduced
    // Then it is direct
    expect(ReferrerSource::from($input))->toBe('direct');
})->with([
    [''], [null], [[]], [123], [true], ['   '], ['javascript:alert(1)'], ['ftp://files.example/x'], ['data:text/html,<b>'],
    ['//example.com/x'], ['example.com/x'], ['not a url'], ["https://example.com/\x00"], ['https://exa mple.com'],
    ['https://example.com/'.str_repeat('a', 2029)], [str_repeat('x', 5000)], ["https://\xFF\xFE.example"],
    [new stdClass], [1.5], ["https://example.com/\x7F"],
]);

it('answers other for hosts that are not valid names', function (string $url) {
    // Given a host that cannot be a public name
    // When it is reduced
    // Then it is other
    expect(ReferrerSource::from($url))->toBe('other');
})->with([
    'ipv4' => ['http://192.168.0.1/x'], 'ipv6' => ['http://[::1]/x'], 'hex ipv4' => ['http://0x7f.1/'], 'decimal ipv4' => ['http://2130706433/'],
    'no dot' => ['https://intranet/'], 'key lookalike direct' => ['https://direct/'], 'key lookalike google' => ['https://google/'],
    'idn failure' => ['https://xn--/'], 'long host' => ['https://'.str_repeat('a', 40).'.'.str_repeat('b', 40).'.example/'],
    'underscore' => ['https://bad_host.example/'], 'leading hyphen' => ['https://-bad.example/'], 'trailing hyphen' => ['https://bad-.example/'],
    'numeric tld' => ['https://example.123/'], 'one letter tld' => ['https://example.c/'],
]);

it('converts an IDN host to its ASCII form', function () {
    // Given a host with a non-ASCII letter
    // When it is reduced
    // Then the punycode form comes back
    expect(ReferrerSource::from('https://bücher.example/'))->toBe('xn--bcher-kva.example');
});

it('keeps only the host: no credentials, port, path, query or fragment', function () {
    // Given a URL carrying all of them
    // When it is reduced
    // Then only the host remains
    expect(ReferrerSource::from('https://user:pass@blog.example:8443/a/b?c=d#e'))->toBe('blog.example');
});

it('never throws and always returns a well-formed source (property loop)', function () {
    // Given 200 generated inputs of every shape
    mt_srand(42);
    $fragments = ['https://', 'http://', 'www.', '.', '..', '-', 'xn--', '[', ']', ':', '@', '/', '?', '#', 'google.com', 't.co', 'a', 'Z', '0', '%00', ' ', "\n", "\xFF", 'é', '日本'];
    $outputs = [];

    // When each is reduced
    for ($i = 0; $i < 200; $i++) {
        $input = match ($i % 4) {
            0 => random_bytes(mt_rand(0, 64)),
            1 => str_repeat('a', mt_rand(0, 3000)),
            2 => implode('', array_map(fn () => $fragments[mt_rand(0, count($fragments) - 1)], range(0, mt_rand(1, 12)))),
            default => 'https://'.implode('', array_map(fn () => $fragments[mt_rand(0, count($fragments) - 1)], range(0, mt_rand(1, 6)))),
        };
        $outputs[] = ReferrerSource::from($input);
    }

    // Then every output has the allowed shape and length
    foreach ($outputs as $out) {
        expect(strlen($out))->toBeLessThanOrEqual(80);
        expect($out)->toMatch('/\A(direct|other|launchpad|[a-z_]+|[a-z0-9.-]{3,80})\z/');
    }
});

it('categorises sources from the config', function (string $source, string $category) {
    // Given a stored source key or host
    // When its category is asked
    // Then it comes from the config
    expect(ReferrerSource::category($source))->toBe($category);
})->with([
    ['direct', 'direct'], ['launchpad', 'internal'], ['google', 'search'], ['x', 'social'], ['other', 'other'], ['blog.example', 'other'],
]);

it('converts an IDN host before stripping www and the trailing dot', function (string $url, string $expected) {
    // Given hosts whose www prefix or trailing dot only appears after IDNA mapping (ideographic and fullwidth full stops)
    // When they are reduced
    // Then the strips still apply
    expect(ReferrerSource::from($url))->toBe($expected);
})->with([
    ["https://www\u{3002}google\u{3002}com/", 'google'],
    ["https://www\u{FF0E}bücher.example\u{3002}/", 'xn--bcher-kva.example'],
]);

it('looks a category up by key and never walks a dotted host into the config', function () {
    // Given a stored host that, as a dotted config path, would point at another config value
    // When its category is asked
    // Then it is other
    config(['referrers.sources' => ['blog' => ['category' => 'social', 'hosts' => ['blog.test'], 'example' => ['category' => 'search']], 'x' => ['category' => 'social', 'hosts' => ['x.com']]]]);
    expect(ReferrerSource::category('blog.example'))->toBe('other')->and(ReferrerSource::category('x'))->toBe('social')->and(ReferrerSource::category('blog'))->toBe('social');
});
