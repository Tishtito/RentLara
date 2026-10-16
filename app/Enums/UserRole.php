<?php

namespace App\Enums;

enum UserRole: string
{
    /** Platform operator: no account, passes every check (Gate::before). */
    case SuperAdmin = 'super_admin';
    /** Someone working in a landlord account; what they may do comes from their roles (Spatie, per account). */
    case Staff = 'staff';
}
