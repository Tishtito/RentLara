<?php

namespace App\Listeners;

use App\Events\Commands\TenantUpdateSubmitted;
use App\Models\Tenant;
use App\Services\Tenants\TenantService;

class HandleTenantUpdateSubmitted
{
    public function __construct(private TenantService $tenants) {}

    public function handle(TenantUpdateSubmitted $event): void
    {
        $this->tenants->update(Tenant::query()->findOrFail($event->tenantId), $event->attributes);
    }
}
