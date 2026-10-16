<?php

namespace App\Listeners;

use App\Events\Commands\InvoiceVoidRequested;
use App\Services\Billing\InvoiceVoidService;

class HandleInvoiceVoidRequested
{
    public function __construct(private InvoiceVoidService $voids) {}

    public function handle(InvoiceVoidRequested $event): void
    {
        $this->voids->void($event->invoiceId, $event->reason, $event->voidedBy);
    }
}
