<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Events\InvoiceIssued;
use App\Models\Invoice;
use App\Services\Ledger\LeaseLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** The paper bill for House 7-7: the 5 Nov monthly bill, produced end to end by billing:run → BillingService. */
class ReferenceBillTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    public function test_reference_bill_totals_19_500(): void
    {
        $this->seedKims();
        Event::fake([InvoiceIssued::class]);

        $this->runBilling();

        $invoice = $this->firstInvoice();

        $this->assertSame([
            'rent' => '15000.00',
            'electricity' => '2400.00',
            'water' => '850.00',
            'garbage' => '250.00',
        ], $invoice->items->mapWithKeys(fn ($item) => [$item->charge_code => $item->amount])->all());

        $electricity = $invoice->items->firstWhere('charge_code', 'electricity');
        $this->assertSame(['100.00', '180.00', '80.00', '30.00'], [
            $electricity->previous_reading, $electricity->current_reading, $electricity->quantity, $electricity->rate,
        ]);

        $this->assertSame('18500.00', $invoice->current_charges);
        $this->assertSame('1000.00', $invoice->balance_brought_forward);
        $this->assertSame('19500.00', $invoice->total_due);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-2026-000001', $invoice->invoice_number);
        $this->assertSame(InvoiceType::Monthly, $invoice->type);
        // Rent for the coming month in advance, issued on the billing day, due 5 days later.
        $this->assertSame(['2026-11-05', '2026-12-04', '2026-11-05', '2026-11-10'], [
            $invoice->period_start->toDateString(), $invoice->period_end->toDateString(),
            $invoice->issue_date->toDateString(), $invoice->due_date->toDateString(),
        ]);
        $this->assertSame('Rent 5 Nov – 4 Dec', $invoice->items->firstWhere('charge_code', 'rent')->description);
        $water = $invoice->items->firstWhere('charge_code', 'water');
        $this->assertSame(['70.00', '75.00', '5.00', '170.00'], [$water->previous_reading, $water->current_reading, $water->quantity, $water->rate]);

        $lease = $this->asKims(fn () => $invoice->lease()->first());
        $this->assertSame(['2026-12-05', '2026-12-04'], [$lease->next_billing_date->toDateString(), $lease->last_billed_on->toDateString()]);
        $this->assertSame('19500.00', $this->asKims(fn () => app(LeaseLedgerService::class)->balance($lease)));

        Event::assertDispatched(InvoiceIssued::class, fn ($e) => $e->invoice->is($invoice));

        // Running again on the same day bills nothing new.
        $this->runBilling();
        $this->assertSame(1, $this->asKims(fn () => Invoice::count()));
    }
}
