<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: resend one failed notification. Handled by HandleNotificationRetryRequested. */
class NotificationRetryRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $deliveryId,
    ) {}
}
