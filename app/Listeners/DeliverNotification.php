<?php

namespace App\Listeners;

use App\Events\NotificationFailed;
use App\Events\NotificationQueued;
use App\Services\Notifications\NotificationSender;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/** Sends one queued notification; the queue retries failures with growing gaps. */
class DeliverNotification implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private CurrentAccount $context,
        private NotificationSender $sender,
    ) {}

    /** Seconds to wait before the 2nd and 3rd attempts; after that, notifications:retry-failed. */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NotificationQueued $event): void
    {
        $this->context->actingAs($event->delivery->account_id, fn () => $this->sender->send($event->delivery));
    }

    public function failed(NotificationQueued $event, Throwable $e): void
    {
        NotificationFailed::dispatch($event->delivery->fresh());
    }
}
