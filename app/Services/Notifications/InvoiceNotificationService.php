<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Events\NotificationQueued;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Services\WhatsApp\WhatsAppConnectionService;
use Illuminate\Validation\ValidationException;

/**
 * Decides who gets a new bill and on which channels, and records one
 * notification_deliveries row per channel. Sending happens in NotificationSender.
 */
class InvoiceNotificationService
{
    public const TYPE_BILL_ISSUED = 'bill_issued';

    /** The same bill sent again by hand (always a new delivery, to the tenant's current contact). */
    public const TYPE_BILL_RESENT = 'bill_resent';

    public function __construct(
        private NotificationChannels $channels,
        private WhatsAppConnectionService $whatsapp,
    ) {}

    /**
     * Queue the bill on each of the tenant's supported channels. Safe to call twice:
     * a channel that already has a delivery for this bill is left alone.
     *
     * @return list<NotificationDelivery> the newly created deliveries
     */
    public function queueFor(Invoice $invoice): array
    {
        $tenant = $invoice->tenant()->firstOrFail();
        $created = [];

        foreach ($this->channels->for($tenant) as $channel) {
            $exists = NotificationDelivery::query()
                ->where('notifiable_type', $invoice->getMorphClass())
                ->where('notifiable_id', $invoice->id)
                ->where('type', self::TYPE_BILL_ISSUED)
                ->where('channel', $channel)
                ->exists();

            if ($exists) {
                continue;
            }

            $recipient = NotificationChannels::recipient($tenant, $channel);

            $delivery = NotificationDelivery::create([
                'account_id' => $invoice->account_id,
                'tenant_id' => $tenant->id,
                'notifiable_type' => $invoice->getMorphClass(),
                'notifiable_id' => $invoice->id,
                'type' => self::TYPE_BILL_ISSUED,
                'channel' => $channel,
                'recipient' => $recipient ?? '',
                // Nothing to send to: record why instead of attempting it.
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

    /**
     * Send a bill again (landlord's "Resend"), on the given channels or the tenant's usual ones.
     *
     * @param  list<string>|null  $channels  email / whatsapp
     * @return list<NotificationDelivery>
     *
     * @throws ValidationException void bill, a channel that can't be used, or nowhere to send it
     */
    public function resend(Invoice $invoice, ?array $channels = null): array
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['invoice' => 'This bill was voided, so it can\'t be sent.']);
        }

        $tenant = $invoice->tenant()->firstOrFail();
        $wanted = $channels === null
            ? $this->channels->for($tenant)
            : array_map(fn (string $c) => NotificationChannel::from($c), array_values(array_unique($channels)));

        foreach ($wanted as $channel) {
            if ($channel === NotificationChannel::WhatsApp && ! $this->whatsapp->isActive()) {
                throw ValidationException::withMessages(['channels' => 'Connect your WhatsApp number first (Settings → WhatsApp).']);
            }
            if (! in_array($channel, NotificationChannels::SUPPORTED, true)) {
                throw ValidationException::withMessages(['channels' => "Bills can't be sent by {$channel->value} yet."]);
            }
        }

        $targets = [];
        foreach ($wanted as $channel) {
            $recipient = $channel === NotificationChannel::Email ? $tenant->email : $tenant->routeNotificationForWhatsapp();
            if (! $recipient) {
                throw ValidationException::withMessages(['channels' => $channel === NotificationChannel::Email ? 'This tenant has no email address.' : 'This tenant has no phone number.']);
            }
            $targets[] = [$channel, $recipient];
        }

        if ($targets === []) {
            throw ValidationException::withMessages(['channels' => 'Choose how to send the bill.']);
        }

        return array_map(function (array $target) use ($invoice, $tenant) {
            [$channel, $recipient] = $target;
            $delivery = NotificationDelivery::create([
                'account_id' => $invoice->account_id,
                'tenant_id' => $tenant->id,
                'notifiable_type' => $invoice->getMorphClass(),
                'notifiable_id' => $invoice->id,
                'type' => self::TYPE_BILL_RESENT,
                'channel' => $channel,
                'recipient' => $recipient,
                'status' => 'queued',
            ]);
            NotificationQueued::dispatch($delivery);

            return $delivery;
        }, $targets);
    }
}
