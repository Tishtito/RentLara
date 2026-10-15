<?php

namespace App\Support;

/**
 * Money maths in integer cents, so 0.1 + 0.2 style float errors never reach a bill.
 * Database columns are decimal(12,2); Eloquent hands them over as strings.
 */
final class Money
{
    public static function toCents(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    /** Back to the "1234.50" string format the decimal columns use. */
    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** quantity x rate, rounded to the cent. 80 units x 30.00 = "2400.00" */
    public static function multiply(string|int|float $quantity, string|int|float $rate): string
    {
        return self::fromCents((int) round(((float) $quantity) * ((float) $rate) * 100));
    }

    public static function sum(string|int|float|null ...$amounts): string
    {
        return self::fromCents(array_sum(array_map(self::toCents(...), $amounts)));
    }

    public static function format(string|int|float|null $amount, string $currency = 'KES'): string
    {
        return $currency.' '.number_format((float) $amount, 2);
    }
}
