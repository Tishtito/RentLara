<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: delete one of the account's own roles. Handled by HandleRoleDeleteRequested. */
class RoleDeleteRequested
{
    use Dispatchable;

    public function __construct(public readonly int $accountId, public readonly int $roleId) {}
}
