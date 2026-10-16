<?php

namespace App\Services\Billing;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Billing dates and move-in proration (confirmed rules, 16 Oct 2026):
 *
 *  - Each landlord account bills on ONE day of the month (accounts.billing_day, 1–28, default 5).
 *    Days 29–31 are not allowed so the date exists in every month, February included.
 *  - The monthly bill on that day charges rent and fixed charges for the coming month
 *    (billing date → day before the next billing date) plus metered usage up to the billing date.
 *  - A tenant who moves in pays, on a move-in bill: the deposit + prorated rent for the days
 *    from the move-in date (inclusive) up to the next billing date (exclusive):
 *        prorated rent = monthly rent × days ÷ 30      (always 30, whatever the month)
 *    rounded to the nearest shilling and never more than one month's rent.
 *    Moving in ON the billing day = a full month's rent.
 *
 * Pure date maths (no Laravel, no Carbon dependency) so it is easy to unit-test.
 * Carbon instances can be passed in; results are DateTimeImmutable at midnight.
 */
final class BillingCalendar
{
    public const DEFAULT_BILLING_DAY = 5;

    public const PRORATION_DAYS = 30;

    public static function assertValidBillingDay(int $day): void
    {
        if ($day < 1 || $day > 28) {
            throw new InvalidArgumentException("Billing day must be between 1 and 28, got {$day}.");
        }
    }

    /** First billing date strictly AFTER $date. Move in 20 Oct, day 5 → 5 Nov. Move in 5 Oct → 5 Nov. */
    public static function nextBillingDateAfter(DateTimeInterface $date, int $billingDay): DateTimeImmutable
    {
        self::assertValidBillingDay($billingDay);
        $d = self::day($date);
        $thisMonth = $d->setDate((int) $d->format('Y'), (int) $d->format('n'), $billingDay);

        if ($thisMonth > $d) {
            return $thisMonth;
        }
        $next = $d->modify('first day of next month');

        return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $billingDay);
    }

    /** Latest billing date on or BEFORE $date. 16 Oct, day 5 → 5 Oct. 3 Oct → 5 Sep. */
    public static function billingDateOnOrBefore(DateTimeInterface $date, int $billingDay): DateTimeImmutable
    {
        self::assertValidBillingDay($billingDay);
        $d = self::day($date);
        $thisMonth = $d->setDate((int) $d->format('Y'), (int) $d->format('n'), $billingDay);

        if ($thisMonth <= $d) {
            return $thisMonth;
        }
        $prev = $thisMonth->modify('first day of previous month');

        return $prev->setDate((int) $prev->format('Y'), (int) $prev->format('n'), $billingDay);
    }

    public static function isBillingDay(DateTimeInterface $date, int $billingDay): bool
    {
        return (int) self::day($date)->format('j') === $billingDay;
    }

    /**
     * Period a monthly bill issued on $billingDate covers for rent and fixed charges.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [start, end] inclusive
     */
    public static function monthlyPeriod(DateTimeInterface $billingDate, int $billingDay): array
    {
        $start = self::day($billingDate);
        $end = self::nextBillingDateAfter($start, $billingDay)->modify('-1 day');

        return [$start, $end];
    }

    /** Whole days from $from (inclusive) to $to (exclusive). */
    public static function daysBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) self::day($from)->diff(self::day($to))->format('%r%a');
    }

    /**
     * Move-in rent: from move-in date up to (not including) the next billing date.
     * Amounts are integer cents; the prorated amount is rounded to the whole shilling.
     */
    public static function moveInRent(int $monthlyRentCents, DateTimeInterface $moveInDate, int $billingDay): MoveInRent
    {
        $start = self::day($moveInDate);
        $nextBilling = self::nextBillingDateAfter($start, $billingDay);
        $periodEnd = $nextBilling->modify('-1 day');

        if (self::isBillingDay($start, $billingDay)) {
            return new MoveInRent($start, $periodEnd, self::PRORATION_DAYS, $monthlyRentCents, $monthlyRentCents, true, $nextBilling);
        }

        $days = self::daysBetween($start, $nextBilling);
        $shillings = (int) round(($monthlyRentCents / 100) * $days / self::PRORATION_DAYS);
        $amount = min($monthlyRentCents, $shillings * 100);

        return new MoveInRent($start, $periodEnd, $days, $amount, $monthlyRentCents, false, $nextBilling);
    }

    private static function day(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }
}
