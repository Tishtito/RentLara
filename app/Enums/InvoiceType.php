<?php

namespace App\Enums;

/** What a bill is for. */
enum InvoiceType: string
{
    /** Deposit + prorated rent up to the first billing day, made when the tenant moves in. */
    case MoveIn = 'move_in';

    /** Rent and fixed charges for the coming month + metered usage, made on the billing day. */
    case Monthly = 'monthly';

    /** Made by hand (e.g. a final bill). */
    case Adjustment = 'adjustment';
}
