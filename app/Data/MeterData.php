<?php

namespace App\Data;

/** A meter fitted to a unit; with $replacesMeterId it replaces (deactivates) that meter. */
final readonly class MeterData
{
    public function __construct(
        public int $unitId,
        public string $chargeCode,
        public string $initialReading,
        public ?string $meterNumber = null,
        public ?int $replacesMeterId = null,
    ) {}
}
