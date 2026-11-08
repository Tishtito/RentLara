<?php

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A tenant's portal password changed (set by the landlord, reset from a link, or changed by the tenant). */
class TenantPasswordChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        /** "landlord", "reset" or "tenant" */
        public readonly string $via,
    ) {}
}
