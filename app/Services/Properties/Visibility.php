<?php

namespace App\Services\Properties;

use App\Enums\Permission;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which properties an admin login may see inside its account (the AccountScope already limits it to
 * the account): everyone with "properties.view_all" sees every property; everyone else only the
 * properties they look after (properties.caretaker_id). Super admins pass via Gate::before.
 */
class Visibility
{
    public function seesAllProperties(User $user): bool
    {
        return $user->can(Permission::PropertiesViewAll->value);
    }

    /** @return Builder<Property> */
    public function properties(User $user): Builder
    {
        return Property::query()->when(
            ! $this->seesAllProperties($user),
            fn (Builder $q) => $q->where('caretaker_id', $user->id),
        );
    }

    /** @return Builder<Unit> */
    public function units(User $user): Builder
    {
        return Unit::query()->when(
            ! $this->seesAllProperties($user),
            fn (Builder $q) => $q->whereHas('property', fn (Builder $p) => $p->where('caretaker_id', $user->id)),
        );
    }

    /** Constrain a query on property ids / a property relation to what this user may see. */
    public function scopeProperty(Builder $query, User $user, string $relation = 'property'): Builder
    {
        return $query->when(
            ! $this->seesAllProperties($user),
            fn (Builder $q) => $q->whereHas($relation, fn (Builder $p) => $p->where('caretaker_id', $user->id)),
        );
    }

    public function canSeeProperty(User $user, ?Property $property): bool
    {
        return $property !== null && ($this->seesAllProperties($user) || $property->caretaker_id === $user->id);
    }
}
