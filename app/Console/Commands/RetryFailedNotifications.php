<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformMessaging;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:retry-failed {--account= : Only this account ID}')]
#[Description('Resend notifications that failed (only failed ones; sent ones are never resent)')]
class RetryFailedNotifications extends Command
{
    public function handle(PlatformMessaging $messaging): int
    {
        $count = $messaging->retryFailed($this->option('account') ? (int) $this->option('account') : null);

        $this->info("Requested a retry for {$count} failed notification(s).");

        return self::SUCCESS;
    }
}
