<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: void a bill and make it again for that lease only. Handled by HandleInvoiceRebillRequested. */
class InvoiceRebillRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $invoiceId,
        public readonly string $reason,
        public readonly ?int $requestedBy = null,
    ) {}
}
