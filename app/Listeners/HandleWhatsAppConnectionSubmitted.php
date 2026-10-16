<?php

namespace App\Listeners;

use App\Events\Commands\WhatsAppConnectionSubmitted;
use App\Services\WhatsApp\WhatsAppConnectionService;

/** Sync, so a credential check that Meta rejects comes back to the landlord as a 422. */
class HandleWhatsAppConnectionSubmitted
{
    public function __construct(private WhatsAppConnectionService $connections) {}

    public function handle(WhatsAppConnectionSubmitted $event): void
    {
        $this->connections->connect($event->data);
    }
}
