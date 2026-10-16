<?php

namespace App\Events\Commands;

use App\Data\MeterData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: create or change a meter. Handled by HandleMeterSubmitted. */
class MeterSubmitted
{
    use Dispatchable;

    public function __construct(public readonly MeterData $data) {}
}
