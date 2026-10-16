<?php

namespace App\Services\Billing;

use App\Data\BillingRunData;
use App\Events\BillingRunCompleted;
use App\Events\InvoiceIssued;
use App\Events\LeaseBillingSkipped;
use App\Models\Account;
use App\Models\BillingRun;
use App\Models\Lease;
use App\Services\Leases\LeaseService;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Runs billing for one account: bills every lease whose cycle is due, skipping (and
 * logging) leases that can't be billed yet. Safe to re-run: a lease is only due again
 * after its cycle advances, and (lease_id, period_start) is unique as a backstop.
 */
class BillingService
{
    public function __construct(
        private CurrentAccount $context,
        private LeaseService $leases,
        private InvoiceBuilder $builder,
    ) {}

    public function run(BillingRunData $data): BillingRun
    {
        $account = Account::query()->findOrFail($data->accountId);

        return $this->context->actingAs($account, function () use ($account, $data) {
            $run = BillingRun::create([
                'account_id' => $account->id,
                'run_date' => $data->runDate->toDateString(),
                'trigger' => $data->trigger,
                'triggered_by' => $data->triggeredBy,
                'status' => 'running',
                'started_at' => now(),
            ]);

            try {
                foreach ($this->leases->dueForBilling($data->runDate) as $lease) {
                    $this->billLease($run, $lease, $data);
                }
            } catch (Throwable $e) {
                $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();

                throw $e;
            }

            $run->forceFill([
                'status' => $run->leases_skipped > 0 ? 'completed_with_errors' : 'completed',
                'finished_at' => now(),
            ])->save();

            BillingRunCompleted::dispatch($run);

            return $run;
        });
    }

    /** Active accounts the daily schedule should bill. */
    public function billableAccounts(?int $onlyAccountId = null): Collection
    {
        return Account::query()
            ->where('status', 'active')
            ->when($onlyAccountId, fn ($q) => $q->whereKey($onlyAccountId))
            ->get();
    }

    public function listRuns(int $perPage = 20): LengthAwarePaginator
    {
        return BillingRun::query()->latest('id')->paginate($perPage);
    }

    private function billLease(BillingRun $run, Lease $lease, BillingRunData $data): void
    {
        try {
            $invoice = $this->builder->build($lease, $data->runDate, $run);
        } catch (MissingMeterReading $e) {
            $this->skip($run, $lease, $e->getMessage());

            return;
        } catch (UniqueConstraintViolationException) {
            $this->skip($run, $lease, 'Already billed for this period.');

            return;
        }

        $run->increment('invoices_created');
        InvoiceIssued::dispatch($invoice);
    }

    private function skip(BillingRun $run, Lease $lease, string $reason): void
    {
        $run->errors = [...($run->errors ?? []), ['lease_id' => $lease->id, 'unit_id' => $lease->unit_id, 'reason' => $reason]];
        $run->leases_skipped++;
        $run->save();

        LeaseBillingSkipped::dispatch($run, $lease, $reason);
    }
}
