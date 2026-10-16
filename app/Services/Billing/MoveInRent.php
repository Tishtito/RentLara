<?php

namespace App\Services\Billing;

use DateTimeImmutable;

/** Result of BillingCalendar::moveInRent(). Money in integer cents. */
final class MoveInRent
{
    public function __construct(
        public readonly DateTimeImmutable $periodStart,   // move-in date
        public readonly DateTimeImmutable $periodEnd,     // day before the first billing date
        public readonly int $days,                        // days charged (30 when moving in on the billing day)
        public readonly int $amountCents,                 // rent charged on the move-in bill
        public readonly int $monthlyRentCents,
        public readonly bool $fullMonth,                  // moved in on the billing day
        public readonly DateTimeImmutable $firstBillingDate,
    ) {}

    /** Daily rate shown on the bill line (monthly rent ÷ 30), rounded to the cent. */
    public function dailyRateCents(): int
    {
        return (int) round($this->monthlyRentCents / BillingCalendar::PRORATION_DAYS);
    }

    /** "Rent 20 Oct – 4 Nov (16 of 30 days)" */
    public function description(): string
    {
        $range = $this->periodStart->format('j M').' – '.$this->periodEnd->format('j M');

        return $this->fullMonth
            ? "Rent {$range}"
            : "Rent {$range} ({$this->days} of ".BillingCalendar::PRORATION_DAYS.' days)';
    }
}
