<?php

namespace App\Listeners;

use App\Events\Commands\LeaseChargeExclusionSubmitted;
use App\Models\Lease;
use App\Services\Leases\ChargeExclusionService;

class HandleLeaseChargeExclusionSubmitted
{
    public function __construct(private ChargeExclusionService $exclusions) {}

    public function handle(LeaseChargeExclusionSubmitted $event): void
    {
        $this->exclusions->exclude(Lease::query()->findOrFail($event->leaseId), $event->chargeCode, $event->reason, $event->userId);
    }
}
