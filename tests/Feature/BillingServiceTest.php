<?php

namespace Tests\Feature;

use App\Events\BillingRunCompleted;
use App\Events\LeaseBillingSkipped;
use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\Meter;
use App\Services\Meters\MeterReadingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_running_twice_on_the_same_day_bills_once(): void
    {
        $this->runBilling();
        $this->runBilling();

        $this->asKims(function () {
            $this->assertSame(1, Invoice::count());
            $this->assertSame([1, 0], BillingRun::orderBy('id')->pluck('invoices_created')->all());
            $this->assertSame(['completed', 'completed'], BillingRun::orderBy('id')->pluck('status')->all());
        });
    }

    public function test_next_cycle_without_new_readings_is_skipped_and_logged(): void
    {
        Event::fake([LeaseBillingSkipped::class, BillingRunCompleted::class]);
        $this->runBilling();

        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->runBilling('2026-12-05');

        $run = $this->asKims(fn () => BillingRun::latest('id')->first());
        $this->assertSame('completed_with_errors', $run->status);
        $this->assertSame(1, $run->leases_skipped);
        $this->assertStringContainsString('No new electricity reading', $run->errors[0]['reason']);
        $this->assertSame(1, $this->asKims(fn () => Invoice::count()));

        Event::assertDispatched(LeaseBillingSkipped::class);
        Event::assertDispatched(BillingRunCompleted::class, 2);
    }

    public function test_second_bill_charges_from_the_last_billed_reading_and_carries_arrears(): void
    {
        $this->runBilling();

        $this->asKims(function () {
            $readings = app(MeterReadingService::class);
            foreach (['electricity' => '190.00', 'water' => '77.00'] as $code => $value) {
                $readings->record(Meter::where('charge_code', $code)->firstOrFail(), CarbonImmutable::parse('2026-12-04'), $value);
            }
        });

        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->runBilling('2026-12-05');

        $invoice = $this->asKims(fn () => Invoice::with('items')->latest('id')->firstOrFail());

        $this->assertSame('INV-2026-000002', $invoice->invoice_number);
        $this->assertSame(['2026-12-05', '2027-01-04'], [$invoice->period_start->toDateString(), $invoice->period_end->toDateString()]);
        $this->assertSame([
            'rent' => '15000.00',
            'electricity' => '300.00',  // 180 → 190 = 10 × 30
            'water' => '340.00',        // 75 → 77 = 2 × 170
            'garbage' => '250.00',
        ], $invoice->items->mapWithKeys(fn ($item) => [$item->charge_code => $item->amount])->all());
        $this->assertSame('15890.00', $invoice->current_charges);
        $this->assertSame('19500.00', $invoice->balance_brought_forward); // nothing paid yet
        $this->assertSame('35390.00', $invoice->total_due);
    }
}
