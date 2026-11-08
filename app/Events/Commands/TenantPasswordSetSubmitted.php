<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: the landlord sets a new portal password for a tenant. Handled by HandleTenantPasswordSetSubmitted. */
class TenantPasswordSetSubmitted
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly string $password) {}
}
