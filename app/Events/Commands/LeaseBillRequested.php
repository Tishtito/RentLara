<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: make one lease's due monthly bill now ("Bill now"). Handled by HandleLeaseBillRequested. */
class LeaseBillRequested
{
    use Dispatchable;

    public function __construct(public readonly int $leaseId, public readonly ?int $requestedBy = null) {}
}
