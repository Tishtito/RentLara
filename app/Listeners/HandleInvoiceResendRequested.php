<?php

namespace App\Listeners;

use App\Events\Commands\InvoiceResendRequested;
use App\Models\Invoice;
use App\Services\Notifications\InvoiceNotificationService;

class HandleInvoiceResendRequested
{
    public function __construct(private InvoiceNotificationService $notifications) {}

    public function handle(InvoiceResendRequested $event): void
    {
        $this->notifications->resend(Invoice::query()->findOrFail($event->invoiceId), $event->channels);
    }
}
