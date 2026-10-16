<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\Visibility;

/** Prices: "rates.view" for properties the user can see, "rates.manage" to change them. */
class ChargeRatePolicy
{
    public function __construct(private Visibility $visibility) {}

    public function viewAny(User $user, Property $property): bool
    {
        return $user->can(Permission::RatesView->value) && $this->visibility->canSeeProperty($user, $property);
    }

    public function change(User $user, Property $property): bool
    {
        return $user->can(Permission::RatesManage->value) && $this->visibility->canSeeProperty($user, $property);
    }
}
