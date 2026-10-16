<?php

namespace App\Events\Commands;

use App\Data\ChangeChargeRateData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: add a charge or set a new price from a date. Handled by HandleChargeRateChangeSubmitted. */
class ChargeRateChangeSubmitted
{
    use Dispatchable;

    public function __construct(public readonly ChangeChargeRateData $data) {}
}
