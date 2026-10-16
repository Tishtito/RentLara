<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Events\NotificationDelivered;
use App\Events\NotificationFailed;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Builder;

/**
 * One message on one channel: "bill INV-2026-000123 by SMS to +2547…: delivered".
 * Used by the notification listeners (next round) and delivery-report webhooks.
 */
class NotificationDeliveryService
{
    /** How far along a delivery is. A webhook status only applies if it moves the delivery forward. */
    private const RANK = ['queued' => 0, 'sent' => 1, 'failed' => 2, 'delivered' => 3];

    public function failed(): Builder
    {
        return NotificationDelivery::query()->where('status', 'failed');
    }

    /** Failed deliveries that can be retried (there is an address to send to). */
    public function retryable(): Builder
    {
        return $this->failed()->where('recipient', '!=', '');
    }

    public function markSent(NotificationDelivery $delivery, string $provider, ?string $providerMessageId = null): void
    {
        $delivery->forceFill([
            'status' => 'sent',
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'attempts' => $delivery->attempts + 1,
            'sent_at' => now(),
            'error' => null,
        ])->save();
    }

    public function markFailed(NotificationDelivery $delivery, string $error): void
    {
        $delivery->forceFill([
            'status' => 'failed',
            'attempts' => $delivery->attempts + 1,
            'error' => $error,
        ])->save();
    }

    public function markDelivered(NotificationDelivery $delivery): void
    {
        $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
    }

    /**
     * Apply one delivery status from a WhatsApp webhook (current account only).
     * sent → sent, delivered / read → delivered, failed → failed with Meta's reason.
     * A status never moves a delivery backwards (a late "sent" can't undo "delivered").
     */
    public function applyWhatsAppStatus(array $status): void
    {
        $delivery = NotificationDelivery::query()
            ->where('channel', NotificationChannel::WhatsApp)
            ->where('provider_message_id', $status['id'] ?? '')
            ->first();

        if (! $delivery) {
            return;
        }

        $new = match ($status['status'] ?? null) {
            'sent' => 'sent',
            'delivered', 'read' => 'delivered',
            'failed' => 'failed',
            default => null,
        };

        if (! $new || self::RANK[$new] <= self::RANK[$delivery->status]) {
            return;
        }

        if ($new === 'delivered') {
            $this->markDelivered($delivery);
            NotificationDelivered::dispatch($delivery);
        } elseif ($new === 'failed') {
            $error = $status['errors'][0] ?? [];
            $detail = $error['error_data']['details'] ?? $error['message'] ?? $error['title'] ?? 'Delivery failed';
            $delivery->forceFill(['status' => 'failed', 'error' => 'WhatsApp error '.($error['code'] ?? '?').": {$detail}"])->save();
            NotificationFailed::dispatch($delivery);
        } else {
            $delivery->forceFill(['status' => 'sent'])->save();
        }
    }
}
