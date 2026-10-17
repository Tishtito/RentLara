<?php

namespace Tests\Feature;

use App\Events\NotificationQueued;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Services\Notifications\NotificationSender;
use App\Services\WhatsApp\WhatsAppApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** Billing run → bill sent on WhatsApp (template + PDF) from the landlord's own number, alongside email. */
class WhatsAppBillTest extends TestCase
{
    use BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
    }

    public function test_bill_goes_out_on_whatsapp_and_email(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();

        $this->runBilling();

        $deliveries = $this->deliveries()->keyBy(fn ($d) => $d->channel->value);
        $this->assertSame(['email', 'whatsapp'], $deliveries->keys()->sort()->values()->all());
        $this->assertSame('sent', $deliveries['email']->status);
        $this->assertSame('sent', $deliveries['whatsapp']->status);
        $this->assertSame('wamid.ABC', $deliveries['whatsapp']->provider_message_id);
        $this->assertSame('whatsapp', $deliveries['whatsapp']->provider);

        // The PDF is uploaded to Meta first…
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v23.0/106540352242922/media')
            && $r->isMultipart()
            && $r->hasHeader('Authorization', 'Bearer EAAG-test-token'));

        // …then the approved template is sent with the PDF as header and the bill as parameters.
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/v23.0/106540352242922/messages')) {
                return false;
            }

            [$header, $body] = $r['template']['components'];

            return $r['to'] === '254712345678'
                && $r['type'] === 'template'
                && $r['template']['name'] === 'rentflow_bill_issued'
                && $r['template']['language'] === ['code' => 'en_US']
                && $header['parameters'][0]['document'] === ['id' => 'media-123', 'filename' => 'INV-2026-000001.pdf']
                && array_column($body['parameters'], 'text') === [
                    'Jane', 'Kims Properties', '7-7', '5 Nov – 4 Dec 2026', 'KES 19,500.00', '10 Nov 2026', 'INV-2026-000001',
                ];
        });
    }

    public function test_landlord_without_whatsapp_only_emails(): void
    {
        Http::fake();

        $this->runBilling();

        $this->assertSame(['email'], $this->deliveries()->map(fn ($d) => $d->channel->value)->all());
        Http::assertNothingSent();
    }

    public function test_tenant_without_a_phone_gets_a_failed_whatsapp_delivery(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();
        $this->asKims(fn () => Tenant::query()->update(['phone' => '', 'whatsapp_phone' => null]));

        $this->runBilling();

        $whatsapp = $this->deliveries()->firstWhere('channel.value', 'whatsapp');
        $this->assertSame('failed', $whatsapp->status);
        $this->assertSame('Tenant has no phone number.', $whatsapp->error);
    }

    public function test_number_not_on_whatsapp_fails_without_retrying(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp($this->whatsAppError(131026, 'Message undeliverable'));

        $this->runBilling(); // would throw (sync queue) if the error were treated as retryable

        $whatsapp = $this->deliveries()->firstWhere('channel.value', 'whatsapp');
        $this->assertSame('failed', $whatsapp->status);
        $this->assertStringContainsString('131026', $whatsapp->error);
        $this->assertSame(1, $whatsapp->attempts);
        $this->assertSame('sent', $this->deliveries()->firstWhere('channel.value', 'email')->status);
    }

    public function test_rate_limit_is_retried_and_retry_failed_sends_it(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp(Http::sequence()
            ->push(['error' => ['message' => 'Rate limit hit', 'code' => 130429]], 429)
            ->push(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.RETRY']]]));

        Event::fake([NotificationQueued::class]); // drive the send by hand
        $this->runBilling();
        $whatsapp = $this->deliveries()->firstWhere('channel.value', 'whatsapp');

        try {
            $this->asKims(fn () => app(NotificationSender::class)->send($whatsapp));
            $this->fail('A rate limit must be rethrown so the queue retries it.');
        } catch (WhatsAppApiException $e) {
            $this->assertTrue($e->retryable());
        }
        $this->assertSame('failed', $whatsapp->fresh()->status);

        $this->artisan('notifications:retry-failed')->assertSuccessful();

        $this->assertSame('sent', $whatsapp->fresh()->status);
        $this->assertSame('wamid.RETRY', $whatsapp->fresh()->provider_message_id);
    }

    private function deliveries()
    {
        return $this->asKims(fn () => NotificationDelivery::orderBy('id')->get());
    }
}
