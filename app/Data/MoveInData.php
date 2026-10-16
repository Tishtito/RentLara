<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/** A tenant moving into a unit: the tenant, their lease and the meters' opening readings. */
final readonly class MoveInData
{
    /**
     * @param  array{first_name: string, last_name: string, phone: string, email: ?string, national_id: ?string}  $tenant
     * @param  array<int, string>  $openingReadings  meter id → reading on the move-in date
     */
    public function __construct(
        public int $unitId,
        public array $tenant,
        public string $monthlyRent,
        public string $depositAmount,
        public string $openingBalance,
        public CarbonImmutable $startDate,
        public array $openingReadings = [],
        public ?int $createdBy = null,
        /** Already living there: no move-in bill, deposit already held, readings taken today. */
        public bool $existing = false,
        /** Existing tenants: the first Rentflow bill (default the next billing day). */
        public ?CarbonImmutable $firstBillDate = null,
    ) {}
}
