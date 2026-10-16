<?php

namespace App\Listeners;

use App\Events\Commands\LeaseEndRequested;
use App\Models\Lease;
use App\Services\Leases\LeaseService;

class HandleLeaseEndRequested
{
    public function __construct(private LeaseService $leases) {}

    public function handle(LeaseEndRequested $event): void
    {
        $this->leases->end(Lease::query()->findOrFail($event->leaseId), $event->endDate);
    }
}
