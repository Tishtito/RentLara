<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: change the account's billing cycle settings. Handled by HandleAccountBillingSettingsSubmitted. */
class AccountBillingSettingsSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $billingDay,
        public readonly int $paymentDueDays,
        public readonly string $invoicePrefix,
    ) {}
}
