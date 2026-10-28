@php
    $currency = $payment->account->currency;
    $balance = \App\Services\Payments\ReceiptPdf::balance($payment);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $payment->receipt_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .meta td { padding: 4px 16px 4px 0; }
        .amount { margin: 24px 0; padding: 16px; border: 1px solid #ddd; }
        .amount .value { font-size: 24px; font-weight: bold; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $payment->account->name }}</h1>
                <div class="muted">{{ $payment->account->phone }} · {{ $payment->account->email }}</div>
            </td>
            <td style="text-align:right;">
                <h1>RECEIPT</h1>
                <div>{{ $payment->receipt_number }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td class="muted">Received from</td><td>{{ $payment->tenant->full_name }}</td></tr>
        @if ($home = \App\Services\Payments\ReceiptPdf::home($payment))
            <tr><td class="muted">House</td><td>{{ $home }}</td></tr>
        @endif
        <tr><td class="muted">Date paid</td><td>{{ $payment->paid_at->timezone($payment->account->timezone)->format('j M Y') }}</td></tr>
        <tr><td class="muted">Method</td><td>{{ $payment->method->label() }}</td></tr>
        @if ($payment->reference)
            <tr><td class="muted">Reference</td><td>{{ $payment->reference }}</td></tr>
        @endif
        @if ($payment->invoice)
            <tr><td class="muted">For bill</td><td>{{ $payment->invoice->invoice_number }}</td></tr>
        @endif
    </table>

    <div class="amount">
        <div class="muted">Amount received</div>
        <div class="value">{{ \App\Support\Money::format($payment->amount, $currency) }}</div>
        @if ($balance)
            <div style="margin-top:8px;">{{ $balance[0] }}: <strong>{{ $balance[1] }}</strong></div>
        @endif
    </div>

    <p class="muted">Thank you for your payment. Keep this receipt for your records.</p>
</body>
</html>
