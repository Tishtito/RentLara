<?php

namespace App\Listeners;

use App\Events\Commands\RoleDeleteRequested;
use App\Services\Authorization\RoleService;

class HandleRoleDeleteRequested
{
    public function __construct(private RoleService $roles) {}

    public function handle(RoleDeleteRequested $event): void
    {
        $this->roles->delete($event->accountId, $event->roleId);
    }
}
