<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: fix a mistyped meter reading that isn't on a bill. Handled by HandleMeterReadingCorrectionSubmitted. */
class MeterReadingCorrectionSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly int $readingId,
        public readonly string $reading,
        public readonly ?int $correctedBy = null,
    ) {}
}
