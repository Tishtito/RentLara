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

/** "Set up your Rentflow login": the one-time invitation link (sent by SendTenantInvitation). */
class TenantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public Account $account,
        public string $link,
        public ?string $home, // "House 7-7, Kims Apartments"
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->account->name),
            replyTo: $this->account->email ? [new Address($this->account->email, $this->account->name)] : [],
            subject: "{$this->account->name} invited you to see your bills on Rentflow",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.tenant-invitation');
    }
}
