<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Models\Tenant;
use App\Services\Tenants\TenantService;
use App\Services\WhatsApp\WhatsAppConnectionService;

/** Which channels a tenant's messages (bills, receipts) go out on, and to which address. */
class NotificationChannels
{
    /** Channels with a working sender. SMS joins this list when its sender exists. */
    public const SUPPORTED = [NotificationChannel::Email, NotificationChannel::WhatsApp];

    public function __construct(
        private TenantService $tenants,
        private WhatsAppConnectionService $whatsapp,
    ) {}

    /**
     * The tenant's preferred channels that can be sent today. WhatsApp only when the landlord has
     * connected their own number; otherwise the tenant just gets the email.
     *
     * @return list<NotificationChannel>
     */
    public function for(Tenant $tenant): array
    {
        $preferred = $this->tenants->notificationChannels($tenant);

        return array_values(array_filter(
            self::SUPPORTED,
            fn (NotificationChannel $channel) => in_array($channel->value, $preferred, true)
                && ($channel !== NotificationChannel::WhatsApp || $this->whatsapp->isActive()),
        ));
    }

    /** Email address or phone number; null / empty when the tenant has none. */
    public static function recipient(Tenant $tenant, NotificationChannel $channel): ?string
    {
        return match ($channel) {
            NotificationChannel::Email => $tenant->email,
            NotificationChannel::Sms => $tenant->routeNotificationForSms(),
            NotificationChannel::WhatsApp => $tenant->routeNotificationForWhatsapp(),
        };
    }

    /** Why a delivery couldn't be attempted (no address). */
    public static function missingRecipient(NotificationChannel $channel): string
    {
        return $channel === NotificationChannel::Email ? 'Tenant has no email address.' : 'Tenant has no phone number.';
    }
}
