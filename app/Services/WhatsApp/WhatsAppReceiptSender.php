<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Services\Payments\ReceiptPdf;
use App\Support\Money;

/**
 * Sends a payment receipt on WhatsApp from the landlord's own number: the PDF as the template's
 * header document and the key figures as body parameters. Template (docs/WHATSAPP.md):
 *
 *   Hello {{1}}, {{2}} has received your payment of {{3}} for {{4}} on {{5}} (reference {{6}}).
 *   Receipt number: {{7}}. {{8}}. The receipt is attached.
 */
class WhatsAppReceiptSender
{
    public function __construct(
        private WhatsAppClient $client,
        private ReceiptPdf $pdf,
        private WhatsAppConnectionService $connections,
    ) {}

    /** @return string the WhatsApp message ID */
    public function send(NotificationDelivery $delivery): string
    {
        $connection = $this->connections->activeOrFail();

        $payment = Payment::query()->with(ReceiptPdf::WITH)->findOrFail($delivery->notifiable_id);

        $filename = $this->pdf->filename($payment);
        $mediaId = $this->client->uploadMedia($connection, $this->pdf->render($payment), $filename, 'application/pdf');

        return $this->client->sendTemplate(
            $connection,
            WhatsAppBillSender::toWhatsAppNumber($delivery->recipient),
            $connection->receiptTemplate(),
            $connection->template_language,
            [
                ['type' => 'header', 'parameters' => [
                    ['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename]],
                ]],
                ['type' => 'body', 'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    $this->bodyParameters($payment),
                )],
            ],
        );
    }

    /** @return list<string> {{1}}…{{8}} in order (WhatsApp rejects empty parameters) */
    private function bodyParameters(Payment $payment): array
    {
        return [
            $payment->tenant->first_name,
            $payment->account->name,
            Money::format($payment->amount, $payment->account->currency),
            ReceiptPdf::home($payment) ?: 'your home',
            $payment->paid_at->timezone($payment->account->timezone)->format('j M Y'),
            $payment->reference ?: $payment->method->label(),
            $payment->receipt_number,
            ReceiptPdf::balanceSentence($payment),
        ];
    }
}
