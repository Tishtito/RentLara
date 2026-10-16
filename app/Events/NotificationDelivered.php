<?php

namespace App\Events;

use App\Models\NotificationDelivery;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The provider confirmed a notification reached the recipient (WhatsApp delivered / read). */
class NotificationDelivered implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NotificationDelivery $delivery,
    ) {}
}
