<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\ChargeRate;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Support\CurrentAccount;
use App\Support\Money;
use Database\Seeders\KimsPropertiesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Builds House 7-7's first bill from the seeded data and checks it matches the paper bill.
 * The billing job (GenerateDueInvoices) does not exist yet, so the bill is assembled here
 * the way docs/SCHEMA.md describes; the job should end up producing the same invoice.
 */
class ReferenceBillTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 06:00:00');
        $this->seed(KimsPropertiesSeeder::class);
        $this->account = Account::where('slug', 'kims-properties')->firstOrFail();
    }

    public function test_reference_bill_totals_19_500(): void
    {
        $this->asKims(function () {
            $invoice = $this->billFirstLease();

            $lines = $invoice->items->mapWithKeys(fn ($item) => [$item->charge_code => $item->amount])->all();

            $this->assertSame([
                'rent' => '15000.00',
                'electricity' => '2400.00',
                'water' => '850.00',
                'garbage' => '250.00',
            ], $lines);

            $electricity = $invoice->items->firstWhere('charge_code', 'electricity');
            $this->assertSame(['100.00', '180.00', '80.00', '30.00'], [
                $electricity->previous_reading, $electricity->current_reading, $electricity->quantity, $electricity->rate,
            ]);

            $this->assertSame('18500.00', $invoice->current_charges);
            $this->assertSame('1000.00', $invoice->balance_brought_forward);
            $this->assertSame('19500.00', $invoice->total_due);
            $this->assertSame('2026-09-01', $invoice->period_start->toDateString());
            $this->assertSame('2026-09-29', $invoice->period_end->toDateString());
            $this->assertSame('INV-2026-000001', $invoice->invoice_number);

            $lease = $invoice->lease->fresh();
            $this->assertSame('19500.00', $lease->balance());
            $this->assertSame('2026-10-29', $lease->next_billing_date->toDateString());
        });
    }

    public function test_payments_update_invoice_status_and_lease_balance(): void
    {
        $this->asKims(function () {
            $invoice = $this->billFirstLease();

            Payment::create([
                'lease_id' => $invoice->lease_id,
                'amount' => '5000.00',
                'method' => PaymentMethod::Mpesa,
                'reference' => 'SJK1A2B3C4',
                'paid_at' => now(),
            ]);

            $invoice->refresh();
            $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
            $this->assertSame('5000.00', $invoice->amount_paid);
            $this->assertSame('14500.00', $invoice->balanceRemaining());

            Payment::create([
                'lease_id' => $invoice->lease_id,
                'amount' => '14500.00',
                'method' => PaymentMethod::Cash,
                'paid_at' => now(),
            ]);

            $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
            $this->assertSame('0.00', $invoice->lease->balance());
        });
    }

    public function test_bill_due_today_is_not_overdue(): void
    {
        $this->asKims(function () {
            $invoice = $this->billFirstLease();
            Carbon::setTestNow($invoice->due_date->copy()->setTime(18, 0));

            Payment::create([
                'lease_id' => $invoice->lease_id,
                'amount' => '100.00',
                'method' => PaymentMethod::Cash,
                'paid_at' => now(),
            ]);

            $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        });
    }

    private function asKims(\Closure $callback): void
    {
        app(CurrentAccount::class)->actingAs($this->account, $callback);
    }

    /** Assemble the next bill for the only lease, following the billing rules in docs/SCHEMA.md. */
    private function billFirstLease(): Invoice
    {
        $lease = Lease::dueForBilling()->with('unit.activeMeters', 'account')->sole();
        [$start, $end] = $lease->nextBillingPeriod();

        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'unit_id' => $lease->unit_id,
            'invoice_number' => Invoice::nextNumber($this->account),
            'period_start' => $start,
            'period_end' => $end,
            'issue_date' => today(),
            'due_date' => today()->addDays($this->account->payment_due_days),
            'balance_brought_forward' => $lease->balance(),
            'status' => InvoiceStatus::Issued,
        ]);

        $sort = 0;
        $invoice->items()->create([
            'charge_code' => 'rent', 'description' => 'Rent',
            'quantity' => 1, 'rate' => $lease->monthly_rent, 'amount' => $lease->monthly_rent,
            'sort_order' => $sort++,
        ]);

        $meters = $lease->unit->activeMeters->keyBy('charge_code');

        // Paper-bill order: metered charges before fixed ones.
        $rates = ChargeRate::allFor($lease->unit, $end)->sortBy(fn (ChargeRate $rate) => $rate->isMetered() ? 0 : 1);

        foreach ($rates as $rate) {
            if ($rate->isMetered()) {
                $meter = $meters[$rate->charge_code];
                $current = $meter->readingOnOrBefore($end);
                $previous = $meter->readingBefore($current->reading_date);
                $from = $previous?->reading ?? $meter->initial_reading;
                $quantity = Money::fromCents(Money::toCents($current->reading) - Money::toCents($from));

                $invoice->items()->create([
                    'charge_code' => $rate->charge_code, 'description' => $rate->label,
                    'charge_rate_id' => $rate->id, 'meter_id' => $meter->id,
                    'previous_reading_id' => $previous?->id, 'current_reading_id' => $current->id,
                    'previous_reading' => $from, 'current_reading' => $current->reading,
                    'quantity' => $quantity, 'rate' => $rate->rate,
                    'amount' => Money::multiply($quantity, $rate->rate),
                    'sort_order' => $sort++,
                ]);
            } else {
                $invoice->items()->create([
                    'charge_code' => $rate->charge_code, 'description' => $rate->label,
                    'charge_rate_id' => $rate->id,
                    'quantity' => 1, 'rate' => $rate->rate, 'amount' => $rate->rate,
                    'sort_order' => $sort++,
                ]);
            }
        }

        $invoice->recalculateTotals();
        $lease->advanceBillingCycle($end);

        return $invoice->load('items', 'lease');
    }
}
