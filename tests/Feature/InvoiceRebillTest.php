<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Events\InvoiceIssued;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** POST invoices/{id}/rebill: void + make again for one lease, same dates, current rent/prices/readings. */
class InvoiceRebillTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->runBilling();
    }

    public function test_rebill_after_a_rent_change(): void
    {
        $owner = $this->actingAsAdmin();
        $old = $this->firstInvoice();
        Carbon::setTestNow('2026-11-08 10:00:00'); // three days later

        $this->putJson("/api/admin/leases/{$old->lease_id}/rent", ['monthly_rent' => '16000'])->assertAccepted();

        Event::fake([InvoiceIssued::class]);
        $this->postJson("/api/admin/invoices/{$old->id}/rebill", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $response = $this->postJson("/api/admin/invoices/{$old->id}/rebill", ['reason' => 'Rent should be 16,000'])
            ->assertAccepted()->assertJsonPath('message', 'Bill made again.')->assertJsonStructure(['invoice_id', 'messages']);

        $old->refresh();
        $this->assertSame(InvoiceStatus::Void, $old->status);
        $this->assertSame(['Re-billed: Rent should be 16,000', $owner->id], [$old->void_reason, $old->voided_by]);

        $new = $this->asKims(fn () => Invoice::with('items')->findOrFail($response->json('invoice_id')));
        $this->assertSame($old->id, $new->replaces_invoice_id);
        $this->assertNotSame($old->invoice_number, $new->invoice_number);
        $this->assertSame(
            ['2026-11-05', '2026-12-04', $old->issue_date->toDateString(), $old->due_date->toDateString()],
            [$new->period_start->toDateString(), $new->period_end->toDateString(), $new->issue_date->toDateString(), $new->due_date->toDateString()],
        );
        // 16,000 + electricity 2,400 + water 850 + garbage 250 = 19,500, + 1,000 arrears.
        $this->assertSame(['19500.00', '1000.00', '20500.00'], [$new->current_charges, $new->balance_brought_forward, $new->total_due]);
        $this->assertSame('16000.00', $new->items->firstWhere('charge_code', 'rent')->amount);
        $this->assertSame(['100.00', '180.00'], [
            $new->items->firstWhere('charge_code', 'electricity')->previous_reading,
            $new->items->firstWhere('charge_code', 'electricity')->current_reading,
        ]);
        Event::assertDispatchedTimes(InvoiceIssued::class, 1);

        $this->getJson("/api/admin/invoices/{$old->id}")->assertJsonPath('data.replaced_by.id', $new->id);
        $this->getJson("/api/admin/invoices/{$new->id}")->assertJsonPath('data.replaces_invoice_number', $old->invoice_number);
        $this->assertSame(1, AuditLog::where('action', 'invoice_rebill.requested')->count());

        // The lease's schedule is where it was: next bill 5 Dec.
        $lease = $this->asKims(fn () => Lease::findOrFail($old->lease_id));
        $this->assertSame(['2026-12-05', '2026-12-04'], [$lease->next_billing_date->toDateString(), $lease->last_billed_on->toDateString()]);
    }

    public function test_guards_leave_the_bill_alone(): void
    {
        $this->actingAsAdmin();
        $bill = $this->firstInvoice();

        // A missing meter: the new bill can't be made, so nothing is voided.
        $this->asKims(fn () => Meter::where('charge_code', 'electricity')->update(['is_active' => false]));
        $this->postJson("/api/admin/invoices/{$bill->id}/rebill", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertSame(InvoiceStatus::Issued, $bill->refresh()->status);
        $this->assertSame(1, $this->asKims(fn () => Invoice::count()));
        $this->asKims(fn () => Meter::where('charge_code', 'electricity')->update(['is_active' => true]));

        // Paid (partly) → can't.
        $this->postJson('/api/admin/payments', ['lease_id' => $bill->lease_id, 'amount' => '500', 'method' => 'cash', 'paid_at' => '2026-11-05 05:30:00'])->assertAccepted();
        $this->postJson("/api/admin/invoices/{$bill->id}/rebill", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertSame(InvoiceStatus::PartiallyPaid, $bill->refresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'invoice_rebill.requested')->count());
    }

    public function test_caretaker_cannot_rebill(): void
    {
        $bill = $this->firstInvoice();
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->postJson("/api/admin/invoices/{$bill->id}/rebill", ['reason' => 'x'])->assertForbidden();
    }

    public function test_rebill_a_move_in_bill_with_a_corrected_rent(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->actingAsAdmin();
        $unit = $this->asKims(fn () => Unit::factory()->create(['property_id' => Property::firstOrFail()->id, 'unit_number' => '7-8']));
        $this->postJson("/api/admin/units/{$unit->id}/move-in", [
            'tenant' => ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722000481', 'email' => 'peter@example.com'],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '15000', 'start_date' => '2026-10-20'],
        ])->assertAccepted();
        $old = $this->asKims(fn () => Invoice::where('unit_id', $unit->id)->sole());
        $this->assertSame('23000.00', $old->total_due);

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->putJson("/api/admin/leases/{$old->lease_id}/rent", ['monthly_rent' => '12000'])->assertAccepted();
        $id = $this->postJson("/api/admin/invoices/{$old->id}/rebill", ['reason' => 'Rent typo'])->assertAccepted()->json('invoice_id');

        $new = $this->asKims(fn () => Invoice::with('items')->findOrFail($id));
        // Deposit 15,000 (unchanged) + 12,000 × 16 ÷ 30 = 6,400.
        $this->assertSame('21400.00', $new->total_due);
        $this->assertSame(['2026-10-20', '2026-10-20'], [$new->issue_date->toDateString(), $new->due_date->toDateString()]);
        $this->assertSame($old->id, $new->replaces_invoice_id);
        $this->assertSame(InvoiceStatus::Void, $old->refresh()->status);
    }
}
