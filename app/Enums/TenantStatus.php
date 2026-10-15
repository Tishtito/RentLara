<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Inactive = 'inactive';
}
