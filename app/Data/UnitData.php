<?php

namespace App\Data;

use App\Enums\UnitStatus;

/** A unit to add to a property, or (with $unitId) the new details of an existing one. */
final readonly class UnitData
{
    public function __construct(
        public int $propertyId,
        public string $unitNumber,
        public string $defaultRent,
        public string $defaultDeposit = '0.00',
        public ?string $type = null,
        public ?int $bedrooms = null,
        public ?int $floor = null,
        public ?UnitStatus $status = null, // null = keep (new units: vacant)
        public ?string $notes = null,
        public ?int $unitId = null,
    ) {}
}
