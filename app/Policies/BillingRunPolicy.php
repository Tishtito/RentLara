<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/** Super admins pass every check via Gate::before (AppServiceProvider). */
class BillingRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BillingView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BillingRun->value);
    }
}
