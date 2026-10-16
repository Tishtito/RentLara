<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Models\Invoice;
use App\Services\Ledger\LeaseLedgerService;
use App\Support\CurrentAccount;

/** Keeps the bill's amount_paid and status in step with its payments. */
class RefreshInvoicePaymentStatus
{
    public function __construct(
        private CurrentAccount $context,
        private LeaseLedgerService $ledger,
    ) {}

    public function handle(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        if (! $payment->invoice_id) {
            return;
        }

        $this->context->actingAs($payment->account_id, function () use ($payment) {
            $this->ledger->refreshInvoiceStatus(Invoice::query()->findOrFail($payment->invoice_id));
        });
    }
}
