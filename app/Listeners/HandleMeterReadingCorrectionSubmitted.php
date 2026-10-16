<?php

namespace App\Listeners;

use App\Events\Commands\MeterReadingCorrectionSubmitted;
use App\Services\Meters\MeterReadingService;

class HandleMeterReadingCorrectionSubmitted
{
    public function __construct(private MeterReadingService $readings) {}

    public function handle(MeterReadingCorrectionSubmitted $event): void
    {
        $this->readings->correct($event->readingId, $event->reading, $event->correctedBy);
    }
}
