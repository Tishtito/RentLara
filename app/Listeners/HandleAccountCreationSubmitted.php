<?php

namespace App\Listeners;

use App\Events\Commands\AccountCreationSubmitted;
use App\Services\Platform\AccountProvisioningService;

class HandleAccountCreationSubmitted
{
    public function __construct(private AccountProvisioningService $service) {}

    public function handle(AccountCreationSubmitted $event): void
    {
        $this->service->create($event->data);
    }
}
