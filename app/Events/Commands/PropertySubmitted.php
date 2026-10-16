<?php

namespace App\Events\Commands;

use App\Data\PropertyData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: create or change a property. Handled by HandlePropertySubmitted. */
class PropertySubmitted
{
    use Dispatchable;

    public function __construct(public readonly PropertyData $data) {}
}
