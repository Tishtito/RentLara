<?php

namespace App\Events\Commands;

use App\Data\ConnectWhatsAppData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: connect (or update) a landlord's WhatsApp number. Handled by HandleWhatsAppConnectionSubmitted. */
class WhatsAppConnectionSubmitted
{
    use Dispatchable;

    public function __construct(public readonly ConnectWhatsAppData $data) {}
}
