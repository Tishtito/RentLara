<?php

namespace App\Listeners;

use App\Events\Commands\UnitSubmitted;
use App\Services\Properties\UnitService;

/** Runs synchronously so validation errors from the service still reach the client. */
class HandleUnitSubmitted
{
    public function __construct(private UnitService $service) {}

    public function handle(UnitSubmitted $event): void
    {
        $this->service->save($event->data);
    }
}
