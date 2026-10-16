<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: void a wrong bill. Handled by HandleInvoiceVoidRequested. */
class InvoiceVoidRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $invoiceId,
        public readonly string $reason,
        public readonly ?int $voidedBy = null,
    ) {}
}
