<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: change a tenant's contact details. Handled by HandleTenantUpdateSubmitted. */
class TenantUpdateSubmitted
{
    use Dispatchable;

    /** @param  array<string, mixed>  $attributes  validated contact fields */
    public function __construct(public readonly int $tenantId, public readonly array $attributes) {}
}
