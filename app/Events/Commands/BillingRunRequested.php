<?php

namespace App\Events\Commands;

use App\Data\BillingRunData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: bill an account's due leases (daily schedule or manual). Handled by HandleBillingRunRequested. */
class BillingRunRequested
{
    use Dispatchable;

    public function __construct(public readonly BillingRunData $data) {}
}
