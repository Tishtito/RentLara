<?php

namespace App\Events\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: save the meter readings taken on one day. Handled by HandleMeterReadingsSubmitted. */
class MeterReadingsSubmitted
{
    use Dispatchable;

    /** @param  array<int, array{meter_id: int, reading: string}>  $readings */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly array $readings,
        public readonly ?int $recordedBy = null,
    ) {}
}
