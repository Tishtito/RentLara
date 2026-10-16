<?php

namespace App\Listeners;

use App\Events\Commands\MetersSubmitted;
use App\Models\Property;
use App\Services\Properties\MeterService;

class HandleMetersSubmitted
{
    public function __construct(private MeterService $meters) {}

    public function handle(MetersSubmitted $event): void
    {
        $this->meters->fitMany(Property::query()->findOrFail($event->propertyId), $event->meters);
    }
}
