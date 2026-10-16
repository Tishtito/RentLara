<?php

namespace App\Listeners;

use App\Events\Commands\MeterReadingsSubmitted;
use App\Models\Meter;
use App\Services\Meters\MeterReadingService;

/** Runs synchronously so "lower than the previous reading" errors still reach the client. */
class HandleMeterReadingsSubmitted
{
    public function __construct(private MeterReadingService $readings) {}

    public function handle(MeterReadingsSubmitted $event): void
    {
        $meters = Meter::query()->whereKey(array_column($event->readings, 'meter_id'))->get()->keyBy('id');

        $this->readings->recordMany($event->date, array_map(fn (array $r) => [
            'meter' => $meters[$r['meter_id']],
            'reading' => $r['reading'],
        ], $event->readings), $event->recordedBy);
    }
}
