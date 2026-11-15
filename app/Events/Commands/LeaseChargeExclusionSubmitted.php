<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: excuse a tenant from one charge. Handled by HandleLeaseChargeExclusionSubmitted. */
class LeaseChargeExclusionSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly int $leaseId,
        public readonly string $chargeCode,
        public readonly ?string $reason = null,
        public readonly ?int $userId = null,
    ) {}
}
