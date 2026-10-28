<?php

namespace Tests\Feature;

use App\Data\RecordPaymentData;
use App\Enums\PaymentMethod;
use App\Mail\PaymentReceiptMail;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ReceiptPdf;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** Confirmed payment → receipt number + balance snapshot → receipt by email and WhatsApp (PDF attached). */
class PaymentReceiptTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->runBilling(); // the 19,500 reference bill
        $this->invoice = $this->firstInvoice();
        $this->actingAsAdmin();
    }

    public function test_receipt_goes_out_by_email_and_whatsapp_with_the_pdf(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();

        $this->pay(['amount' => '5000', 'method' => 'mpesa', 'reference' => 'SJK1A2B3C4'])->assertAccepted();

        $payment = $this->payments()->sole();
        $this->assertSame('RCT-2026-000001', $payment->receipt_number);
        $this->assertSame('14500.00', $payment->balance_after);

        $deliveries = $this->receiptDeliveries()->keyBy(fn ($d) => $d->channel->value);
        $this->assertSame(['email', 'whatsapp'], $deliveries->keys()->sort()->values()->all());
        $this->assertSame('sent', $deliveries['email']->status);
        $this->assertSame('sent', $deliveries['whatsapp']->status);

        Mail::assertSent(PaymentReceiptMail::class, function (PaymentReceiptMail $mail) {
            $this->assertTrue($mail->hasTo('jane@rentflow.test'));
            $this->assertSame('Receipt RCT-2026-000001 from Kims Properties — KES 5,000.00 received', $mail->envelope()->subject);
            $this->assertSame('RCT-2026-000001.pdf', $mail->attachments()[0]->as);
            $html = $mail->render();
            $this->assertStringContainsString('SJK1A2B3C4', $html);
            $this->assertStringContainsString('KES 14,500.00', $html);

            return true;
        });

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media') && $r->isMultipart());
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/messages')) {
                return false;
            }

            [$header, $body] = $r['template']['components'];

            return $r['to'] === '254712345678'
                && $r['template']['name'] === 'rentflow_payment_receipt'
                && $header['parameters'][0]['document'] === ['id' => 'media-123', 'filename' => 'RCT-2026-000001.pdf']
                && array_column($body['parameters'], 'text') === [
                    'Jane', 'Kims Properties', 'KES 5,000.00', 'House 7-7, Kims Apartments', '5 Nov 2026',
                    'SJK1A2B3C4', 'RCT-2026-000001', 'Your balance is now KES 14,500.00',
                ];
        });
    }

    public function test_numbers_follow_on_and_the_balance_can_be_paid_off_or_in_credit(): void
    {
        $this->pay(['amount' => '14500', 'method' => 'cash'])->assertAccepted();
        $this->pay(['amount' => '14000', 'method' => 'cash'])->assertAccepted();
        $this->pay(['amount' => '1000', 'method' => 'cash'])->assertAccepted();

        [$first, $second, $third] = $this->payments()->all();
        $this->assertSame(['RCT-2026-000001', 'RCT-2026-000002', 'RCT-2026-000003'], [$first->receipt_number, $second->receipt_number, $third->receipt_number]);
        $this->assertSame('5000.00', $first->balance_after);
        $this->assertSame('-9000.00', $second->balance_after);

        $this->assertSame(['In credit', 'KES 9,000.00'], ReceiptPdf::balance($second->load('account')));
        $this->assertSame('You are KES 9,000.00 in credit', ReceiptPdf::balanceSentence($second));

        // Another landlord's receipts start at 1 again (leave the last request's account context first).
        app(CurrentAccount::class)->set(null);
        $other = Lease::factory()->create();
        $theirs = app(PaymentService::class)->record(new RecordPaymentData(
            accountId: $other->account_id, leaseId: $other->id, amount: '100', method: PaymentMethod::Cash,
            paidAt: now()->toImmutable(),
        ));
        $this->assertSame('RCT-2026-000001', $theirs->receipt_number);
    }

    public function test_paid_off_balance_says_fully_paid(): void
    {
        $this->pay(['amount' => '19500', 'method' => 'cash'])->assertAccepted();

        $payment = $this->payments()->sole()->load('account');
        $this->assertSame('0.00', $payment->balance_after);
        $this->assertSame('Your account is fully paid', ReceiptPdf::balanceSentence($payment));
    }

    public function test_without_whatsapp_only_email_and_no_phone_fails_whatsapp(): void
    {
        Http::fake();
        $this->pay(['amount' => '100', 'method' => 'cash'])->assertAccepted();
        $this->assertSame(['email'], $this->receiptDeliveries()->map(fn ($d) => $d->channel->value)->all());
        Http::assertNothingSent();

        $this->connectWhatsApp($this->kims);
        $this->asKims(fn () => Tenant::query()->update(['phone' => '', 'whatsapp_phone' => null]));
        $this->pay(['amount' => '100', 'method' => 'cash'])->assertAccepted();

        $whatsapp = $this->receiptDeliveries()->firstWhere('channel.value', 'whatsapp');
        $this->assertSame('failed', $whatsapp->status);
        $this->assertSame('Tenant has no phone number.', $whatsapp->error);
    }

    public function test_admin_and_tenant_can_download_the_receipt(): void
    {
        $this->pay(['amount' => '5000', 'method' => 'mpesa', 'reference' => 'SJK1A2B3C4'])->assertAccepted();
        $payment = $this->payments()->sole();

        $this->getJson("/api/admin/payments?lease_id={$payment->lease_id}")
            ->assertOk()
            ->assertJsonPath('data.0.receipt_number', 'RCT-2026-000001')
            ->assertJsonPath('data.0.balance_after', '14500.00');

        $this->get("/api/admin/payments/{$payment->id}/receipt")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="RCT-2026-000001.pdf"');

        // A caretaker who doesn't look after Kims Apartments can't.
        Sanctum::actingAs(User::factory()->caretaker()->create(['account_id' => $this->kims->id]));
        $this->get("/api/admin/payments/{$payment->id}/receipt")->assertForbidden();

        $jane = Tenant::withoutGlobalScopes()->where('email', 'jane@rentflow.test')->firstOrFail();
        $this->actingAs($jane, 'tenant')->getJson('/api/portal/payments')
            ->assertOk()
            ->assertJsonPath('data.0.receipt_number', 'RCT-2026-000001');
        $this->actingAs($jane, 'tenant')->get("/api/portal/payments/{$payment->id}/receipt")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Another tenant of the same landlord gets a 404.
        $other = $this->asKims(fn () => Tenant::factory()->create(['account_id' => $this->kims->id]));
        $this->actingAs($other, 'tenant')->get("/api/portal/payments/{$payment->id}/receipt")->assertNotFound();
    }

    public function test_receipt_pdf_shows_the_payment(): void
    {
        $this->pay(['amount' => '5000', 'method' => 'mpesa', 'reference' => 'SJK1A2B3C4'])->assertAccepted();
        $payment = $this->asKims(fn () => Payment::with(ReceiptPdf::WITH)->firstOrFail());

        $html = view('pdf.receipt', ['payment' => $payment])->render();

        foreach (['RECEIPT', 'RCT-2026-000001', 'Jane Wanjiru', 'House 7-7, Kims Apartments', 'M-Pesa', 'SJK1A2B3C4', 'INV-2026-000001', 'KES 5,000.00', 'Balance due', 'KES 14,500.00'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringStartsWith('%PDF', app(ReceiptPdf::class)->render($payment));
    }

    private function pay(array $body)
    {
        return $this->postJson('/api/admin/payments', $body + [
            'lease_id' => $this->invoice->lease_id,
            'paid_at' => now()->toDateTimeString(),
        ]);
    }

    private function payments()
    {
        return $this->asKims(fn () => Payment::orderBy('id')->get());
    }

    private function receiptDeliveries()
    {
        return $this->asKims(fn () => NotificationDelivery::where('type', 'payment_receipt')->orderBy('id')->get());
    }
}
