<?php

namespace App\Events;

use App\Models\NotificationDelivery;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A notification was queued for delivery on one channel. */
class NotificationQueued implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NotificationDelivery $delivery,
    ) {}
}
