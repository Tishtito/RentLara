<?php

namespace App\Listeners;

use App\Events\Commands\TenantPasswordResetRequested;
use App\Services\Tenants\TenantPasswordResetService;

class HandleTenantPasswordResetRequested
{
    public function __construct(private TenantPasswordResetService $resets) {}

    public function handle(TenantPasswordResetRequested $event): void
    {
        $this->resets->request($event->identifier);
    }
}
