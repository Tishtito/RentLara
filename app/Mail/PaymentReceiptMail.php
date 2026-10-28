<?php

namespace App\Mail;

use App\Models\Payment;
use App\Services\Payments\ReceiptPdf;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The payment receipt email: summary + PDF copy. Sent from inside the queued DeliverNotification
 * listener, so the mailable itself is not queued. Expects ReceiptPdf::WITH loaded.
 */
class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        $account = $this->payment->account;

        return new Envelope(
            from: new Address(config('mail.from.address'), $account->name),
            replyTo: $account->email ? [new Address($account->email, $account->name)] : [],
            subject: sprintf(
                'Receipt %s from %s — %s received',
                $this->payment->receipt_number,
                $account->name,
                Money::format($this->payment->amount, $account->currency),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-receipt');
    }

    public function attachments(): array
    {
        $pdf = app(ReceiptPdf::class);

        return [
            Attachment::fromData(fn () => $pdf->render($this->payment), $pdf->filename($this->payment))
                ->withMime('application/pdf'),
        ];
    }
}
