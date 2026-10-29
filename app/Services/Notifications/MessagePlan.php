<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Models\Tenant;
use App\Services\Tenants\TenantService;
use App\Services\WhatsApp\WhatsAppConnectionService;

/**
 * What a send action will deliver, per channel, for the admin screens' "sent" alert. Read-only and
 * follows the same rules as the senders, so the alert matches what is queued. Sending itself is
 * queued, so `sending: true` means "on its way", not "delivered".
 *
 * Each line: ['channel' => 'email'|'whatsapp', 'to' => ?string, 'sending' => bool, 'note' => ?string]
 */
class MessagePlan
{
    public function __construct(
        private TenantService $tenants,
        private WhatsAppConnectionService $whatsapp,
    ) {}

    /**
     * Bills and receipts: the tenant's usual channels, or the `$channels` the landlord picked.
     *
     * @param  list<string>|null  $channels
     * @return list<array{channel: string, to: ?string, sending: bool, note: ?string}>
     */
    public function forTenant(Tenant $tenant, ?array $channels = null): array
    {
        $wanted = $channels ?? $this->tenants->notificationChannels($tenant);
        $lines = [];

        foreach (NotificationChannels::SUPPORTED as $channel) {
            if (in_array($channel->value, $wanted, true)) {
                $lines[] = $this->line($tenant, $channel);
            } elseif ($channels === null) {
                $lines[] = self::skipped($channel, "The tenant doesn't get {$this->label($channel)} messages.");
            }
        }

        return $lines;
    }

    /**
     * Portal invitations: always by email (an email is required to invite), plus WhatsApp on the
     * same rules as TenantInvitationNotificationService::queueWhatsApp.
     *
     * @return list<array{channel: string, to: ?string, sending: bool, note: ?string}>
     */
    public function forInvitation(Tenant $tenant): array
    {
        $whatsapp = in_array(NotificationChannel::WhatsApp->value, $this->tenants->notificationChannels($tenant), true)
            ? $this->line($tenant, NotificationChannel::WhatsApp)
            : self::skipped(NotificationChannel::WhatsApp, "The tenant doesn't get WhatsApp messages.");

        return [$this->line($tenant, NotificationChannel::Email), $whatsapp];
    }

    /** One planned delivery (an existing delivery being resent, e.g. on the platform screen). */
    public static function sending(NotificationChannel|string $channel, ?string $to): array
    {
        return ['channel' => $channel instanceof NotificationChannel ? $channel->value : $channel, 'to' => $to, 'sending' => true, 'note' => null];
    }

    private function line(Tenant $tenant, NotificationChannel $channel): array
    {
        if ($channel === NotificationChannel::WhatsApp && ! $this->whatsapp->isActive()) {
            return self::skipped($channel, "WhatsApp isn't connected (Settings → WhatsApp).");
        }

        $to = NotificationChannels::recipient($tenant, $channel);

        return $to ? self::sending($channel, $to) : self::skipped($channel, NotificationChannels::missingRecipient($channel));
    }

    private static function skipped(NotificationChannel $channel, string $note): array
    {
        return ['channel' => $channel->value, 'to' => null, 'sending' => false, 'note' => $note];
    }

    private function label(NotificationChannel $channel): string
    {
        return $channel === NotificationChannel::WhatsApp ? 'WhatsApp' : $channel->value;
    }
}
