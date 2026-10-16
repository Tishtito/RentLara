<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Mail\InvoiceIssuedMail;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Billing\CreateMoveInInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/**
 * The move-in bill (decision: deposit + rent × days to the first billing day ÷ 30), made when the lease
 * is created, and the first monthly bill after it.
 */
class MoveInBillTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Unit $unit;

    /** @var array<string, Meter> */
    private array $meters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Carbon::setTestNow('2026-10-20 09:00:00'); // move-in day
        Mail::fake();

        [$this->unit, $this->meters] = $this->asKims(function () {
            $unit = Unit::factory()->create(['property_id' => Property::firstOrFail()->id, 'unit_number' => '7-8', 'default_rent' => '15000.00']);
            $meters = [];
            foreach (['electricity' => '500', 'water' => '40'] as $code => $initial) {
                $meters[$code] = Meter::create(['unit_id' => $unit->id, 'charge_code' => $code, 'initial_reading' => $initial, 'is_active' => true]);
            }

            return [$unit, $meters];
        });
        $this->actingAsAdmin();
    }

    public function test_moving_in_on_20_oct_bills_23_000(): void
    {
        $this->moveIn('2026-10-20')->assertAccepted();

        $bill = $this->moveInBill();
        $this->assertSame(InvoiceType::MoveIn, $bill->type);
        $this->assertSame([
            ['deposit', 'Deposit (refundable)', '1.00', '15000.00', '15000.00'],
            ['rent', 'Rent 20 Oct – 4 Nov (16 of 30 days)', '16.00', '500.00', '8000.00'],
        ], $bill->items->map(fn ($i) => [$i->charge_code, $i->description, $i->quantity, $i->rate, $i->amount])->all());
        $this->assertSame(['23000.00', '0.00', '23000.00'], [$bill->current_charges, $bill->balance_brought_forward, $bill->total_due]);
        $this->assertSame(['2026-10-20', '2026-11-04', '2026-10-20', '2026-10-20'], [
            $bill->period_start->toDateString(), $bill->period_end->toDateString(), $bill->issue_date->toDateString(), $bill->due_date->toDateString(),
        ]);

        // The first monthly bill is on the billing day; the deposit is never charged again.
        $lease = $this->lease();
        $this->assertSame(['2026-11-05', '2026-11-04'], [$lease->next_billing_date->toDateString(), $lease->last_billed_on->toDateString()]);
        $this->assertNotNull($lease->deposit_invoiced_at);

        $this->getJson("/api/admin/invoices/{$bill->id}")->assertJsonPath('data.type', 'move_in')->assertJsonPath('data.total_due', '23000.00');

        // Sent like every bill.
        Mail::assertSent(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $m) => $m->hasTo('peter@example.com') && $m->invoice->is($bill));

        // Calling it again returns the same bill.
        $again = $this->asKims(fn () => app(CreateMoveInInvoice::class)($lease));
        $this->assertTrue($again->is($bill));
        $this->assertSame(1, $this->asKims(fn () => Invoice::where('lease_id', $lease->id)->count()));
    }

    public function test_moving_in_on_the_billing_day_is_a_full_month(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->moveIn('2026-10-05')->assertAccepted();

        $bill = $this->moveInBill();
        $this->assertSame('Rent 5 Oct – 4 Nov', $bill->items->firstWhere('charge_code', 'rent')->description);
        $this->assertSame('30000.00', $bill->total_due); // deposit 15,000 + a full month 15,000
        $this->assertSame('2026-11-05', $this->lease()->next_billing_date->toDateString());
    }

    public function test_the_5_nov_monthly_bill_follows_with_the_move_in_bill_as_arrears(): void
    {
        $this->moveIn('2026-10-20')->assertAccepted();

        Carbon::setTestNow('2026-11-05 06:00:00');
        $this->postJson('/api/admin/readings', ['readings' => [
            ['meter_id' => $this->meters['electricity']->id, 'reading' => '540'],
            ['meter_id' => $this->meters['water']->id, 'reading' => '43'],
        ]])->assertAccepted();
        $this->runBilling('2026-11-05');

        $monthly = $this->asKims(fn () => Invoice::with('items')->where('unit_id', $this->unit->id)->where('type', 'monthly')->sole());
        $this->assertSame([
            'rent' => '15000.00',       // 5 Nov – 4 Dec
            'electricity' => '600.00',  // 520 (move-in) → 540 = 20 × 30
            'water' => '340.00',        // 41 → 43 = 2 × 170
            'garbage' => '250.00',
        ], $monthly->items->mapWithKeys(fn ($i) => [$i->charge_code => $i->amount])->all());
        $this->assertSame('23000.00', $monthly->balance_brought_forward); // the unpaid move-in bill
        $this->assertSame('39190.00', $monthly->total_due); // 16,190 this month + 23,000
        $this->assertSame(['2026-11-05', '2026-12-04'], [$monthly->period_start->toDateString(), $monthly->period_end->toDateString()]);
    }

    private function moveIn(string $date)
    {
        return $this->postJson("/api/admin/units/{$this->unit->id}/move-in", [
            'tenant' => ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722000481', 'email' => 'peter@example.com'],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '15000', 'start_date' => $date],
            'opening_readings' => [$this->meters['electricity']->id => '520', $this->meters['water']->id => '41'],
        ]);
    }

    private function lease(): Lease
    {
        return $this->asKims(fn () => Lease::where('unit_id', $this->unit->id)->firstOrFail());
    }

    private function moveInBill(): Invoice
    {
        return $this->asKims(fn () => Invoice::with('items')->where('unit_id', $this->unit->id)->where('type', 'move_in')->sole());
    }
}
