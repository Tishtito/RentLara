<?php

namespace App\Listeners;

use App\Events\Commands\MoveInSubmitted;
use App\Services\Leases\MoveInService;

/** Runs synchronously so validation errors from the service still reach the client. */
class HandleMoveInSubmitted
{
    public function __construct(private MoveInService $moveIns) {}

    public function handle(MoveInSubmitted $event): void
    {
        $this->moveIns->moveIn($event->data);
    }
}
