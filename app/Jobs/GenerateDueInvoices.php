<?php

namespace App\Jobs;

use App\Data\BillingRunData;
use App\Events\Commands\BillingRunRequested;
use App\Services\Billing\BillingService;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs every day at 06:00 Nairobi (routes/console.php) and bills every lease whose next_billing_date
 * has arrived. That's normally everyone on the account's billing day; running daily also catches up a
 * day the server missed and re-tries leases skipped for a missing reading once it's captured.
 *
 * One BillingRunRequested per active account: the same path as `billing:run`, the Billing settings
 * button and platform "Run billing now", so runs, skips, the activity log and bill messages are identical.
 */
class GenerateDueInvoices implements ShouldQueue
{
    use Queueable;

    public function handle(BillingService $billing, CurrentAccount $context): void
    {
        $accounts = $context->withoutScope(fn () => $billing->billableAccounts());

        foreach ($accounts as $account) {
            BillingRunRequested::dispatch(new BillingRunData(
                accountId: $account->id,
                runDate: CarbonImmutable::now($account->timezone)->startOfDay(),
                trigger: BillingRunData::TRIGGER_SCHEDULED,
            ));
        }
    }
}
