<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: create (no roleId) or edit one of the account's own roles. Handled by HandleRoleSubmitted. */
class RoleSubmitted
{
    use Dispatchable;

    /** @param  list<string>  $permissions */
    public function __construct(
        public readonly int $accountId,
        public readonly string $name,
        public readonly array $permissions,
        public readonly ?int $roleId = null,
    ) {}
}
