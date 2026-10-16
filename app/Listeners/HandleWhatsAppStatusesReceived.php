<?php

namespace App\Listeners;

use App\Events\Commands\WhatsAppStatusesReceived;
use App\Services\Notifications\NotificationDeliveryService;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued: Meta only needs a quick 200 from the webhook; the statuses are applied afterwards. */
class HandleWhatsAppStatusesReceived implements ShouldQueue
{
    public function __construct(
        private CurrentAccount $context,
        private NotificationDeliveryService $deliveries,
    ) {}

    public function handle(WhatsAppStatusesReceived $event): void
    {
        // Scoped to the webhook's landlord: a status can only ever match that landlord's deliveries.
        $this->context->actingAs($event->accountId, function () use ($event) {
            foreach ($event->statuses as $status) {
                $this->deliveries->applyWhatsAppStatus($status);
            }
        });
    }
}
