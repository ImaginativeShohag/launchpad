<?php

namespace App\Console\Commands;

use App\Actions\Email\DispatchEmails;
use Illuminate\Console\Command;

class SendEmailsCommand extends Command
{
    protected $signature = 'launchpad:send-emails';

    protected $description = 'Send the due mail of the outbox (claims rows with a lease, retries with backoff, stops when the provider is down)';

    public function handle(DispatchEmails $dispatch): int
    {
        $result = $dispatch->handle();

        $this->info("sent {$result['sent']}, retried {$result['retried']}, suppressed {$result['suppressed']}, failed {$result['failed']}"
            .($result['exhausted'] > 0 ? ", exhausted {$result['exhausted']}" : '')
            .($result['stopped'] === null ? '' : ", stopped: {$result['stopped']}"));

        // A transient failure (an outage), a lease-exhausted row (mail that was lost) or a tripped breaker makes the run
        // non-zero so a monitor sees it. A permanently failed row (a bad address) is final: it is printed and logged, and exits 0.
        return $result['transient_failures'] > 0 || $result['retried'] > 0 || $result['exhausted'] > 0 || $result['stopped'] === 'breaker'
            ? self::FAILURE : self::SUCCESS;
    }
}
