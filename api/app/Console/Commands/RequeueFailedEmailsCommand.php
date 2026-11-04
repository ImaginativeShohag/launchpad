<?php

namespace App\Console\Commands;

use App\Actions\Email\RequeueFailedEmails;
use App\Enums\EmailType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RequeueFailedEmailsCommand extends Command
{
    protected $signature = 'launchpad:requeue-failed-emails {--since=48h : How far back failures count, like 36h or 7d} {--type= : Only this email type}';

    protected $description = 'Manually put failed outbox mail back in the queue (not scheduled)';

    public function handle(RequeueFailedEmails $requeue): int
    {
        $since = $this->option('since');
        if (! is_string($since) || preg_match('/\A([1-9]\d{0,3})([hd])\z/', $since, $m) !== 1) {
            $this->error('--since must look like 36h or 7d.');

            return self::INVALID;
        }

        $type = null;
        $typeOption = $this->option('type');
        if ($typeOption !== null) {
            $type = is_string($typeOption) ? EmailType::tryFrom($typeOption) : null;
            if ($type === null) {
                $this->error('--type must be one of: '.implode(', ', EmailType::values()).'.');

                return self::INVALID;
            }
        }

        $count = $requeue->handle(CarbonImmutable::now('UTC'), (int) $m[1] * ($m[2] === 'd' ? 24 : 1), $type);
        $this->info("requeued {$count}");

        return self::SUCCESS;
    }
}
