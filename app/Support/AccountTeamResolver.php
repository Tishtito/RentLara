<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Spatie "teams" are landlord accounts, and the team is always the current account context
 * (CurrentAccount, set by the `account` middleware, jobs and commands). One source of truth, so
 * permission checks can never use a different account than the data queries.
 */
class AccountTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): int|string|null
    {
        return app(CurrentAccount::class)->id();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        app(CurrentAccount::class)->set($id instanceof Model ? $id->getKey() : ($id === null ? null : (int) $id));
    }
}
