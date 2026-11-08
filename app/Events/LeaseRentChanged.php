<?php

namespace App\Events;

use App\Models\LeaseRentChange;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A lease's rent changed; the next bill charges the new rent. */
class LeaseRentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly LeaseRentChange $change) {}
}
