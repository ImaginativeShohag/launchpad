<?php

it('pins the outbox constants', function () {
    // Given the application config
    // When the outbox block is read
    $outbox = config('launchpad.outbox');

    // Then every value is the documented one
    expect($outbox)->toBe([
        'batch' => 50, 'budget_seconds' => 50, 'lease_minutes' => 10, 'max_attempts' => 9, 'breaker' => 5,
        'backoff_minutes' => [1, 5, 15, 60, 180, 360, 720, 1440],
    ]);
});

it('has one backoff wait per retry', function () {
    // Given the outbox block
    // When the waits are counted
    // Then there are max_attempts - 1 of them and they never shrink
    $outbox = config('launchpad.outbox');
    expect($outbox['backoff_minutes'])->toHaveCount($outbox['max_attempts'] - 1);
    $sorted = $outbox['backoff_minutes'];
    sort($sorted);
    expect($sorted)->toBe($outbox['backoff_minutes']);
});

it('bounds the smtp connection so a hung server cannot stall the outbox run', function () {
    // Given the mail config
    // When the smtp mailer timeout is read
    // Then it is a positive integer (20 seconds by default)
    expect(config('mail.mailers.smtp.timeout'))->toBeInt()->toBeGreaterThan(0);
    expect(file_get_contents(base_path('.env.example')))->toContain('MAIL_TIMEOUT=20');
});
