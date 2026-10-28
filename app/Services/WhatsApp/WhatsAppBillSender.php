<?php

namespace App\Services\WhatsApp;

use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Services\Billing\InvoicePdf;
use App\Support\Money;

/**
 * Sends a bill on WhatsApp from the landlord's own number: the PDF as the template's header
 * document and the key figures as body parameters. Template (docs/WHATSAPP.md):
 *
 *   Hello {{1}}, your bill from {{2}} for house {{3}} ({{4}}) is ready. Total due: {{5}},
 *   payable by {{6}}. Invoice number: {{7}}. The bill is attached.
 */
class WhatsAppBillSender
{
    public function __construct(
        private WhatsAppClient $client,
        private InvoicePdf $pdf,
        private WhatsAppConnectionService $connections,
    ) {}

    /** @return string the WhatsApp message ID */
    public function send(NotificationDelivery $delivery): string
    {
        $connection = $this->connections->activeOrFail();

        $invoice = Invoice::query()
            ->with(['items', 'tenant', 'unit.property', 'account'])
            ->findOrFail($delivery->notifiable_id);

        $filename = $this->pdf->filename($invoice);
        $mediaId = $this->client->uploadMedia($connection, $this->pdf->render($invoice), $filename, 'application/pdf');

        return $this->client->sendTemplate(
            $connection,
            self::toWhatsAppNumber($delivery->recipient),
            $connection->template_name,
            $connection->template_language,
            [
                ['type' => 'header', 'parameters' => [
                    ['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename]],
                ]],
                ['type' => 'body', 'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    $this->bodyParameters($invoice),
                )],
            ],
        );
    }

    /** "+254 712 345678" → "254712345678" (WhatsApp wants digits in international format). */
    public static function toWhatsAppNumber(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }

    /** @return list<string> {{1}}…{{7}} in order */
    private function bodyParameters(Invoice $invoice): array
    {
        return [
            $invoice->tenant->first_name,
            $invoice->account->name,
            $invoice->unit->unit_number,
            $invoice->period_start->format('j M').' – '.$invoice->period_end->format('j M Y'),
            Money::format($invoice->total_due, $invoice->account->currency),
            $invoice->due_date->format('j M Y'),
            $invoice->invoice_number,
        ];
    }
}
