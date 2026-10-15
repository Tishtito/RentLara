<?php

namespace App\Support;

use App\Models\Scopes\AccountScope;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Auth provider for the tenant guard. Tenant is account-scoped, but the guard
 * has to load the logged-in tenant from the session BEFORE SetCurrentAccount
 * runs, so lookups here skip the account scope. Everything after login is scoped.
 */
class AccountlessUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(AccountScope::class);
    }
}
