<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: stop sending bills on WhatsApp for a landlord. Handled by HandleWhatsAppDisconnectRequested. */
class WhatsAppDisconnectRequested
{
    use Dispatchable;

    public function __construct(public readonly int $accountId) {}
}
