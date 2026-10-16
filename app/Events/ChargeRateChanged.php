<?php

namespace App\Events;

use App\Models\ChargeRate;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A new charge rate version took effect; $previous is the version it closed, if any. */
class ChargeRateChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChargeRate $rate,
        public readonly ?ChargeRate $previous = null,
    ) {}
}
