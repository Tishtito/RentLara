<?php

namespace App\Listeners;

use App\Events\ApiTokenIssued;
use App\Services\Audit\AuditLogService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/** Logins (cookie or app token) and logouts go in the activity log, for both portals. */
class RecordAuthEntry
{
    public function __construct(private AuditLogService $audit) {}

    public function handle(Login|Logout|ApiTokenIssued $event): void
    {
        match (true) {
            $event instanceof Login => $this->audit->recordAuth('auth.login', $event->user),
            $event instanceof Logout => $this->audit->recordAuth('auth.logout', $event->user),
            default => $this->audit->recordAuth('auth.token', $event->user),
        };
    }
}
