<?php

namespace App\Listeners;

use App\Events\Commands\TenantInvitationRequested;
use App\Models\Tenant;
use App\Services\Tenants\TenantInvitationService;

/** Runs synchronously so "no email" / "already active" errors still reach the client. */
class HandleTenantInvitationRequested
{
    public function __construct(private TenantInvitationService $invitations) {}

    public function handle(TenantInvitationRequested $event): void
    {
        $this->invitations->send(Tenant::query()->findOrFail($event->tenantId), $event->requestedBy);
    }
}
