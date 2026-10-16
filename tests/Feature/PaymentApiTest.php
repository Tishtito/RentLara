<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Events\PaymentRecorded;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        $this->runBilling();
        $this->invoice = $this->firstInvoice();
    }

    public function test_payments_are_accepted_and_the_invoice_follows(): void
    {
        $this->actingAsUser('landlord@rentflow.test');

        $this->postPayment(['amount' => '5000', 'method' => 'mpesa', 'reference' => 'SJK1A2B3C4'])
            ->assertAccepted();

        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoiceNow()->status);
        $this->assertSame('5000.00', $this->invoiceNow()->amount_paid);

        $this->postPayment(['amount' => '14500', 'method' => 'cash'])->assertAccepted();

        $this->assertSame(InvoiceStatus::Paid, $this->invoiceNow()->status);

        $this->getJson("/api/admin/invoices/{$this->invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.total_due', '19500.00')
            ->assertJsonPath('data.balance_remaining', '0.00')
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.tenant.full_name', 'Jane Wanjiru')
            ->assertJsonPath('data.unit.unit_number', '7-7')
            ->assertJsonPath('data.unit.property_name', 'Kims Apartments');

        $this->getJson("/api/admin/payments?lease_id={$this->invoice->lease_id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.tenant.full_name', 'Jane Wanjiru')
            ->assertJsonPath('data.0.unit_number', '7-7')
            ->assertJsonPath('data.0.invoice_number', $this->invoice->invoice_number);
    }

    public function test_payment_recorded_event_is_dispatched(): void
    {
        Event::fake([PaymentRecorded::class]);
        $this->actingAsUser('landlord@rentflow.test');

        $this->postPayment(['amount' => '100', 'method' => 'cash'])->assertAccepted();

        Event::assertDispatched(PaymentRecorded::class, fn ($e) => $e->payment->invoice_id === $this->invoice->id
            && $e->payment->tenant_id === $this->invoice->tenant_id);
    }

    public function test_a_bill_due_today_is_not_overdue(): void
    {
        $this->actingAsUser('landlord@rentflow.test');
        Carbon::setTestNow($this->invoice->due_date->copy()->setTime(18, 0));

        $this->postPayment(['amount' => '100', 'method' => 'cash'])->assertAccepted();

        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoiceNow()->status);
    }

    public function test_another_landlords_lease_is_rejected_as_if_missing(): void
    {
        $otherLease = Lease::factory()->create();
        $this->actingAsUser('landlord@rentflow.test');

        $this->postPayment(['lease_id' => $otherLease->id, 'amount' => '100', 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lease_id');
    }

    public function test_duplicate_mpesa_reference_is_rejected(): void
    {
        $this->actingAsUser('landlord@rentflow.test');

        $this->postPayment(['amount' => '100', 'method' => 'mpesa', 'reference' => 'SJK1A2B3C4'])->assertAccepted();
        $this->postPayment(['amount' => '100', 'method' => 'mpesa', 'reference' => 'SJK1A2B3C4'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');
    }

    public function test_caretakers_only_record_payments_for_their_properties(): void
    {
        $this->actingAsUser('caretaker@rentflow.test');
        $this->postPayment(['amount' => '100', 'method' => 'cash'])->assertAccepted();

        Sanctum::actingAs(User::factory()->caretaker()->create(['account_id' => $this->kims->id]));
        $this->postPayment(['amount' => '100', 'method' => 'cash'])->assertForbidden();
    }

    private function actingAsUser(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    private function postPayment(array $overrides)
    {
        return $this->postJson('/api/admin/payments', $overrides + [
            'lease_id' => $this->invoice->lease_id,
            'paid_at' => now()->toDateTimeString(),
        ]);
    }

    private function invoiceNow(): Invoice
    {
        return $this->asKims(fn () => Invoice::findOrFail($this->invoice->id));
    }
}
