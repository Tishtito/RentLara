<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Events\NotificationQueued;
use App\Models\NotificationDelivery;
use App\Models\TenantAuthToken;
use App\Services\Tenants\TenantService;
use App\Services\WhatsApp\WhatsAppConnectionService;

/**
 * Tenant portal invitations on WhatsApp (the email is sent by SendTenantInvitation).
 * Records a notification_deliveries row; NotificationSender sends it.
 */
class TenantInvitationNotificationService
{
    public const TYPE_TENANT_INVITATION = 'tenant_invitation';

    public function __construct(
        private TenantService $tenants,
        private WhatsAppConnectionService $whatsapp,
    ) {}

    /**
     * Queue the invitation on WhatsApp when the landlord's number is connected and the tenant gets
     * WhatsApp messages. The raw token is kept (encrypted) on the delivery for the link button.
     */
    public function queueWhatsApp(TenantAuthToken $token, string $rawToken): ?NotificationDelivery
    {
        $tenant = $token->tenant()->first();

        if (! $tenant
            || ! $this->whatsapp->isActive()
            || ! in_array(NotificationChannel::WhatsApp->value, $this->tenants->notificationChannels($tenant), true)) {
            return null;
        }

        $recipient = $tenant->routeNotificationForWhatsapp();

        $delivery = NotificationDelivery::create([
            'account_id' => $token->account_id,
            'tenant_id' => $tenant->id,
            'notifiable_type' => $token->getMorphClass(),
            'notifiable_id' => $token->id,
            'type' => self::TYPE_TENANT_INVITATION,
            'channel' => NotificationChannel::WhatsApp,
            'recipient' => $recipient ?: '',
            'payload' => ['token' => $rawToken],
            'status' => $recipient ? 'queued' : 'failed',
            'error' => $recipient ? null : 'Tenant has no phone number.',
        ]);

        if ($recipient) {
            NotificationQueued::dispatch($delivery);
        }

        return $delivery;
    }
}
