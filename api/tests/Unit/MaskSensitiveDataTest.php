<?php

use App\Logging\MaskSensitiveData;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

function maskedRecord(string $message, array $context = []): LogRecord
{
    $handler = new TestHandler;
    $logger = new Logger('test', [$handler]);
    (new MaskSensitiveData)($logger);

    $logger->info($message, $context);

    return $handler->getRecords()[0];
}

it('masks emails and redacts secrets in log records', function () {
    // Given a logger with the masking tap and a capturing handler
    // When something sensitive is logged
    $record = maskedRecord('Login failed for amina@example.com', [
        'email' => 'amina@example.com',
        'password' => 'hunter2hunter2',
        'nested' => ['token' => 'abc', 'note' => 'contact rafi@x.org'],
    ]);

    // Then emails are masked and secrets are redacted
    expect($record->message)->toBe('Login failed for a***@example.com');
    expect($record->context['email'])->toBe('a***@example.com');
    expect($record->context['password'])->toBe('[redacted]');
    expect($record->context['nested']['token'])->toBe('[redacted]');
    expect($record->context['nested']['note'])->toBe('contact r***@x.org');
});

it('matches sensitive keys case-insensitively', function () {
    // Given keys written in other cases
    // When they are logged
    $record = maskedRecord('x', ['Password' => 'a', 'X-XSRF-TOKEN' => 'b', 'Cookie' => 'c']);

    // Then all values are redacted
    expect($record->context)->toBe(['Password' => '[redacted]', 'X-XSRF-TOKEN' => '[redacted]', 'Cookie' => '[redacted]']);
});

it('matches sensitive keys by substring, whatever the value type', function () {
    // Given keys that merely contain a sensitive word
    // When they are logged
    $record = maskedRecord('x', ['client_secret' => 's', 'access_token' => ['a' => 1], 'lp_did' => 5, 'X-Device-Id' => 'd']);

    // Then the values are redacted
    expect($record->context)->toBe([
        'client_secret' => '[redacted]',
        'access_token' => '[redacted]',
        'lp_did' => '[redacted]',
        'X-Device-Id' => '[redacted]',
    ]);
});

it('redacts code and state only on exact key match', function () {
    // Given exact and near-miss keys
    // When they are logged
    $record = maskedRecord('x', ['code' => 'c', 'STATE' => 's', 'postcode' => '1000', 'estate' => 'home']);

    // Then only the exact keys are redacted
    expect($record->context)->toBe(['code' => '[redacted]', 'STATE' => '[redacted]', 'postcode' => '1000', 'estate' => 'home']);
});

it('leaves non-sensitive keys unchanged', function () {
    // Given harmless data
    // When it is logged
    $record = maskedRecord('hello', ['user_id' => 'abc', 'count' => 3]);

    // Then nothing changes
    expect($record->context)->toBe(['user_id' => 'abc', 'count' => 3]);
    expect($record->message)->toBe('hello');
});

it('redacts secrets in query strings', function () {
    // Given a URL containing a signature
    // When it is logged as message and context
    $record = maskedRecord('GET /verify?id=1&signature=abc123&x=1', ['url' => '/cb?code=zzz&state=yyy&x=1']);

    // Then the secret values are redacted and the rest stays
    expect($record->message)->toBe('GET /verify?id=1&signature=[redacted]&x=1');
    expect($record->context['url'])->toBe('/cb?code=[redacted]&state=[redacted]&x=1');
});

it('redacts bearer tokens', function () {
    // Given an authorization value inside free text
    // When it is logged
    $record = maskedRecord('sent Bearer abcdef.ghi-jk to server', ['header' => 'bearer 123abc']);

    // Then the token is redacted
    expect($record->message)->toBe('sent Bearer [redacted] to server');
    expect($record->context['header'])->toBe('bearer [redacted]');
});

it('leaves a non-email at-sign alone', function () {
    // Given text with an @ that is not an email
    // When it is logged
    $record = maskedRecord('price @ 5', ['note' => 'meet @ noon']);

    // Then it is unchanged
    expect($record->message)->toBe('price @ 5');
    expect($record->context['note'])->toBe('meet @ noon');
});

