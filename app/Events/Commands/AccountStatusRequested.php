<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent (platform): suspend, close or reactivate a landlord account. Handled by HandleAccountStatusRequested. */
class AccountStatusRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {}
}
