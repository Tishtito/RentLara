<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lease;
use App\Models\Unit;
use App\Models\User;
use App\Services\Properties\Visibility;

/** Move-in, move-out and rent changes need "leases.manage" (and access to the property). Super admins pass via Gate::before. */
class LeasePolicy
{
    public function __construct(private Visibility $visibility) {}

    /** Move a tenant into this unit. */
    public function create(User $user, Unit $unit): bool
    {
        return $user->can(Permission::LeasesManage->value) && $this->visibility->canSeeProperty($user, $unit->property);
    }

    /** End the lease (move-out). */
    public function end(User $user, Lease $lease): bool
    {
        return $user->can(Permission::LeasesManage->value) && $this->visibility->canSeeProperty($user, $lease->unit?->property);
    }

    /** Change the rent. */
    public function update(User $user, Lease $lease): bool
    {
        return $user->can(Permission::LeasesManage->value) && $this->visibility->canSeeProperty($user, $lease->unit?->property);
    }

    /** See the lease's rent history (anyone who can see its tenant). */
    public function view(User $user, Lease $lease): bool
    {
        return ($user->can(Permission::TenantsView->value) || $user->can(Permission::LeasesManage->value))
            && $this->visibility->canSeeProperty($user, $lease->unit?->property);
    }

    /** Make the lease's due bill now ("Bill now"): same permission as running billing. */
    public function bill(User $user, Lease $lease): bool
    {
        return $user->can(Permission::BillingRun->value) && $this->visibility->canSeeProperty($user, $lease->unit?->property);
    }
}
