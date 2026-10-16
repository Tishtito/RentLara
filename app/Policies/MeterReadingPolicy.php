<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Meter;
use App\Models\User;
use App\Services\Properties\Visibility;

/** Readings: "readings.view" / "readings.record", for properties the user can see. Super admins pass via Gate::before. */
class MeterReadingPolicy
{
    public function __construct(private Visibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ReadingsView->value);
    }

    /** One meter's reading history. */
    public function view(User $user, Meter $meter): bool
    {
        return $user->can(Permission::ReadingsView->value) && $this->visibility->canSeeProperty($user, $meter->unit->property);
    }

    public function create(User $user, Meter $meter): bool
    {
        return $user->can(Permission::ReadingsRecord->value) && $this->visibility->canSeeProperty($user, $meter->unit->property);
    }
}
