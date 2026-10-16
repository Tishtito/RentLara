<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdf;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The bill email: HTML summary + PDF copy. Sent from inside the queued
 * DeliverNotification listener, so the mailable itself is not queued.
 * Expects items, tenant, unit.property and account loaded.
 */
class InvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $account = $this->invoice->account;

        return new Envelope(
            from: new Address(config('mail.from.address'), $account->name),
            replyTo: $account->email ? [new Address($account->email, $account->name)] : [],
            subject: sprintf(
                'Your bill %s from %s — %s due %s',
                $this->invoice->invoice_number,
                $account->name,
                Money::format($this->invoice->total_due, $account->currency),
                $this->invoice->due_date->format('j M'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice-issued');
    }

    public function attachments(): array
    {
        $pdf = app(InvoicePdf::class);

        return [
            Attachment::fromData(fn () => $pdf->render($this->invoice), $pdf->filename($this->invoice))
                ->withMime('application/pdf'),
        ];
    }
}