it('scrubs deeply nested arrays and stringable values', function () {
    // Given nested data and a Stringable holding an email
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'from amina@example.com';
        }
    };

    // When it is logged
    $record = maskedRecord('x', ['a' => ['b' => ['c' => ['password' => 'p', 'mail' => 'rafi@x.org']]], 's' => $stringable]);

    // Then every level is scrubbed
    expect($record->context['a']['b']['c'])->toBe(['password' => '[redacted]', 'mail' => 'r***@x.org']);
    expect($record->context['s'])->toBe('from a***@example.com');
});

it('stops recursing at a safe depth', function () {
    // Given a very deep array
    $deep = 'leaf';
    for ($i = 0; $i < 20; $i++) {
        $deep = ['k' => $deep];
    }

    // When it is logged
    $record = maskedRecord('x', $deep);

    // Then the deep part is truncated
    $node = $record->context;
    $depth = 0;
    while (is_array($node)) {
        $node = $node['k'];
        $depth++;
    }
    expect($node)->toBe('[truncated]');
    expect($depth)->toBeLessThanOrEqual(9);
});

it('masks exceptions in context and drops the trace', function () {
    // Given an exception whose message holds an email and a token
    $e = new RuntimeException('bad amina@example.com ?token=abc');

    // When it is logged
    $record = maskedRecord('failed', ['exception' => $e]);

    // Then only a masked summary remains
    expect($record->context['exception'])->toBe([
        'class' => RuntimeException::class,
        'message' => 'bad a***@example.com ?token=[redacted]',
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
});

it('fails closed when masking itself throws', function () {
    // Given a Stringable value whose conversion throws
    $bomb = new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('boom');
        }
    };

    // When it is logged together with other data
    $record = maskedRecord('leaky amina@example.com', ['x' => $bomb, 'email' => 'amina@example.com']);

    // Then the log call does not throw and none of the original data survives
    expect($record->message)->toBe('[log masking failed]');
    expect($record->context)->toBe([]);
    expect($record->extra)->toBe([]);
});

it('leaves a normal record unaffected by the fail-closed wrapper', function () {
    // Given an ordinary record
    // When it is logged
    $record = maskedRecord('ok', ['id' => 7]);

    // Then it passes through unchanged
    expect($record->message)->toBe('ok');
    expect($record->context)->toBe(['id' => 7]);
});

it('masks url-encoded and html-escaped query secrets', function () {
    // Given encoded query strings
    // When they are logged
    $record = maskedRecord('a?x=1&signature%3Dabc&y=2', [
        'u1' => 'https://h/p?token%3Dsecret123&z=1',
        'u2' => '/cb?a=1&amp;code=zzz&amp;b=2',
        'u3' => '/cb?a=1;state=qqq',
        'u4' => 'https://h/p?next=%2Fa%26token%3Dabc',
    ]);

    // Then the secret values are redacted
    expect($record->message)->toContain('signature%3D[redacted]')->not->toContain('abc');
    expect($record->context['u1'])->toBe('https://h/p?token%3D[redacted]&z=1');
    expect($record->context['u2'])->toBe('/cb?a=1&amp;code=[redacted]&amp;b=2');
    expect($record->context['u3'])->toBe('/cb?a=1;state=[redacted]');
    expect($record->context['u4'])->not->toContain('abc');
});

it('matches query secret keys case-insensitively', function () {
    // Given mixed-case query keys
    // When they are logged
    $record = maskedRecord('/p?Signature=abc&TOKEN=q&x=1');

    // Then they are redacted
    expect($record->message)->toBe('/p?Signature=[redacted]&TOKEN=[redacted]&x=1');
});

it('redacts secrets inside json-looking strings', function () {
    // Given JSON text in a string value
    // When it is logged
    $record = maskedRecord('body {"password":"hunter2","name":"Amina"}', ['raw' => '{"Access_Token": "abc", "ok": "yes"}']);

    // Then only the secret values are redacted
    expect($record->message)->toBe('body {"password":"[redacted]","name":"Amina"}');
    expect($record->context['raw'])->toBe('{"Access_Token": "[redacted]", "ok": "yes"}');
});

it('redacts basic credentials', function () {
    // Given an Authorization header inside text
    // When it is logged
    $record = maskedRecord('Authorization: Basic dXNlcjpwYXNz', ['h' => 'basic dXNlcjpwYXNz=']);

    // Then the credentials are redacted
    expect($record->message)->toBe('Authorization: Basic [redacted]');
    expect($record->context['h'])->toBe('basic [redacted]');
});
