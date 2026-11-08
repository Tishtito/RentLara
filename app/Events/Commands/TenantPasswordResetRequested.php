<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: a tenant forgot their password (email or national ID). Handled by HandleTenantPasswordResetRequested. */
class TenantPasswordResetRequested
{
    use Dispatchable;

    public function __construct(public readonly string $identifier) {}
}
