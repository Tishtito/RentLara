<?php

namespace App\Listeners;

use App\Events\Commands\TenantPasswordSetSubmitted;
use App\Models\Tenant;
use App\Services\Tenants\TenantService;

/** Runs synchronously so "no login yet" errors still reach the client. */
class HandleTenantPasswordSetSubmitted
{
    public function __construct(private TenantService $tenants) {}

    public function handle(TenantPasswordSetSubmitted $event): void
    {
        $this->tenants->setPassword(Tenant::query()->findOrFail($event->tenantId), $event->password);
    }
}
