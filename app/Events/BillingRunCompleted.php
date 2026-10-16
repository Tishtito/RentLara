<?php

namespace App\Events;

use App\Models\BillingRun;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A billing run finished for one account. */
class BillingRunCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BillingRun $billingRun,
    ) {}
}
