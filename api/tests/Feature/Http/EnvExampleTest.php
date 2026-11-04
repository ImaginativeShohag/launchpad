<?php

/** The example env is what a new deployment copies, so its safe defaults are pinned. */
function envExample(): array
{
    $values = [];
    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] !== '#' && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }
    }

    return $values;
}

it('ships safe production-leaning defaults in .env.example', function () {
    // Given the example env file
    $env = envExample();

    // When its security-relevant keys are read
    // Then debug is off, the cookie is host-only, hashing is argon2id, no proxy is trusted and the sync queue is not used
    expect($env['APP_DEBUG'])->toBe('false');
    expect($env['SESSION_DOMAIN'])->toBe('');
    expect($env['HASH_DRIVER'])->toBe('argon2id');
    expect($env['TRUSTED_PROXIES'])->toBe('');
    expect($env['QUEUE_CONNECTION'])->not->toBe('sync');
    expect($env['LAUNCHPAD_HMAC_KEY'])->toBe('');
});
