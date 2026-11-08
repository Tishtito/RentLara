<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: change the rent on a running lease. Handled by HandleLeaseRentChangeSubmitted. */
class LeaseRentChangeSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly int $leaseId,
        public readonly string $monthlyRent,
        public readonly ?string $reason = null,
        public readonly ?int $changedBy = null,
    ) {}
}
