<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: apply delivery statuses Meta sent to a landlord's webhook. Handled by HandleWhatsAppStatusesReceived. */
class WhatsAppStatusesReceived
{
    use Dispatchable;

    /** @param  list<array<string, mixed>>  $statuses  raw Meta status objects */
    public function __construct(
        public readonly int $accountId,
        public readonly array $statuses,
    ) {}
}
