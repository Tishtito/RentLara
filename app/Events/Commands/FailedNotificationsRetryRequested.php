<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent (platform): resend every failed message (one account, or all). Handled by HandleFailedNotificationsRetryRequested. */
class FailedNotificationsRetryRequested
{
    use Dispatchable;

    public function __construct(
        public readonly ?int $accountId = null,
    ) {}
}
