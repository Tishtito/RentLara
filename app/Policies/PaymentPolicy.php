<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use App\Services\Properties\Visibility;

/** Super admins pass every check via Gate::before (AppServiceProvider). */
class PaymentPolicy
{
    public function __construct(private Visibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PaymentsView->value);
    }

    /** One payment (its receipt), in a property the user can see. */
    public function view(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentsView->value) && $this->visibility->canSeeProperty($user, $payment->lease?->unit?->property);
    }

    /** Record a payment for a lease in a property the user can see. */
    public function create(User $user, Lease $lease): bool
    {
        return $user->can(Permission::PaymentsRecord->value) && $this->visibility->canSeeProperty($user, $lease->unit?->property);
    }
}
