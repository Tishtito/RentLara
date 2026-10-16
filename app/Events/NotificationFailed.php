<?php

namespace App\Events;

use App\Models\NotificationDelivery;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A notification could not be delivered after all retries. */
class NotificationFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NotificationDelivery $delivery,
    ) {}
}
