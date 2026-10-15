<?php

namespace App\Enums;

enum BillingType: string
{
    case Metered = 'metered'; // electricity, water: units consumed x rate
    case Fixed = 'fixed';     // garbage, security: flat amount per cycle
}
