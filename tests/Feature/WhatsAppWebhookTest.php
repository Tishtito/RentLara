<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\NotificationDelivery;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    private const URL = '/api/webhooks/whatsapp/kims-properties';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp(); // bill goes out as wamid.ABC
        $this->runBilling();
    }

    public function test_meta_verification_handshake(): void
    {
        $this->get(self::URL.'?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=1158201444')
            ->assertOk()
            ->assertContent('1158201444');

        $this->get(self::URL.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1158201444')->assertForbidden();
    }

    public function test_delivered_status_marks_the_delivery_delivered(): void
    {
        $this->postStatus(['id' => 'wamid.ABC', 'status' => 'delivered'])->assertOk();

        $delivery = $this->whatsAppDelivery();
        $this->assertSame('delivered', $delivery->status);
        $this->assertNotNull($delivery->delivered_at);

        // A late "sent" doesn't move it backwards.
        $this->postStatus(['id' => 'wamid.ABC', 'status' => 'sent'])->assertOk();
        $this->assertSame('delivered', $this->whatsAppDelivery()->status);
    }

    public function test_failed_status_records_metas_reason(): void
    {
        $this->postStatus(['id' => 'wamid.ABC', 'status' => 'failed', 'errors' => [[
            'code' => 131026, 'title' => 'Message undeliverable',
            'error_data' => ['details' => 'Receiver is incapable of receiving this message'],
        ]]])->assertOk();

        $delivery = $this->whatsAppDelivery();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('WhatsApp error 131026: Receiver is incapable of receiving this message', $delivery->error);
    }

    public function test_unsigned_or_wrongly_signed_calls_are_rejected(): void
    {
        $this->postStatus(['id' => 'wamid.ABC', 'status' => 'delivered'], secret: 'not-the-secret')->assertForbidden();
        $this->postJson(self::URL, ['entry' => []])->assertForbidden();

        $this->assertSame('sent', $this->whatsAppDelivery()->status);
    }

    public function test_one_landlords_webhook_cannot_touch_another_landlords_messages(): void
    {
        $other = Account::factory()->create(['slug' => 'other-landlord']);
        $this->connectWhatsApp($other, ['app_secret' => 'other-secret', 'webhook_verify_token' => 'other-token']);

        // Correctly signed by the other landlord's app, but naming Kims's message.
        $this->postStatus(['id' => 'wamid.ABC', 'status' => 'delivered'], secret: 'other-secret', url: '/api/webhooks/whatsapp/other-landlord')
            ->assertOk();

        $this->assertSame('sent', $this->whatsAppDelivery()->status);
    }

    private function postStatus(array $status, string $secret = 'test-app-secret', string $url = self::URL)
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '254700000001', 'phone_number_id' => '106540352242922'],
                'statuses' => [$status + ['timestamp' => '1790000000', 'recipient_id' => '254712345678']],
            ]]]]],
        ]);

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    private function whatsAppDelivery(): NotificationDelivery
    {
        return app(CurrentAccount::class)->actingAs($this->kims, fn () => NotificationDelivery::where('channel', 'whatsapp')->sole());
    }
}
