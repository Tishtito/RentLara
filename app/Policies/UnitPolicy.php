<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

/** Units follow their property's rules (PropertyPolicy). */
class UnitPolicy
{
    public function __construct(private PropertyPolicy $properties) {}

    public function view(User $user, Unit $unit): bool
    {
        return $this->properties->view($user, $unit->property);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->properties->update($user, $unit->property);
    }
}
