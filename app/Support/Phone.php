<?php

namespace App\Support;

/** Kenyan phone numbers. Stored in E.164 (+2547…, +2541…). */
final class Phone
{
    /**
     * "0712 345 678", "712345678", "254712345678", "+254-712-345-678" → "+254712345678".
     * Returns null for anything that isn't a Kenyan mobile number (07xx / 01xx).
     */
    public static function toE164(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        $local = match (true) {
            str_starts_with($digits, '254') && strlen($digits) === 12 => substr($digits, 3),
            str_starts_with($digits, '0') && strlen($digits) === 10 => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        return $local !== null && preg_match('/^[17]\d{8}$/', $local) ? '+254'.$local : null;
    }
}
