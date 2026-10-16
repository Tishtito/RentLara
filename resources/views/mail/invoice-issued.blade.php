<x-mail::message>
# Your bill for {{ $invoice->unit->unit_number }}

Hello {{ $invoice->tenant->first_name }},

Here is your bill from **{{ $invoice->account->name }}** for {{ $invoice->period_start->format('j M') }} – {{ $invoice->period_end->format('j M Y') }}.

@include('mail.partials.invoice-lines', ['invoice' => $invoice])

**Please pay {{ \App\Support\Money::format($invoice->total_due, $invoice->account->currency) }} by {{ $invoice->due_date->format('j F Y') }}.**
Quote invoice number **{{ $invoice->invoice_number }}** when you pay.

A PDF copy of this bill is attached.

Thanks,<br>
{{ $invoice->account->name }}
</x-mail::message>
