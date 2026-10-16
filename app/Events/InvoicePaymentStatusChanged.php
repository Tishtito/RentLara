<?php

namespace App\Events;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An invoice moved between issued / partially_paid / paid / overdue. */
class InvoicePaymentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoiceStatus $from,
        public readonly InvoiceStatus $to,
    ) {}
}
