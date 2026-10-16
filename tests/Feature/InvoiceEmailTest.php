<?php

namespace Tests\Feature;

use App\Events\InvoiceIssued;
use App\Events\NotificationQueued;
use App\Mail\InvoiceIssuedMail;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Services\Billing\InvoicePdf;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Billing run → InvoiceIssued → QueueInvoiceNotifications → DeliverNotification → email with PDF. */
class InvoiceEmailTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_billing_emails_the_bill_with_a_pdf_once(): void
    {
        Mail::fake();

        $this->runBilling();

        Mail::assertSent(InvoiceIssuedMail::class, 1);
        Mail::assertSent(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail) {
            return $mail->hasTo('jane@rentflow.test')
                && $mail->hasSubject('Your bill INV-2026-000001 from Kims Properties — KES 19,500.00 due 10 Nov')
                && $mail->hasReplyTo('kims@rentflow.test')
                && collect($mail->attachments())->contains(fn ($attachment) => $attachment->as === 'INV-2026-000001.pdf'
                    && $attachment->mime === 'application/pdf');
        });

        $delivery = $this->deliveries()->sole();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('jane@rentflow.test', $delivery->recipient);
        $this->assertNotNull($this->firstInvoice()->sent_at);

        // Re-running billing, or the same InvoiceIssued firing again, never emails twice.
        $this->runBilling();
        InvoiceIssued::dispatch($this->firstInvoice());

        Mail::assertSent(InvoiceIssuedMail::class, 1);
        $this->assertCount(1, $this->deliveries());
    }

    public function test_email_shows_the_reference_bill(): void
    {
        Mail::fake();
        $this->runBilling();

        $invoice = $this->asKims(fn () => Invoice::with(['items', 'tenant', 'unit.property', 'account'])->firstOrFail());
        $mail = new InvoiceIssuedMail($invoice);

        $mail->assertSeeInHtml('INV-2026-000001');
        // Same table as the admin bill preview: Item | Previous | Current | Units used | Rate | Total.
        $mail->assertSeeInOrderInHtml(['Previous reading', 'Current reading', 'Units used', 'Rate (KES)', 'Total (KES)']);
        $mail->assertSeeInOrderInHtml(['Electricity', '100', '180', '80', '30.00', '2,400.00']);
        $mail->assertSeeInOrderInHtml(["This month's charges", '18,500.00', 'Arrears brought forward', '1,000.00'], false);
        $mail->assertSeeInHtml('KES 19,500.00');
        $mail->assertSeeInHtml('10 November 2026');

        $this->assertStringStartsWith('%PDF', app(InvoicePdf::class)->render($invoice));
    }

    public function test_tenant_who_only_wants_sms_gets_no_email(): void
    {
        Mail::fake();
        $this->asKims(fn () => Tenant::query()->update(['notification_channels' => json_encode(['sms'])]));

        $this->runBilling();

        Mail::assertNothingSent();
        $this->assertCount(0, $this->deliveries());
    }

    public function test_tenant_without_an_email_gets_a_failed_delivery_with_the_reason(): void
    {
        Mail::fake();
        $this->asKims(fn () => Tenant::query()->update(['email' => null]));

        $this->runBilling();

        Mail::assertNothingSent();
        $delivery = $this->deliveries()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('Tenant has no email address.', $delivery->error);
    }

    public function test_a_failed_send_is_recorded_and_retried_by_the_command(): void
    {
        // Keep the delivery queued so the send can be driven by hand.
        Event::fake([NotificationQueued::class]);
        $this->runBilling();
        $delivery = $this->deliveries()->sole();

        $mailer = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP connection refused'));

        try {
            $this->asKims(fn () => app(NotificationSender::class)->send($delivery));
            $this->fail('The send error should be rethrown so the queue retries it.');
        } catch (RuntimeException) {
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('SMTP connection refused', $delivery->error);
        $this->assertSame(1, $delivery->attempts);

        Mail::swap($mailer);
        Mail::fake();
        $this->artisan('notifications:retry-failed')->assertSuccessful();

        Mail::assertSent(InvoiceIssuedMail::class, 1);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
    }

    private function deliveries()
    {
        return $this->asKims(fn () => NotificationDelivery::all());
    }
}
