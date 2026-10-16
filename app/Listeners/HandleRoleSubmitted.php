<?php

namespace App\Listeners;

use App\Events\Commands\RoleSubmitted;
use App\Services\Authorization\RoleService;

class HandleRoleSubmitted
{
    public function __construct(private RoleService $roles) {}

    public function handle(RoleSubmitted $event): void
    {
        $this->roles->save($event->accountId, $event->name, $event->permissions, $event->roleId);
    }
}
