<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Str;

/** URL-safe, unique account slugs ("kims-properties", "kims-properties-2"). */
final class AccountSlug
{
    public static function unique(string $name): string
    {
        $base = Str::slug($name) ?: 'landlord';
        $slug = $base;

        for ($i = 2; Account::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
