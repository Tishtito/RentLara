<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

class WhatsAppConnectionApiTest extends TestCase
{
    use BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    private const CREDENTIALS = [
        'phone_number_id' => '106540352242922',
        'business_account_id' => '102290129340398',
        'access_token' => 'EAAG-real-looking-token',
        'app_secret' => 'super-secret-app-secret',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Sanctum::actingAs(User::where('email', 'landlord@rentflow.test')->firstOrFail());
    }

    public function test_landlord_connects_their_number_and_gets_webhook_details(): void
    {
        $this->getJson('/api/admin/whatsapp')->assertNotFound();
        $this->fakeWhatsApp();

        $this->putJson('/api/admin/whatsapp', self::CREDENTIALS)->assertAccepted();

        $response = $this->getJson('/api/admin/whatsapp')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.display_phone_number', '+254 700 000001')
            ->assertJsonPath('data.verified_name', 'Kims Properties')
            ->assertJsonPath('data.template_name', 'rentflow_bill_issued')
            ->assertJsonPath('data.webhook_url', url('/api/webhooks/whatsapp/kims-properties'))
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.app_secret');

        $this->assertSame(40, strlen($response->json('data.webhook_verify_token')));
        $this->assertStringNotContainsString(self::CREDENTIALS['access_token'], $response->getContent());
        $this->assertStringNotContainsString(self::CREDENTIALS['app_secret'], $response->getContent());

        // Encrypted at rest.
        $raw = DB::table('whatsapp_connections')->first();
        $this->assertStringNotContainsString(self::CREDENTIALS['access_token'], $raw->access_token);
        $this->assertSame(self::CREDENTIALS['access_token'], $this->asKims(fn () => WhatsAppConnection::first()->access_token));

        // The credentials were checked with Meta using the landlord's token.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/106540352242922?fields=display_phone_number')
            && $r->hasHeader('Authorization', 'Bearer EAAG-real-looking-token'));
    }

    public function test_credentials_meta_rejects_are_a_validation_error(): void
    {
        Http::fake(['graph.facebook.com/*' => $this->whatsAppError(190, 'Invalid OAuth access token.', 401)]);

        $this->putJson('/api/admin/whatsapp', self::CREDENTIALS)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('access_token');

        $this->assertSame(0, $this->asKims(fn () => WhatsAppConnection::count()));
    }

    public function test_updating_without_secrets_keeps_the_stored_ones(): void
    {
        $this->fakeWhatsApp();
        $this->putJson('/api/admin/whatsapp', self::CREDENTIALS)->assertAccepted();
        $token = $this->getJson('/api/admin/whatsapp')->json('data.webhook_verify_token');

        $this->putJson('/api/admin/whatsapp', [
            'phone_number_id' => self::CREDENTIALS['phone_number_id'],
            'business_account_id' => self::CREDENTIALS['business_account_id'],
            'template_name' => 'kims_bill',
        ])->assertAccepted();

        $connection = $this->asKims(fn () => WhatsAppConnection::sole());
        $this->assertSame(self::CREDENTIALS['access_token'], $connection->access_token);
        $this->assertSame('kims_bill', $connection->template_name);
        $this->assertSame($token, $connection->webhook_verify_token); // Meta's webhook keeps working
    }

    public function test_first_connection_requires_token_and_secret(): void
    {
        $this->putJson('/api/admin/whatsapp', ['phone_number_id' => '1', 'business_account_id' => '2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access_token', 'app_secret']);
    }

    public function test_caretakers_cannot_manage_whatsapp(): void
    {
        Sanctum::actingAs(User::where('email', 'caretaker@rentflow.test')->firstOrFail());

        $this->getJson('/api/admin/whatsapp')->assertForbidden();
        $this->putJson('/api/admin/whatsapp', self::CREDENTIALS)->assertForbidden();
    }

    public function test_disconnecting_stops_whatsapp_bills_but_not_email(): void
    {
        Mail::fake();
        $this->fakeWhatsApp();
        $this->putJson('/api/admin/whatsapp', self::CREDENTIALS)->assertAccepted();

        $this->deleteJson('/api/admin/whatsapp')->assertAccepted();
        $this->getJson('/api/admin/whatsapp')->assertJsonPath('data.status', 'disabled');

        $this->runBilling();

        $this->assertSame(['email'], $this->asKims(fn () => NotificationDelivery::pluck('channel')->map->value->all()));
    }
}
