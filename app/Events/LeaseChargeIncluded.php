<?php

namespace App\Events;

use App\Models\LeaseChargeExclusion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A tenant was charged again for a charge they were excused from (from their next bill). */
class LeaseChargeIncluded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly LeaseChargeExclusion $exclusion) {}
}
