<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Services\Notifications\PaymentReceiptNotificationService;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A confirmed payment → send the tenant a receipt (email + WhatsApp, PDF attached). */
class QueueReceiptNotifications implements ShouldQueue
{
    public function __construct(
        private CurrentAccount $context,
        private PaymentReceiptNotificationService $notifications,
    ) {}

    public function handle(PaymentRecorded $event): void
    {
        $this->context->actingAs($event->payment->account_id, fn () => $this->notifications->queueFor($event->payment));
    }
}
