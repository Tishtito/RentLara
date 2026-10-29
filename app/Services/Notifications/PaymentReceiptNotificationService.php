<?php

namespace App\Services\Notifications;

use App\Events\NotificationQueued;
use App\Models\NotificationDelivery;
use App\Models\Payment;

/**
 * Sends the tenant a receipt for each confirmed payment: one notification_deliveries row per
 * channel (email / WhatsApp, same rules as bills). Sending happens in NotificationSender.
 */
class PaymentReceiptNotificationService
{
    public const TYPE_PAYMENT_RECEIPT = 'payment_receipt';

    public function __construct(private NotificationChannels $channels) {}

    /**
     * Queue the receipt on each of the tenant's channels. Safe to call twice: a channel that already
     * has a receipt delivery for this payment is left alone.
     *
     * @return list<NotificationDelivery> the newly created deliveries
     */
    public function queueFor(Payment $payment): array
    {
        if (! $payment->hasReceipt()) {
            return [];
        }

        $tenant = $payment->tenant()->firstOrFail();
        $created = [];

        foreach ($this->channels->for($tenant) as $channel) {
            $exists = NotificationDelivery::query()
                ->where('notifiable_type', $payment->getMorphClass())
                ->where('notifiable_id', $payment->id)
                ->where('type', self::TYPE_PAYMENT_RECEIPT)
                ->where('channel', $channel)
                ->exists();

            if ($exists) {
                continue;
            }

            $recipient = NotificationChannels::recipient($tenant, $channel);

            $delivery = NotificationDelivery::create([
                'account_id' => $payment->account_id,
                'tenant_id' => $tenant->id,
                'notifiable_type' => $payment->getMorphClass(),
                'notifiable_id' => $payment->id,
                'type' => self::TYPE_PAYMENT_RECEIPT,
                'channel' => $channel,
                'recipient' => $recipient ?? '',
                'status' => $recipient ? 'queued' : 'failed',
                'error' => $recipient ? null : NotificationChannels::missingRecipient($channel),
            ]);

            if ($recipient) {
                NotificationQueued::dispatch($delivery);
            }

            $created[] = $delivery;
        }

        return $created;
    }
}
