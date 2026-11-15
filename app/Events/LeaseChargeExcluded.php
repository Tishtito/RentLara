<?php

namespace App\Events;

use App\Models\LeaseChargeExclusion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A tenant was excused from a charge (from their next bill). */
class LeaseChargeExcluded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly LeaseChargeExclusion $exclusion) {}
}
