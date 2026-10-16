<?php

namespace App\Listeners;

use App\Events\Commands\FailedNotificationsRetryRequested;
use App\Services\Platform\PlatformMessaging;

class HandleFailedNotificationsRetryRequested
{
    public function __construct(private PlatformMessaging $service) {}

    public function handle(FailedNotificationsRetryRequested $event): void
    {
        $this->service->retryFailed($event->accountId);
    }
}
