<?php

namespace App\Listeners;

use App\Events\Commands\AccountBillingSettingsSubmitted;
use App\Services\Accounts\AccountSettingsService;

class HandleAccountBillingSettingsSubmitted
{
    public function __construct(private AccountSettingsService $settings) {}

    public function handle(AccountBillingSettingsSubmitted $event): void
    {
        $this->settings->updateBilling($event->accountId, $event->billingDay, $event->paymentDueDays, $event->invoicePrefix);
    }
}
