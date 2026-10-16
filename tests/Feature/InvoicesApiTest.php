<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceIssuedMail;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\Ledger\LeaseLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** The admin bills page: list + totals, PDF, resend, void. */
class InvoicesApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->runBilling();
    }

    public function test_list_with_search_filters_and_totals(): void
    {
        $this->actingAsAdmin();
        $bill = $this->firstInvoice();

        $this->getJson('/api/admin/invoices')->assertOk()
            ->assertJsonPath('data.0.invoice_number', $bill->invoice_number)
            ->assertJsonPath('data.0.tenant.full_name', 'Jane Wanjiru')
            ->assertJsonPath('data.0.unit.unit_number', '7-7')
            ->assertJsonPath('data.0.balance_remaining', '19500.00')
            ->assertJsonPath('meta.summary.count', 1)
            ->assertJsonPath('meta.summary.total_due', '19500.00')
            ->assertJsonPath('meta.summary.outstanding', '19500.00');

        foreach (['Jane Wanjiru', '7-7', $bill->invoice_number] as $term) {
            $this->getJson('/api/admin/invoices?search='.urlencode($term))->assertJsonCount(1, 'data');
        }
        $this->getJson('/api/admin/invoices?search=nobody')->assertJsonCount(0, 'data')->assertJsonPath('meta.summary.total_due', '0.00');
        $this->getJson('/api/admin/invoices?status=unpaid')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/invoices?status=paid')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/invoices?month=2026-11')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/invoices?month=2026-10')->assertJsonCount(0, 'data');
        $other = $this->asKims(fn () => Property::factory()->create(['account_id' => $this->kims->id]));
        $this->getJson("/api/admin/invoices?property_id={$other->id}")->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/invoices?status=bogus')->assertUnprocessable();

        // Paid in full → "paid".
        $this->postJson('/api/admin/payments', ['lease_id' => $bill->lease_id, 'amount' => '19500', 'method' => 'cash', 'paid_at' => '2026-11-05 05:00:00'])->assertAccepted();
        $this->getJson('/api/admin/invoices?status=paid')->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.outstanding', '0.00');
    }

    public function test_caretakers_see_only_their_properties_bills_and_cannot_manage_them(): void
    {
        $bill = $this->firstInvoice();
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->getJson('/api/admin/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/admin/invoices/{$bill->id}/resend")->assertForbidden();
        $this->postJson("/api/admin/invoices/{$bill->id}/void", ['reason' => 'x'])->assertForbidden();

        // Someone looking after no property sees nothing.
        Sanctum::actingAs(User::factory()->caretaker()->create(['account_id' => $this->kims->id]));
        $this->getJson('/api/admin/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/invoices/{$bill->id}/pdf")->assertForbidden();
    }

    public function test_pdf_download(): void
    {
        $this->actingAsAdmin();
        $bill = $this->firstInvoice();

        $response = $this->get("/api/admin/invoices/{$bill->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($bill->invoice_number, (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // Another landlord's bill doesn't exist for us.
        Sanctum::actingAs(User::factory()->create(['account_id' => Account::factory()->create()->id]));
        $this->get("/api/admin/invoices/{$bill->id}/pdf")->assertNotFound();
    }

    public function test_pdf_has_the_same_lines_as_the_preview(): void
    {
        $bill = $this->asKims(fn () => Invoice::with(['items', 'tenant', 'unit.property', 'account'])->findOrFail($this->firstInvoice()->id));
        $html = view('pdf.invoice', ['invoice' => $bill])->render();
        $text = preg_replace('/\s+/', ' ', trim(strip_tags($html)));

        $this->assertStringContainsString('Item Previous reading Current reading Units used Rate (KES) Total (KES)', $text);
        $this->assertStringContainsString('Rent 5 Nov – 4 Dec 15,000.00', $text); // fixed charge: reading cells empty
        $this->assertStringContainsString('Electricity 100 180 80 30.00 2,400.00', $text);
        $this->assertStringContainsString('Water 70 75 5 170.00 850.00', $text);
        $this->assertStringContainsString('Garbage 250.00', $text);
        $this->assertStringContainsString("This month's charges 18,500.00 Arrears brought forward 1,000.00 Total due KES 19,500.00 Paid so far 0.00", $text);
    }

    public function test_resend_by_email_and_whatsapp_and_delivery_history(): void
    {
        $this->actingAsAdmin();
        $bill = $this->firstInvoice();
        Mail::assertSent(InvoiceIssuedMail::class, 1);

        $this->postJson("/api/admin/invoices/{$bill->id}/resend", ['channels' => ['email']])->assertAccepted();
        Mail::assertSent(InvoiceIssuedMail::class, 2);
        $this->postJson("/api/admin/invoices/{$bill->id}/resend", ['channels' => ['whatsapp']])->assertUnprocessable()->assertJsonValidationErrors('channels');

        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();
        $this->postJson("/api/admin/invoices/{$bill->id}/resend", ['channels' => ['whatsapp']])->assertAccepted();

        $this->getJson("/api/admin/invoices/{$bill->id}")->assertOk()
            ->assertJsonCount(3, 'data.deliveries')
            ->assertJsonPath('data.deliveries.0.type', 'bill_resent')
            ->assertJsonPath('data.deliveries.0.channel', 'whatsapp')
            ->assertJsonPath('data.deliveries.0.status', 'sent')
            ->assertJsonPath('data.deliveries.1.channel', 'email')
            ->assertJsonPath('data.deliveries.2.type', 'bill_issued');
        $this->assertSame('Resent a bill', AuditLog::where('action', 'invoice_resend.requested')->latest('id')->value('summary'));
    }

    public function test_void_rebills_the_period_and_its_guards(): void
    {
        $owner = $this->actingAsAdmin();
        $bill = $this->firstInvoice();
        $lease = $this->asKims(fn () => Lease::findOrFail($bill->lease_id));
        $balanceBefore = $this->asKims(fn () => app(LeaseLedgerService::class)->balance($lease));
        $this->assertSame('19500.00', $balanceBefore);

        $this->postJson("/api/admin/invoices/{$bill->id}/void", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/invoices/{$bill->id}/void", ['reason' => 'Electricity reading mistyped'])->assertAccepted();

        $bill->refresh();
        $this->assertSame(InvoiceStatus::Void, $bill->status);
        $this->assertSame(['Electricity reading mistyped', $owner->id, null], [$bill->void_reason, $bill->voided_by, $bill->billing_key]);
        $this->getJson("/api/admin/invoices/{$bill->id}")->assertJsonPath('data.voided_by', 'Kim Landlord')->assertJsonPath('data.balance_remaining', '0.00');
        $this->postJson("/api/admin/invoices/{$bill->id}/resend")->assertUnprocessable();
        $this->postJson("/api/admin/invoices/{$bill->id}/void", ['reason' => 'again'])->assertUnprocessable();
        $this->assertSame('Electricity reading mistyped', AuditLog::where('action', 'invoice_void.requested')->sole()->data['reason']);

        // Arrears drop back to the opening balance and the lease is due again for the same period.
        $lease->refresh();
        $this->assertSame('1000.00', $this->asKims(fn () => app(LeaseLedgerService::class)->balance($lease)));
        $this->assertSame('2026-11-05', $lease->next_billing_date->toDateString());
        $this->assertSame('2026-11-04', $lease->last_billed_on->toDateString());

        // The next run bills it again: same 19,500.
        $this->runBilling();
        $rebill = $this->asKims(fn () => Invoice::where('lease_id', $lease->id)->where('status', '!=', 'void')->sole());
        $this->assertNotSame($bill->id, $rebill->id);
        $this->assertSame(['2026-11-05', '19500.00', '18500.00'], [$rebill->period_start->toDateString(), $rebill->total_due, $rebill->current_charges]);
        $this->assertSame(2, $this->asKims(fn () => Invoice::where('lease_id', $lease->id)->count()));

        // Once a payment is applied, the bill can't be voided.
        $this->postJson('/api/admin/payments', ['lease_id' => $lease->id, 'amount' => '500', 'method' => 'cash', 'paid_at' => '2026-11-05 05:30:00'])->assertAccepted();
        $this->postJson("/api/admin/invoices/{$rebill->id}/void", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('invoice');
    }

    public function test_only_the_latest_bill_can_be_voided(): void
    {
        $this->actingAsAdmin();
        $first = $this->firstInvoice();
        $this->asKims(fn () => Invoice::query()->whereKey($first->id)->update(['period_start' => '2026-10-05', 'period_end' => '2026-11-04', 'billing_key' => 'old']));
        $later = $this->asKims(fn () => tap($first->replicate())->forceFill(['invoice_number' => 'INV-LATER', 'period_start' => '2026-11-05', 'period_end' => '2026-12-04', 'billing_key' => 'new'])->save());

        $this->postJson("/api/admin/invoices/{$first->id}/void", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertNotNull($later);
    }
}
