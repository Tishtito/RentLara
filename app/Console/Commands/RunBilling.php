<?php

namespace App\Console\Commands;

use App\Data\BillingRunData;
use App\Events\Commands\BillingRunRequested;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:run {--account= : Only this account ID} {--date= : Bill as of this date (default today)}')]
#[Description('Request a billing run for every active landlord account')]
class RunBilling extends Command
{
    public function handle(BillingService $billing): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? 'today')->startOfDay();
        $accounts = $billing->billableAccounts($this->option('account') ? (int) $this->option('account') : null);

        foreach ($accounts as $account) {
            BillingRunRequested::dispatch(new BillingRunData($account->id, $date));
        }

        $this->info("Requested billing for {$accounts->count()} account(s) as of {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
