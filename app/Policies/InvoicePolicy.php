<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Properties\Visibility;

/** Bills of the properties the user can see, with "invoices.view". Super admins pass via Gate::before. */
class InvoicePolicy
{
    public function __construct(private Visibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $user->can(Permission::InvoicesView->value);
    }

    /** Resend or void. */
    public function manage(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesManage->value) && $this->visibility->canSeeProperty($user, $invoice->unit?->property);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesView->value) && $this->visibility->canSeeProperty($user, $invoice->unit?->property);
    }
}
