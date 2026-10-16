<?php

namespace App\Listeners;

use App\Events\Commands\RoleTemplateUpdateSubmitted;
use App\Services\Authorization\RoleService;

class HandleRoleTemplateUpdateSubmitted
{
    public function __construct(private RoleService $roles) {}

    public function handle(RoleTemplateUpdateSubmitted $event): void
    {
        $this->roles->updateTemplate($event->roleId, $event->permissions);
    }
}
