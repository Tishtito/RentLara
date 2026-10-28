<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationDelivery;
use App\Models\TenantAuthToken;
use App\Services\Tenants\TenantAuthTokenService;
use App\Support\TenantAppLinks;

/**
 * Sends a tenant portal invitation on WhatsApp from the landlord's own number. Template
 * (docs/WHATSAPP.md), with a "Set up my login" URL button ending in the one-time token:
 *
 *   Hello {{1}}, {{2}} has set up your Rentflow tenant account for {{3}}. Tap the button below to
 *   choose your password, then log in to see your bills, balance and payments any time.
 *   Get the app: {{4}}. The link expires in 3 days.
 *
 *   Button (Visit website, dynamic): {PORTAL_URL}/invite/{{1}}
 */
class WhatsAppInvitationSender
{
    public function __construct(
        private WhatsAppClient $client,
        private WhatsAppConnectionService $connections,
        private TenantAuthTokenService $tokens,
    ) {}

    /** @return string the WhatsApp message ID */
    public function send(NotificationDelivery $delivery): string
    {
        $connection = $this->connections->activeOrFail();

        $raw = $delivery->payload['token'] ?? '';
        $token = $raw !== '' ? $this->tokens->findValid($raw, TenantAuthToken::PURPOSE_INVITATION) : null;

        if (! $token || $token->id !== $delivery->notifiable_id) {
            throw new PermanentWhatsAppApiException('This invitation link has expired or was already used. Send a new invitation.');
        }

        $tenant = $token->tenant->load(['account', 'activeLease.unit.property']);
        $unit = $tenant->activeLease?->unit;

        return $this->client->sendTemplate(
            $connection,
            WhatsAppBillSender::toWhatsAppNumber($delivery->recipient),
            $connection->invitationTemplate(),
            $connection->template_language,
            [
                ['type' => 'body', 'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    [
                        $tenant->first_name,
                        $tenant->account->name,
                        $unit ? "House {$unit->unit_number}, {$unit->property->name}" : 'your home',
                        TenantAppLinks::summary(),
                    ],
                )],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [
                    ['type' => 'text', 'text' => $raw],
                ]],
            ],
        );
    }
}
