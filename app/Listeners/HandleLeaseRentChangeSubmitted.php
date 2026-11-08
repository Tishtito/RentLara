<?php

namespace App\Listeners;

use App\Events\Commands\LeaseRentChangeSubmitted;
use App\Models\Lease;
use App\Services\Leases\LeaseService;

class HandleLeaseRentChangeSubmitted
{
    public function __construct(private LeaseService $leases) {}

    public function handle(LeaseRentChangeSubmitted $event): void
    {
        $this->leases->changeRent(Lease::query()->findOrFail($event->leaseId), $event->monthlyRent, $event->reason, $event->changedBy);
    }
}
