<?php

namespace App\Events\Commands;

use App\Data\UnitData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: create or change a unit. Handled by HandleUnitSubmitted. */
class UnitSubmitted
{
    use Dispatchable;

    public function __construct(public readonly UnitData $data) {}
}
