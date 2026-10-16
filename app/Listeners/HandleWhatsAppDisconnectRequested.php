<?php

namespace App\Listeners;

use App\Events\Commands\WhatsAppDisconnectRequested;
use App\Services\WhatsApp\WhatsAppConnectionService;

class HandleWhatsAppDisconnectRequested
{
    public function __construct(private WhatsAppConnectionService $connections) {}

    public function handle(WhatsAppDisconnectRequested $event): void
    {
        $this->connections->disconnect($event->accountId);
    }
}
