<?php

namespace App\Events\Commands;

use App\Data\MeterData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: fit many meters on one property (meter setup). Handled by HandleMetersSubmitted. */
class MetersSubmitted
{
    use Dispatchable;

    /** @param  list<MeterData>  $meters */
    public function __construct(
        public readonly int $propertyId,
        public readonly array $meters,
    ) {}
}
