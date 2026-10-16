<?php

namespace Tests\Unit;

use App\Services\Billing\BillingCalendar;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The billing rules confirmed on 16 Oct 2026. Pure PHP: runs without a database. */
class BillingCalendarTest extends TestCase
{
    public static function nextBillingDates(): array
    {
        return [
            'after billing day → next month' => ['2026-10-20', 5, '2026-11-05'],
            'on billing day → next month' => ['2026-10-05', 5, '2026-11-05'],
            'before billing day → same month' => ['2026-10-03', 5, '2026-10-05'],
            'across year end' => ['2026-12-20', 5, '2027-01-05'],
            'day 28 in February' => ['2027-01-31', 28, '2027-02-28'],
        ];
    }

    #[DataProvider('nextBillingDates')]
    public function test_next_billing_date(string $from, int $day, string $expected): void
    {
        $this->assertSame($expected, BillingCalendar::nextBillingDateAfter(new DateTimeImmutable($from), $day)->format('Y-m-d'));
    }

    public static function moveIns(): array
    {
        // [move-in, billing day, monthly rent KES, expected days, expected rent KES]
        return [
            'example: 20 Oct, rent 15,000 → 16 days → 8,000' => ['2026-10-20', 5, 15000, 16, 8000],
            'moving in on the billing day = full month' => ['2026-10-05', 5, 15000, 30, 15000],
            'day after billing day in a 31-day month is capped' => ['2026-08-06', 5, 15000, 30, 15000],
            'four days' => ['2026-10-01', 5, 15000, 4, 2000],
            'rounded to the shilling: 16,900 × 16/30 = 9,013.33' => ['2026-10-20', 5, 16900, 16, 9013],
            'February counts 27 days' => ['2027-02-06', 5, 15000, 27, 13500],
        ];
    }

    #[DataProvider('moveIns')]
    public function test_move_in_rent(string $moveIn, int $day, int $rent, int $days, int $amount): void
    {
        $r = BillingCalendar::moveInRent($rent * 100, new DateTimeImmutable($moveIn), $day);

        $this->assertSame($days, $r->days);
        $this->assertSame($amount * 100, $r->amountCents);
    }

    public function test_move_in_rent_description_and_first_bill(): void
    {
        $r = BillingCalendar::moveInRent(1500000, new DateTimeImmutable('2026-10-20'), 5);

        $this->assertSame('Rent 20 Oct – 4 Nov (16 of 30 days)', $r->description());
        $this->assertSame('2026-11-05', $r->firstBillingDate->format('Y-m-d'));
    }

    public function test_monthly_period(): void
    {
        [$start, $end] = BillingCalendar::monthlyPeriod(new DateTimeImmutable('2026-11-05'), 5);

        $this->assertSame('2026-11-05', $start->format('Y-m-d'));
        $this->assertSame('2026-12-04', $end->format('Y-m-d'));
    }

    public function test_billing_day_must_exist_every_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BillingCalendar::nextBillingDateAfter(new DateTimeImmutable('2026-01-01'), 31);
    }
}
