<?php

namespace App\Listeners;

use App\Events\Commands\InvoiceRebillRequested;
use App\Services\Billing\InvoiceRebillService;

class HandleInvoiceRebillRequested
{
    public function __construct(private InvoiceRebillService $rebills) {}

    public function handle(InvoiceRebillRequested $event): void
    {
        $this->rebills->rebill($event->invoiceId, $event->reason, $event->requestedBy);
    }
}
