<?php

use Symfony\Component\Yaml\Yaml;

/** Evaluates config/reverb.php with REVERB_ALLOWED_ORIGINS set to $value (null = unset), then restores the environment. */
function reverbOrigins(?string $value): array
{
    $previous = [getenv('REVERB_ALLOWED_ORIGINS'), $_SERVER['REVERB_ALLOWED_ORIGINS'] ?? null, $_ENV['REVERB_ALLOWED_ORIGINS'] ?? null];
    $set = function (?string $v): void {
        if ($v === null) {
            putenv('REVERB_ALLOWED_ORIGINS');
            unset($_SERVER['REVERB_ALLOWED_ORIGINS'], $_ENV['REVERB_ALLOWED_ORIGINS']);
        } else {
            putenv('REVERB_ALLOWED_ORIGINS='.$v);
            $_SERVER['REVERB_ALLOWED_ORIGINS'] = $_ENV['REVERB_ALLOWED_ORIGINS'] = $v;
        }
    };
    $set($value);

    try {
        return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
    } finally {
        $set($previous[0] === false ? $previous[1] ?? $previous[2] : $previous[0]);
    }
}

it('never allows every origin by default', function () {
    // Given the shipped config
    // When the loaded origins are read
    $origins = config('reverb.apps.apps.0.allowed_origins');

    // Then they are a non-empty list without a wildcard
    expect($origins)->toBeArray()->not->toBeEmpty()->not->toContain('*');
});

it('parses REVERB_ALLOWED_ORIGINS into hosts and never lets a wildcard through', function (?string $env, array $expected) {
    // Given an environment value
    // When the config file is evaluated
    $origins = reverbOrigins($env);

    // Then it is trimmed, reduced to hosts, de-duplicated and wildcard-free; a useless value falls back to the web origin
    expect($origins)->toBe($expected);
})->with([
    'unset' => [null, ['localhost']],
    'empty' => ['', ['localhost']],
    'blanks and commas only' => [' , ,, ', ['localhost']],
    'single origin' => ['https://app.example.com', ['app.example.com']],
    'origin with port' => ['http://localhost:5173', ['localhost']],
    'bare host' => ['app.example.com', ['app.example.com']],
    'host with port' => ['app.example.com:8443', ['app.example.com']],
    'list with spaces and empties' => [' https://a.example.com , ,b.example.com,, ', ['a.example.com', 'b.example.com']],
    'upper case' => ['HTTPS://App.Example.COM', ['app.example.com']],
    'duplicates' => ['https://a.example.com,http://a.example.com:81', ['a.example.com']],
    'star' => ['*', ['localhost']],
    'double star' => ['**', ['localhost']],
    'scheme star' => ['https://*', ['localhost']],
    'star among others' => ['https://a.example.com,*', ['a.example.com']],
    'subdomain pattern' => ['*.example.com', ['*.example.com']],
    'star dot star' => ['*.*', ['localhost']],
    'star tld' => ['*.com', ['localhost']],
    'prefix star' => ['a*', ['localhost']],
    'trailing star label' => ['*.example.*', ['localhost']],
    'scheme star dot star' => ['https://*.*', ['localhost']],
    'two leading stars' => ['*.*.example.com', ['localhost']],
    'star inside a label' => ['a*.example.com', ['localhost']],
    'single label host' => ['intranet', ['localhost']],
    'ip literal' => ['http://127.0.0.1:5173', ['localhost']],
    'userinfo trick yields the real host only' => ['http://localhost@evil.example.com', ['evil.example.com']],
    'userinfo trick with port' => ['https://localhost:443@evil.example.com:8443', ['evil.example.com']],
    'bad entry among good ones' => ['*.*, https://a.example.com, *', ['a.example.com']],
    'underscore' => ['a_b.example.com', ['localhost']],
]);

it('documents the settings in .env.example without a key or secret', function () {
    // Given the example environment file
    $example = file_get_contents(base_path('.env.example'));

    // When its Reverb lines are read
    // Then the new variables exist, and the key and secret stay blank
    expect($example)->toContain('REVERB_ALLOWED_ORIGINS=')->toContain('REVERB_SERVER_HOST=')->toContain('REVERB_SERVER_PORT=');
    expect($example)->toMatch('/^REVERB_APP_KEY=$/m')->toMatch('/^REVERB_APP_SECRET=$/m');
});

it('keeps the other services and runs Reverb on loopback with its own blank-secret check', function () {
    // Given the parsed compose file
    $services = Yaml::parseFile(base_path('compose.yaml'))['services'];

    // When the services are read
    // Then the old services are intact and Reverb is a loopback-only, build-less service that starts reverb and checks its secrets
    expect(array_keys($services))->toContain('laravel.test', 'pgsql', 'redis', 'mailpit', 'reverb');
    expect($services['laravel.test']['ports'])->toContain('${APP_PORT:-80}:80');
    $reverb = $services['reverb'];
    expect($reverb['ports'])->toBe(['127.0.0.1:${REVERB_PORT:-8080}:8080']);
    expect($reverb)->not->toHaveKey('build');
    foreach ($reverb['ports'] as $mapping) {
        expect($mapping)->not->toMatch('/:80$/');
    }
    $command = implode(' ', $reverb['command']);
    expect($command)->toContain('reverb:start')->toContain('REVERB_APP_SECRET')->toContain('exit 1');
    expect($reverb['depends_on'])->toBe(['laravel.test']);
});

it('gives the Pusher client short timeouts so a dead Reverb cannot stall a request', function () {
    // Given the broadcasting config
    $options = config('broadcasting.connections.reverb.client_options');

    // When the client options are read
    // Then both timeouts are short
    expect($options['timeout'])->toBeLessThanOrEqual(3)->and($options['connect_timeout'])->toBeLessThanOrEqual(2);
});
