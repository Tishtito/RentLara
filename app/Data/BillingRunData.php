<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/** One billing run for one landlord account on one date. */
final readonly class BillingRunData
{
    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    public function __construct(
        public int $accountId,
        public CarbonImmutable $runDate,
        public string $trigger = self::TRIGGER_SCHEDULED,
        public ?int $triggeredBy = null,
    ) {}
}
