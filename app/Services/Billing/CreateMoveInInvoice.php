<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Events\InvoiceIssued;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\Leases\LeaseService;
use App\Services\Ledger\LeaseLedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The move-in bill, made together with the lease (MoveInService):
 *
 *   Deposit (refundable)                            15,000
 *   Rent 20 Oct – 4 Nov (16 of 30 days)              8,000    = 15,000 × 16 ÷ 30
 *   Total due on move-in                            23,000
 *
 * Only deposit and rent: garbage and other fixed charges start on the first monthly bill, metered
 * charges from the opening meter readings. Rules: BillingCalendar::moveInRent(). Safe to call twice
 * (returns the existing move-in bill).
 */
class CreateMoveInInvoice
{
    public function __construct(
        private LeaseService $leases,
        private LeaseLedgerService $ledger,
        private InvoiceNumberGenerator $numbers,
    ) {}

    public function __invoke(Lease $lease): Invoice
    {
        return DB::transaction(function () use ($lease) {
            $lease = Lease::query()->whereKey($lease->getKey())->lockForUpdate()->firstOrFail();

            $existing = Invoice::query()->where('lease_id', $lease->id)->where('type', InvoiceType::MoveIn)
                ->where('status', '!=', InvoiceStatus::Void)->first();
            if ($existing) {
                return $existing;
            }

            $account = Account::query()->findOrFail($lease->account_id);
            $rent = BillingCalendar::moveInRent(Money::toCents($lease->monthly_rent), $lease->start_date, $this->leases->billingDay($lease));
            $today = CarbonImmutable::now($account->timezone)->startOfDay();

            $lines = [];
            if (Money::toCents($lease->deposit_amount) > 0 && ! $lease->deposit_invoiced_at) {
                $lines[] = [
                    'charge_code' => 'deposit',
                    'description' => 'Deposit (refundable)',
                    'quantity' => '1.00',
                    'rate' => $lease->deposit_amount,
                    'amount' => $lease->deposit_amount,
                ];
            }
            $lines[] = [
                'charge_code' => 'rent',
                'description' => $rent->description(),
                'quantity' => $rent->fullMonth ? '1.00' : number_format($rent->days, 2, '.', ''),
                'rate' => Money::fromCents($rent->fullMonth ? $rent->monthlyRentCents : $rent->dailyRateCents()),
                'amount' => Money::fromCents($rent->amountCents),
            ];

            $currentCents = array_sum(array_map(fn ($line) => Money::toCents($line['amount']), $lines));
            $arrears = $this->ledger->balance($lease); // the opening balance for a new lease
            $moveIn = CarbonImmutable::instance($rent->periodStart);

            $invoice = Invoice::create([
                'account_id' => $lease->account_id,
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'unit_id' => $lease->unit_id,
                'type' => InvoiceType::MoveIn,
                'invoice_number' => $this->numbers->next($account, $today),
                'period_start' => $moveIn->toDateString(),
                'period_end' => $rent->periodEnd->format('Y-m-d'),
                'issue_date' => $today->toDateString(),
                // Due on move-in day (or today when the lease is entered late).
                'due_date' => $moveIn->max($today)->toDateString(),
                'current_charges' => Money::fromCents($currentCents),
                'balance_brought_forward' => $arrears,
                'total_due' => Money::fromCents($currentCents + Money::toCents($arrears)),
                'status' => InvoiceStatus::Issued,
                'billing_key' => "{$lease->id}:{$moveIn->toDateString()}",
            ]);

            foreach ($lines as $sort => $line) {
                $invoice->items()->create($line + ['sort_order' => $sort]);
            }

            $lease->forceFill([
                'deposit_invoiced_at' => $lease->deposit_invoiced_at ?? now(),
                'last_billed_on' => $rent->periodEnd->format('Y-m-d'),
                'next_billing_date' => $rent->firstBillingDate->format('Y-m-d'),
            ])->save();

            InvoiceIssued::dispatch($invoice); // emailed / WhatsApp'd like every bill (after commit)

            return $invoice;
        });
    }
}
