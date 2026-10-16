<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\Visibility;

/**
 * "properties.view" to see properties (all with "properties.view_all", else the ones they look after),
 * "properties.manage" to add and edit them. Super admins pass every check via Gate::before.
 */
class PropertyPolicy
{
    public function __construct(private Visibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PropertiesView->value);
    }

    public function view(User $user, Property $property): bool
    {
        return $user->can(Permission::PropertiesView->value) && $this->visibility->canSeeProperty($user, $property);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PropertiesManage->value);
    }

    /** Edit the property, add/edit its units and meters. */
    public function update(User $user, Property $property): bool
    {
        return $user->can(Permission::PropertiesManage->value) && $this->visibility->canSeeProperty($user, $property);
    }
}
