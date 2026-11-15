<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: charge a tenant again for a charge they were excused from. Handled by HandleLeaseChargeInclusionRequested. */
class LeaseChargeInclusionRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $leaseId,
        public readonly string $chargeCode,
        public readonly ?int $userId = null,
    ) {}
}
