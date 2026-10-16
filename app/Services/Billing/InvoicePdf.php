<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

/** The printable bill, attached to the invoice email. */
class InvoicePdf
{
    /** PDF bytes. Expects items, tenant, unit.property and account loaded (see InvoiceIssuedMail). */
    public function render(Invoice $invoice): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true) // embed only the glyphs used: ~25 KB instead of ~850 KB
            ->loadView('pdf.invoice', ['invoice' => $invoice])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Invoice $invoice): string
    {
        return "{$invoice->invoice_number}.pdf";
    }
}
