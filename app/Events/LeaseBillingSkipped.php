<?php

namespace App\Events;

use App\Models\BillingRun;
use App\Models\Lease;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The billing run could not bill a lease (e.g. a missing meter reading). */
class LeaseBillingSkipped implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BillingRun $billingRun,
        public readonly Lease $lease,
        public readonly string $reason,
    ) {}
}
