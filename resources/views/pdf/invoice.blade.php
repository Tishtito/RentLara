<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .meta td { padding: 2px 12px 2px 0; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $invoice->account->name }}</h1>
                <div class="muted">{{ $invoice->account->phone }} · {{ $invoice->account->email }}</div>
            </td>
            <td style="text-align:right;">
                <h1>BILL</h1>
                <div>{{ $invoice->invoice_number }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td class="muted">Tenant</td><td>{{ $invoice->tenant->full_name }}</td></tr>
        <tr><td class="muted">House</td><td>{{ $invoice->unit->unit_number }}, {{ $invoice->unit->property->name }}</td></tr>
        <tr><td class="muted">Period</td><td>{{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}</td></tr>
        <tr><td class="muted">Issued</td><td>{{ $invoice->issue_date->format('j M Y') }}</td></tr>
        <tr><td class="muted">Due</td><td><strong>{{ $invoice->due_date->format('j M Y') }}</strong></td></tr>
    </table>

    <br>
    @include('mail.partials.invoice-lines', ['invoice' => $invoice])
    <br>
    <p class="muted">Quote the invoice number when you pay.</p>
</body>
</html>
