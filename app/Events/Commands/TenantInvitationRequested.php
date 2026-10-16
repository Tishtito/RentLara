<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: (re)send a tenant's portal invitation. Handled by HandleTenantInvitationRequested. */
class TenantInvitationRequested
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly ?int $requestedBy = null) {}
}
