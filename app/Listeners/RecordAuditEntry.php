<?php

namespace App\Listeners;

use App\Services\Audit\AuditLogService;

/**
 * Wildcard listener for every intent event (App\Events\Commands\*), registered in AppServiceProvider.
 * Wildcards run after the event's own listener, so a write refused with 422 is never logged.
 */
class RecordAuditEntry
{
    public function __construct(private AuditLogService $audit) {}

    /** @param  array<int, object>  $payload */
    public function handle(string $eventName, array $payload): void
    {
        foreach ($payload as $event) {
            if (is_object($event)) {
                $this->audit->recordIntent($event);
            }
        }
    }
}
