<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent (super admin): change a built-in role's permissions everywhere. Handled by HandleRoleTemplateUpdateSubmitted. */
class RoleTemplateUpdateSubmitted
{
    use Dispatchable;

    /** @param  list<string>  $permissions */
    public function __construct(public readonly int $roleId, public readonly array $permissions) {}
}
