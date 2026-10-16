<?php

namespace App\Events\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: a tenant moves out. Handled by HandleLeaseEndRequested. */
class LeaseEndRequested
{
    use Dispatchable;

    public function __construct(public readonly int $leaseId, public readonly CarbonImmutable $endDate) {}
}
