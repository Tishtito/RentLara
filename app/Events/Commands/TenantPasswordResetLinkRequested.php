<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: the landlord emails a tenant a password reset link. Handled by HandleTenantPasswordResetLinkRequested. */
class TenantPasswordResetLinkRequested
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly ?int $requestedBy = null) {}
}
