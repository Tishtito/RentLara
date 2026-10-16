<?php

namespace App\Listeners;

use App\Events\Commands\NotificationRetryRequested;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationSender;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued: a retry is one more send attempt, recorded on the same delivery row. */
class HandleNotificationRetryRequested implements ShouldQueue
{
    public function __construct(
        private CurrentAccount $context,
        private NotificationSender $sender,
    ) {}

    public function handle(NotificationRetryRequested $event): void
    {
        $this->context->actingAs($event->accountId, function () use ($event) {
            $this->sender->send(NotificationDelivery::query()->findOrFail($event->deliveryId));
        });
    }
}
