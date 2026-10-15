<?php

namespace App\Enums;

enum LeaseStatus: string
{
    case Active = 'active';
    case Notice = 'notice';   // tenant has given notice; still billed
    case Ended = 'ended';

    /** Statuses the billing job picks up. */
    public static function billable(): array
    {
        return [self::Active->value, self::Notice->value];
    }
}
