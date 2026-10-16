<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Properties\Visibility;

/**
 * "tenants.view" to look tenants up (without "properties.view_all": only tenants with a lease in the
 * properties they look after), "tenants.manage" to change them. Super admins pass via Gate::before.
 */
class TenantPolicy
{
    public function __construct(private Visibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TenantsView->value);
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TenantsView->value) && $this->inMyProperties($user, $tenant);
    }

    /** Edit contact details, (re)send the invitation. */
    public function update(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TenantsManage->value) && $this->inMyProperties($user, $tenant);
    }

    private function inMyProperties(User $user, Tenant $tenant): bool
    {
        return $this->visibility->seesAllProperties($user)
            || $tenant->leases()->whereHas('unit.property', fn ($q) => $q->where('caretaker_id', $user->id))->exists();
    }
}
