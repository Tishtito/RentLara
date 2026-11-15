<?php

namespace App\Listeners;

use App\Events\Commands\LeaseChargeInclusionRequested;
use App\Models\Lease;
use App\Services\Leases\ChargeExclusionService;

class HandleLeaseChargeInclusionRequested
{
    public function __construct(private ChargeExclusionService $exclusions) {}

    public function handle(LeaseChargeInclusionRequested $event): void
    {
        $this->exclusions->include(Lease::query()->findOrFail($event->leaseId), $event->chargeCode, $event->userId);
    }
}
