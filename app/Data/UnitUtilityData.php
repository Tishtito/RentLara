<?php

namespace App\Data;

use App\Enums\BillingType;

/** How one of a property's metered utilities is billed for a unit: by meter, or a fixed amount (KES) a month. */
final readonly class UnitUtilityData
{
    public function __construct(
        public string $chargeCode,
        public BillingType $billingType,
        public ?string $amount = null, // fixed only
    ) {}
}
