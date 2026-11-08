<?php

namespace App\Listeners;

use App\Events\Commands\LeaseBillRequested;
use App\Models\Lease;
use App\Services\Billing\BillingService;

class HandleLeaseBillRequested
{
    public function __construct(private BillingService $billing) {}

    public function handle(LeaseBillRequested $event): void
    {
        $this->billing->billLeaseNow(Lease::query()->findOrFail($event->leaseId), $event->requestedBy);
    }
}
