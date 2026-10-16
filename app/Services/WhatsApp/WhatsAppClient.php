<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Thin wrapper over the WhatsApp Cloud API (Graph API) for one landlord's connection. */
class WhatsAppClient
{
    /**
     * Check credentials: the number's display form and verified business name.
     *
     * @return array{display_phone_number: ?string, verified_name: ?string}
     */
    public function phoneNumber(WhatsAppConnection $connection): array
    {
        $response = $this->request($connection)
            ->get($this->url($connection->phone_number_id), ['fields' => 'display_phone_number,verified_name']);

        $this->throwIfFailed($response);

        return [
            'display_phone_number' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
        ];
    }

    /** Upload a file (e.g. the bill PDF) and get a media ID to use in a message. Media IDs last 30 days. */
    public function uploadMedia(WhatsAppConnection $connection, string $contents, string $filename, string $mime): string
    {
        $response = $this->request($connection)
            ->attach('file', $contents, $filename, ['Content-Type' => $mime])
            ->post($this->url("{$connection->phone_number_id}/media"), [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
            ]);

        $this->throwIfFailed($response);

        return (string) $response->json('id');
    }

    /**
     * Send an approved template message.
     *
     * @param  string  $to  recipient in international format, digits only (2547…)
     * @param  list<array<string, mixed>>  $components  header / body parameters
     * @return string the WhatsApp message ID (wamid.…), used to match delivery webhooks
     */
    public function sendTemplate(WhatsAppConnection $connection, string $to, string $template, string $language, array $components = []): string
    {
        $response = $this->request($connection)->post($this->url("{$connection->phone_number_id}/messages"), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ]);

        $this->throwIfFailed($response);

        return (string) $response->json('messages.0.id');
    }

    private function request(WhatsAppConnection $connection): PendingRequest
    {
        return Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(config('whatsapp.timeout'));
    }

    private function url(string $path): string
    {
        return rtrim(config('whatsapp.base_url'), '/').'/'.config('whatsapp.api_version').'/'.$path;
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->failed()) {
            throw WhatsAppApiException::fromResponse($response);
        }
    }
}
