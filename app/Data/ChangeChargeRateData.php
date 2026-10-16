<?php

namespace App\Data;

use App\Enums\BillingType;
use Carbon\CarbonImmutable;

/**
 * A new price for one charge on a property (or one unit's override), from a date.
 * label / billingType / unitOfMeasure default to the current version's values.
 */
final readonly class ChangeChargeRateData
{
    public function __construct(
        public int $propertyId,
        public string $chargeCode,
        public string $rate,
        public CarbonImmutable $effectiveFrom,
        public ?int $unitId = null,
        public ?string $label = null,
        public ?BillingType $billingType = null,
        public ?string $unitOfMeasure = null,
        public ?int $createdBy = null,
    ) {}
}
