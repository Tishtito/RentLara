<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "Set up your login" (team invitation) or "Reset your password" for the admin portal; both link to /set-password. */
class AdminPasswordLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $link,
        public bool $invitation,
        public ?string $accountName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->invitation
            ? ($this->accountName ? "{$this->accountName} added you to Rentflow" : 'Set up your Rentflow login')
            : 'Reset your Rentflow password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.admin-password-link');
    }
}
