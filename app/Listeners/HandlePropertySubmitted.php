<?php

namespace App\Listeners;

use App\Events\Commands\PropertySubmitted;
use App\Services\Properties\PropertyService;

/** Runs synchronously so validation errors from the service still reach the client. */
class HandlePropertySubmitted
{
    public function __construct(private PropertyService $service) {}

    public function handle(PropertySubmitted $event): void
    {
        $this->service->save($event->data);
    }
}
