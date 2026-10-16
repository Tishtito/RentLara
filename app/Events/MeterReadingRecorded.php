<?php

namespace App\Events;

use App\Models\MeterReading;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A meter reading was captured. */
class MeterReadingRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MeterReading $reading,
    ) {}
}
