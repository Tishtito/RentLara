<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\BillingRun;
use App\Models\ChargeRate;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Services\Leases\LeaseService;
use App\Services\Ledger\LeaseLedgerService;
use App\Services\Meters\MeterReadingService;
use App\Services\Rates\ChargeRateService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds a lease's monthly bill, issued on the billing day (e.g. 5 Nov):
 *   Rent 5 Nov – 4 Dec            the coming month, in advance
 *   Rent 20 Oct – 4 Nov (16 days) only after a billing-day change: the days in between (rent_adjustment)
 *   Electricity / water           usage since the last billed reading, up to the billing day
 *   Garbage and other fixed       the coming month
 *   Arrears brought forward       the lease balance before this bill
 * Metered lines come before fixed ones; everything is snapshotted on the items.
 */
class InvoiceBuilder
{
    /** Bill line for the days between two billing days after a billing-day change. */
    public const RENT_ADJUSTMENT = 'rent_adjustment';

    public function __construct(
        private LeaseService $leases,
        private LeaseLedgerService $ledger,
        private ChargeRateService $rates,
        private MeterReadingService $readings,
        private InvoiceNumberGenerator $numbers,
    ) {}

    /** @throws MissingMeterReading before anything is written */
    public function build(Lease $lease, CarbonImmutable $issueDate, ?BillingRun $run = null): Invoice
    {
        $account = Account::query()->findOrFail($lease->account_id);
        [$start, $end] = $this->leases->nextBillingPeriod($lease);
        $lines = $this->lines($lease, $start, $end);

        return DB::transaction(function () use ($lease, $account, $issueDate, $run, $start, $end, $lines) {
            $currentCents = array_sum(array_map(fn ($line) => Money::toCents($line['amount']), $lines));
            $arrears = $this->ledger->balance($lease);

            $invoice = Invoice::create([
                'account_id' => $account->id,
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'unit_id' => $lease->unit_id,
                'billing_run_id' => $run?->id,
                'invoice_number' => $this->numbers->next($account, $issueDate),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->addDays($account->payment_due_days)->toDateString(),
                'current_charges' => Money::fromCents($currentCents),
                'balance_brought_forward' => $arrears,
                'total_due' => Money::fromCents($currentCents + Money::toCents($arrears)),
                'status' => InvoiceStatus::Issued,
                'type' => InvoiceType::Monthly,
                // One live bill per lease and period (voiding clears it, so the period can be billed again).
                'billing_key' => "{$lease->id}:{$start->toDateString()}",
            ]);

            foreach ($lines as $sort => $line) {
                $invoice->items()->create($line + ['sort_order' => $sort]);
            }

            $this->leases->advanceCycle($lease, $end);

            return $invoice;
        });
    }

    /** @return list<array<string, mixed>> */
    private function lines(Lease $lease, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $lines = [[
            'charge_code' => 'rent',
            'description' => 'Rent '.$start->format('j M').' – '.$end->format('j M'),
            'quantity' => '1.00',
            'rate' => $lease->monthly_rent,
            'amount' => $lease->monthly_rent,
        ]];

        if ($gap = $this->rentGap($lease, $start)) {
            $lines[] = $gap;
        }

        $meters = $lease->unit->activeMeters()->get()->keyBy('charge_code');
        // A unit can bill a utility by meter or as a fixed amount; forBill() handles the switch-over bill.
        ['usage' => $usage, 'fixed' => $fixed] = $this->rates->forBill($lease->unit, $start);

        foreach ($usage as $rate) {
            $lines[] = $this->meteredLine($lease, $rate, $meters->get($rate->charge_code), $start);
        }

        foreach ($fixed as $rate) {
            $lines[] = [
                'charge_code' => $rate->charge_code,
                'description' => $rate->label,
                'charge_rate_id' => $rate->id,
                'quantity' => '1.00',
                'rate' => $rate->rate,
                'amount' => $rate->rate,
            ];
        }

        return $lines;
    }

    /**
     * After a billing-day change the bill moves to the new day: the days between the last billed day and
     * this bill's period are charged here (rent × days ÷ 30), e.g. "Rent 5 Dec – 19 Dec (15 of 30 days)".
     * quantity = days, so voiding the bill can roll the lease back over the gap too (InvoiceVoidService).
     *
     * @return array<string, mixed>|null
     */
    private function rentGap(Lease $lease, CarbonImmutable $start): ?array
    {
        if (! $lease->last_billed_on) {
            return null;
        }

        $gapStart = CarbonImmutable::parse($lease->last_billed_on->toDateString())->addDay();
        if ($gapStart->gte($start)) {
            return null;
        }

        $rent = BillingCalendar::gapRent(Money::toCents($lease->monthly_rent), $gapStart, $start);

        return [
            'charge_code' => self::RENT_ADJUSTMENT,
            'description' => $rent->description(),
            'quantity' => number_format($rent->days, 2, '.', ''),
            'rate' => Money::fromCents($rent->dailyRateCents()),
            'amount' => Money::fromCents($rent->amountCents),
        ];
    }

    /**
     * Consumption between the readings MeterReadingService::pendingReading() picks (last billed /
     * move-in opening reading → newest reading up to the billing day).
     */
    private function meteredLine(Lease $lease, ChargeRate $rate, ?Meter $meter, CarbonImmutable $billingDay): array
    {
        if (! $meter) {
            throw new MissingMeterReading("Unit has no active {$rate->charge_code} meter.");
        }

        $pending = $this->readings->pendingReading($lease, $meter, $billingDay);

        if (! $pending['ready']) {
            throw new MissingMeterReading("No new {$rate->charge_code} reading up to {$billingDay->toDateString()}.");
        }

        ['previous' => $previous, 'current' => $current, 'from' => $from] = $pending;
        $quantity = Money::fromCents(Money::toCents($current->reading) - Money::toCents($from));

        return [
            'charge_code' => $rate->charge_code,
            'description' => $rate->label,
            'charge_rate_id' => $rate->id,
            'meter_id' => $meter->id,
            'previous_reading_id' => $previous?->id,
            'current_reading_id' => $current->id,
            'previous_reading' => $from,
            'current_reading' => $current->reading,
            'quantity' => $quantity,
            'rate' => $rate->rate,
            'amount' => Money::multiply($quantity, $rate->rate),
        ];
    }
}
