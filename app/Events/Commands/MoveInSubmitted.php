<?php

namespace App\Events\Commands;

use App\Data\MoveInData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: move a tenant into a unit. Handled by HandleMoveInSubmitted. */
class MoveInSubmitted
{
    use Dispatchable;

    public function __construct(public readonly MoveInData $data) {}
}
