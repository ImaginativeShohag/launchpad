<?php

use App\Enums\EmailStatus;

it('lists its values in declaration order', function () {
    // Given the enum
    // When its values are read
    // Then they are the five states
    expect(EmailStatus::values())->toBe(['queued', 'sending', 'sent', 'failed', 'suppressed']);
});

it('calls exactly sent, failed and suppressed terminal', function () {
    // Given every status
    // When terminality is asked
    $terminal = array_values(array_filter(EmailStatus::cases(), fn (EmailStatus $s) => $s->isTerminal()));

    // Then only the three final states are terminal
    expect($terminal)->toBe([EmailStatus::Sent, EmailStatus::Failed, EmailStatus::Suppressed]);
});
