<?php

namespace App\Models;

use App\Models\Scopes\AccountScope;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum API token. Tokens belong to users (admin API) or tenants (tenant portal apps).
 *
 * The owner is loaded before any account context exists (that is what the token establishes),
 * so the lookup skips the account scope; the `account` middleware sets the context afterwards.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function tokenable(): MorphTo
    {
        return parent::tokenable()->withoutGlobalScope(AccountScope::class);
    }
}
