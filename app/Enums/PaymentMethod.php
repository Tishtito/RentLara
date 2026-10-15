<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Mpesa = 'mpesa';
    case Bank = 'bank';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Other = 'other';
}
