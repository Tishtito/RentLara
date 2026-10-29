<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ledger\LeaseLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** The tenant portal after login: home, bills (+ PDF) and payments, only ever the tenant's own. */
class TenantPortalApiTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    private Tenant $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        $this->runBilling();
        $this->jane = Tenant::withoutGlobalScopes()->where('email', 'jane@rentflow.test')->firstOrFail();
    }

    public function test_home_shows_the_balance_and_the_latest_bill_with_its_lines(): void
    {
        $this->pay('5000.00', 'QK12ABC');

        $this->actingAs($this->jane, 'tenant')->getJson('/api/portal/home')
            ->assertOk()
            ->assertJsonCount(1, 'homes')
            ->assertJsonPath('homes.0.unit_number', '7-7')
            ->assertJsonPath('homes.0.property_name', 'Kims Apartments')
            ->assertJsonPath('homes.0.balance', '14500.00')
            ->assertJsonPath('homes.0.latest_invoice.total_due', '19500.00')
            ->assertJsonPath('homes.0.latest_invoice.balance_remaining', '14500.00')
            ->assertJsonCount(4, 'homes.0.latest_invoice.items')
            ->assertJsonPath('recent_payments.0.amount', '5000.00')
            ->assertJsonPath('recent_payments.0.reference', 'QK12ABC')
            ->assertJsonMissingPath('recent_payments.0.notes');
    }

    public function test_bills_list_and_detail_are_the_tenants_own(): void
    {
        $bill = $this->firstInvoice();

        $this->actingAs($this->jane, 'tenant')->getJson('/api/portal/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'INV-2026-000001')
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/portal/invoices/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.total_due', '19500.00')
            ->assertJsonPath('data.unit.unit_number', '7-7')
            ->assertJsonCount(4, 'data.items')
            ->assertJsonMissingPath('data.deliveries');

        $this->get("/api/portal/invoices/{$bill->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_another_tenants_bill_is_not_found(): void
    {
        $bill = $this->firstInvoice();
        $neighbour = $this->asKims(fn () => Tenant::factory()->create(['account_id' => $this->kims->id]));
        $stranger = Tenant::factory()->create(['account_id' => Account::factory()->create()->id]);

        foreach ([$neighbour, $stranger] as $tenant) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($tenant, 'tenant')->getJson("/api/portal/invoices/{$bill->id}")->assertNotFound();
            $this->get("/api/portal/invoices/{$bill->id}/pdf")->assertNotFound();
            $this->getJson('/api/portal/invoices')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/portal/home')->assertOk()->assertJsonCount(0, 'homes');
        }
    }

    public function test_void_bills_and_unconfirmed_payments_are_hidden(): void
    {
        $bill = $this->firstInvoice();
        $this->pay('100.00', 'PENDING1', PaymentStatus::Pending);
        $this->asKims(fn () => $bill->forceFill(['status' => InvoiceStatus::Void, 'billing_key' => null])->save());

        $this->actingAs($this->jane, 'tenant')->getJson('/api/portal/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/portal/invoices/{$bill->id}")->assertNotFound();
        $this->getJson('/api/portal/payments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/portal/home')->assertOk()->assertJsonPath('homes.0.latest_invoice', null);
    }

    public function test_payments_list_newest_first_without_landlord_notes(): void
    {
        $this->pay('1000.00', 'FIRST', paidAt: '2026-11-06 10:00:00');
        $this->pay('2000.00', 'SECOND', paidAt: '2026-11-07 10:00:00');

        $this->actingAs($this->jane, 'tenant')->getJson('/api/portal/payments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', 'SECOND')
            ->assertJsonPath('data.0.invoice_number', 'INV-2026-000001')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.payer_phone');
    }

    public function test_a_moved_out_tenant_still_sees_what_they_owe(): void
    {
        $lease = $this->asKims(fn () => Lease::where('tenant_id', $this->jane->id)->firstOrFail());
        $this->asKims(fn () => $lease->forceFill(['status' => 'ended'])->save());

        $this->actingAs($this->jane, 'tenant')->getJson('/api/portal/home')
            ->assertOk()
            ->assertJsonPath('homes.0.lease_status', 'ended')
            ->assertJsonPath('homes.0.balance', $this->asKims(fn () => app(LeaseLedgerService::class)->balance($lease)));
    }

    public function test_the_app_uses_a_token_and_admins_are_refused(): void
    {
        $token = $this->jane->createToken('phone', ['portal'])->plainTextToken;
        $this->getJson('/api/portal/home', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonCount(1, 'homes');

        $this->app['auth']->forgetGuards();
        $admin = User::factory()->superAdmin()->create();
        $adminToken = $admin->createToken('admin', ['admin'])->plainTextToken;
        $this->getJson('/api/portal/invoices', ['Authorization' => "Bearer {$adminToken}"])->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/portal/home')->assertUnauthorized();
    }

    private function pay(string $amount, string $reference, PaymentStatus $status = PaymentStatus::Confirmed, string $paidAt = '2026-11-06 09:00:00'): Payment
    {
        return $this->asKims(function () use ($amount, $reference, $status, $paidAt) {
            $bill = Invoice::where('tenant_id', $this->jane->id)->firstOrFail();

            $payment = Payment::create([
                'lease_id' => $bill->lease_id,
                'tenant_id' => $this->jane->id,
                'invoice_id' => $bill->id,
                'amount' => $amount,
                'method' => 'mpesa',
                'reference' => $reference,
                'payer_phone' => '+254700000000',
                'paid_at' => $paidAt,
                'status' => $status,
                'notes' => 'internal note',
            ]);
            // What PaymentRecorded → RefreshInvoicePaymentStatus does after a recorded payment.
            app(LeaseLedgerService::class)->refreshInvoiceStatus($bill);

            return $payment;
        });
    }
}
