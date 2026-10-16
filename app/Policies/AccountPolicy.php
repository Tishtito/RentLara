<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;

/** Staff see their own account; changing its billing settings needs "settings.billing". Super admins pass via Gate::before. */
class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $user->account_id === $account->id;
    }

    public function update(User $user, Account $account): bool
    {
        return $user->account_id === $account->id && $user->can(Permission::SettingsBilling->value);
    }
}
