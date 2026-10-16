<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Http;

/** Meta's WhatsApp Cloud API, faked. */
trait FakesWhatsApp
{
    protected function connectWhatsApp(Account $account, array $attributes = []): WhatsAppConnection
    {
        return WhatsAppConnection::factory()->create([
            'account_id' => $account->id,
            'phone_number_id' => '106540352242922',
            'access_token' => 'EAAG-test-token',
            'app_secret' => 'test-app-secret',
            'webhook_verify_token' => 'test-verify-token',
            ...$attributes,
        ]);
    }

    /** @param  mixed  $messages  the /messages answer: Http::response() or Http::sequence() (default: accepted) */
    protected function fakeWhatsApp(mixed $messages = null): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'media-123']),
            'graph.facebook.com/*/messages' => $messages ?? $this->whatsAppAccepted(),
            'graph.facebook.com/*' => Http::response([
                'display_phone_number' => '+254 700 000001',
                'verified_name' => 'Kims Properties',
                'id' => '106540352242922',
            ]),
        ]);
    }

    protected function whatsAppAccepted(string $id = 'wamid.ABC')
    {
        return Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '254712345678', 'wa_id' => '254712345678']],
            'messages' => [['id' => $id]],
        ]);
    }

    protected function whatsAppError(int $code, string $message, int $status = 400)
    {
        return Http::response(['error' => [
            'message' => $message, 'type' => 'OAuthException', 'code' => $code,
            'error_data' => ['messaging_product' => 'whatsapp', 'details' => $message],
        ]], $status);
    }
}
