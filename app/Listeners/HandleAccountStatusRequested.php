<?php

namespace App\Listeners;

use App\Events\Commands\AccountStatusRequested;
use App\Services\Platform\AccountProvisioningService;

class HandleAccountStatusRequested
{
    public function __construct(private AccountProvisioningService $service) {}

    public function handle(AccountStatusRequested $event): void
    {
        $this->service->setStatus($event->accountId, $event->status);
    }
}
