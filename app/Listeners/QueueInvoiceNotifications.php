<?php

namespace App\Listeners;

use App\Events\InvoiceIssued;
use App\Services\Notifications\InvoiceNotificationService;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A new bill → queue it on the tenant's channels (email now; SMS / WhatsApp later). */
class QueueInvoiceNotifications implements ShouldQueue
{
    public function __construct(
        private CurrentAccount $context,
        private InvoiceNotificationService $notifications,
    ) {}

    public function handle(InvoiceIssued $event): void
    {
        $this->context->actingAs($event->invoice->account_id, fn () => $this->notifications->queueFor($event->invoice));
    }
}
