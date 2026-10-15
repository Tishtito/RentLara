<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Landlord = 'landlord';
    case Caretaker = 'caretaker';
}
