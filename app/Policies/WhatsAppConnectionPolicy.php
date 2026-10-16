<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/** Super admins pass every check via Gate::before (AppServiceProvider). */
class WhatsAppConnectionPolicy
{
    public function view(User $user): bool
    {
        return $user->can(Permission::WhatsAppManage->value);
    }

    public function update(User $user): bool
    {
        return $user->can(Permission::WhatsAppManage->value);
    }
}
