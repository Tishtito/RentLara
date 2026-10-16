<?php

namespace App\Listeners;

use App\Events\Commands\MeterSubmitted;
use App\Services\Properties\MeterService;

/** Runs synchronously so validation errors from the service still reach the client. */
class HandleMeterSubmitted
{
    public function __construct(private MeterService $service) {}

    public function handle(MeterSubmitted $event): void
    {
        $this->service->save($event->data);
    }
}
