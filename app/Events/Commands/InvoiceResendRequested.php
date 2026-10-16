<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: send a bill to the tenant again. Handled by HandleInvoiceResendRequested. */
class InvoiceResendRequested
{
    use Dispatchable;

    /** @param  list<string>|null  $channels  email / whatsapp; null = the tenant's usual channels */
    public function __construct(
        public readonly int $invoiceId,
        public readonly ?array $channels = null,
    ) {}
}
