<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Events\NotificationFailed;
use App\Events\NotificationSent;
use App\Mail\InvoiceIssuedMail;
use App\Mail\PaymentReceiptMail;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Services\Payments\ReceiptPdf;
use App\Services\WhatsApp\WhatsAppBillSender;
use App\Services\WhatsApp\WhatsAppInvitationSender;
use App\Services\WhatsApp\WhatsAppReceiptSender;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Throwable;

/**
 * Sends one notification_deliveries row on its channel and records the outcome.
 * Failures are recorded and rethrown so the queue can retry them, except permanent ones
 * (PermanentDeliveryFailure: not on WhatsApp, template missing…), which no retry would fix.
 */
class NotificationSender
{
    public function __construct(
        private NotificationDeliveryService $deliveries,
        private WhatsAppBillSender $whatsapp,
        private WhatsAppInvitationSender $whatsappInvitation,
        private WhatsAppReceiptSender $whatsappReceipt,
    ) {}

    public function send(NotificationDelivery $delivery): void
    {
        if (in_array($delivery->status, ['sent', 'delivered'], true)) {
            return;
        }

        try {
            [$provider, $messageId] = match ($delivery->channel) {
                NotificationChannel::Email => [$this->sendEmail($delivery), null],
                NotificationChannel::WhatsApp => ['whatsapp', $this->sendWhatsApp($delivery)],
                default => throw new LogicException("Sending by {$delivery->channel->value} is not supported yet."),
            };
        } catch (Throwable $e) {
            $this->deliveries->markFailed($delivery, $e->getMessage());

            if ($e instanceof PermanentDeliveryFailure) {
                NotificationFailed::dispatch($delivery);

                return;
            }

            throw $e;
        }

        $this->deliveries->markSent($delivery, $provider, $messageId);
        $this->markNotifiableSent($delivery);

        NotificationSent::dispatch($delivery);
    }

    /** @return string the mailer used, recorded as the provider */
    private function sendEmail(NotificationDelivery $delivery): string
    {
        Mail::to($delivery->recipient)->send($this->mailFor($delivery));

        return config('mail.default');
    }

    /** @return string the WhatsApp message ID, matched later by the delivery webhook */
    private function sendWhatsApp(NotificationDelivery $delivery): string
    {
        return match ($delivery->type) {
            InvoiceNotificationService::TYPE_BILL_ISSUED, InvoiceNotificationService::TYPE_BILL_RESENT => $this->whatsapp->send($delivery),
            TenantInvitationNotificationService::TYPE_TENANT_INVITATION => $this->whatsappInvitation->send($delivery),
            PaymentReceiptNotificationService::TYPE_PAYMENT_RECEIPT => $this->whatsappReceipt->send($delivery),
            default => throw new LogicException("No WhatsApp message for notification type {$delivery->type}."),
        };
    }

    private function mailFor(NotificationDelivery $delivery): Mailable
    {
        return match ($delivery->type) {
            InvoiceNotificationService::TYPE_BILL_ISSUED, InvoiceNotificationService::TYPE_BILL_RESENT => new InvoiceIssuedMail(
                Invoice::query()
                    ->with(['items', 'tenant', 'unit.property', 'account'])
                    ->findOrFail($delivery->notifiable_id),
            ),
            PaymentReceiptNotificationService::TYPE_PAYMENT_RECEIPT => new PaymentReceiptMail(
                Payment::query()->with(ReceiptPdf::WITH)->findOrFail($delivery->notifiable_id),
            ),
            default => throw new LogicException("No email for notification type {$delivery->type}."),
        };
    }

    /** invoices.sent_at = first successful delivery on any channel. */
    private function markNotifiableSent(NotificationDelivery $delivery): void
    {
        if ($delivery->notifiable_type !== (new Invoice)->getMorphClass()) {
            return;
        }

        Invoice::query()
            ->whereKey($delivery->notifiable_id)
            ->whereNull('sent_at')
            ->update(['sent_at' => now()]);
    }
}
