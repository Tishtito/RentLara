<?php

namespace App\Listeners;

use App\Events\Commands\TenantPasswordResetLinkRequested;
use App\Models\Tenant;
use App\Services\Tenants\TenantPasswordResetService;

/** Runs synchronously so "no email" / "no login yet" errors still reach the client. */
class HandleTenantPasswordResetLinkRequested
{
    public function __construct(private TenantPasswordResetService $resets) {}

    public function handle(TenantPasswordResetLinkRequested $event): void
    {
        $this->resets->sendLink(Tenant::query()->findOrFail($event->tenantId), $event->requestedBy);
    }
}
