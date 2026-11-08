<?php

namespace App\Mail;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/** "Reset your password": a one-time tenant portal link (sent by SendTenantPasswordReset). One email per landlord. */
class TenantPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public Account $account,
        public string $link,
        public ?string $home, // "House 7-7, Kims Apartments"
        public Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->account->name),
            replyTo: $this->account->email ? [new Address($this->account->email, $this->account->name)] : [],
            subject: "Reset your {$this->account->name} tenant portal password",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.tenant-password-reset', with: [
            'validFor' => $this->expiresAt->isAfter(now()->addHours(2))
                ? round(now()->diffInHours($this->expiresAt)).' hours'
                : round(now()->diffInMinutes($this->expiresAt)).' minutes',
        ]);
    }
}
