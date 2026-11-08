<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Events\InvoiceIssued;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/**
 * POST leases/{id}/bill ("Bill now"): one lease's due bill without a full run. Seed: House 7-7 bills 5 Nov
 * (electricity 100→180, water 70→75 read 4 Nov).
 */
class BillLeaseNowTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Lease $lease;

    private Unit $house;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        [$this->lease, $this->house] = $this->asKims(fn () => [Lease::firstOrFail(), Unit::where('unit_number', '7-7')->firstOrFail()]);

        // No water reading this month: the 5 Nov run skips 7-7.
        $this->asKims(fn () => MeterReading::where('meter_id', Meter::where('charge_code', 'water')->value('id'))->delete());
        $this->runBilling();
        $this->assertSame(0, $this->asKims(fn () => Invoice::count()));
    }

    public function test_skipped_lease_switched_to_fixed_water_is_billed_now(): void
    {
        $this->actingAsAdmin();
        Carbon::setTestNow('2026-11-08 10:00:00');

        $this->getJson("/api/admin/tenants/{$this->lease->tenant_id}")->assertJsonPath('data.current_lease.bill_due', true);
        $this->getJson("/api/admin/properties/{$this->house->property_id}/units")
            ->assertJsonPath('data.0.current_lease.bill_due', true);

        // Still metered: the reading is missing.
        $this->postJson("/api/admin/leases/{$this->lease->id}/bill")->assertUnprocessable()
            ->assertJsonValidationErrors('lease')->assertJsonFragment(['Can\'t bill yet: No new water reading up to 2026-11-05.']);

        // Water becomes a fixed 500 from the due bill (5 Nov): no last-usage line without a closing reading.
        $this->putJson("/api/admin/units/{$this->house->id}", [
            'unit_number' => '7-7', 'default_rent' => '15000',
            'utilities' => [['charge_code' => 'water', 'billing_type' => 'fixed', 'amount' => '500']],
        ])->assertAccepted();

        Event::fake([InvoiceIssued::class]);
        $id = $this->postJson("/api/admin/leases/{$this->lease->id}/bill")->assertAccepted()
            ->assertJsonPath('message', 'Bill made.')->assertJsonStructure(['messages'])->json('invoice_id');

        $bill = $this->asKims(fn () => Invoice::with('items')->findOrFail($id));
        $this->assertSame([
            ['rent', '15000.00'],
            ['electricity', '2400.00'],
            ['water', '500.00'],
            ['garbage', '250.00'],
        ], $bill->items->sortBy('sort_order')->map(fn ($i) => [$i->charge_code, $i->amount])->values()->all());
        $this->assertSame(
            ['2026-11-05', '2026-12-04', '2026-11-08', '18150.00', '19150.00'],
            [$bill->period_start->toDateString(), $bill->period_end->toDateString(), $bill->issue_date->toDateString(), $bill->current_charges, $bill->total_due],
        );
        $this->assertNull($bill->billing_run_id);
        Event::assertDispatchedTimes(InvoiceIssued::class, 1);
        $this->assertSame(1, AuditLog::where('action', 'lease_bill.requested')->count());

        // Done: not due any more, and pressing again doesn't bill twice.
        $this->getJson("/api/admin/tenants/{$this->lease->tenant_id}")->assertJsonPath('data.current_lease.bill_due', false);
        $this->postJson("/api/admin/leases/{$this->lease->id}/bill")->assertUnprocessable()
            ->assertJsonFragment(['The next bill is on 5 Dec 2026. Nothing is due yet.']);
        $this->assertSame(1, $this->asKims(fn () => Invoice::count()));
    }

    public function test_moved_out_and_permissions(): void
    {
        // The caretaker sees the property but can't run billing.
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->postJson("/api/admin/leases/{$this->lease->id}/bill")->assertForbidden();

        Sanctum::actingAs(User::factory()->caretaker()->create(['account_id' => $this->kims->id]));
        $this->postJson("/api/admin/leases/{$this->lease->id}/bill")->assertForbidden();

        $this->actingAsAdmin();
        $this->asKims(fn () => $this->lease->forceFill(['status' => LeaseStatus::Ended, 'end_date' => '2026-11-06'])->save());
        $this->postJson("/api/admin/leases/{$this->lease->id}/bill")->assertUnprocessable()->assertJsonFragment(['This tenant has moved out.']);
        $this->assertSame(0, AuditLog::where('action', 'lease_bill.requested')->count());
    }
}
