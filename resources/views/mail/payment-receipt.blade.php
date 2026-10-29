@php
    $currency = $payment->account->currency;
    $balance = \App\Services\Payments\ReceiptPdf::balance($payment);
    $home = \App\Services\Payments\ReceiptPdf::home($payment);
@endphp
<x-mail::message>
# Payment received — thank you

Hello {{ $payment->tenant->first_name }},

**{{ $payment->account->name }}** has received your payment of **{{ \App\Support\Money::format($payment->amount, $currency) }}**@if ($home) for {{ $home }}@endif.

<x-mail::table>
| | |
|:--|--:|
| Receipt number | {{ $payment->receipt_number }} |
| Date paid | {{ $payment->paid_at->timezone($payment->account->timezone)->format('j M Y') }} |
| Method | {{ $payment->method->label() }} |
@if ($payment->reference)
| Reference | {{ $payment->reference }} |
@endif
@if ($payment->invoice)
| For bill | {{ $payment->invoice->invoice_number }} |
@endif
@if ($balance)
| {{ $balance[0] }} | **{{ $balance[1] }}** |
@endif
</x-mail::table>

A PDF copy of this receipt is attached.

Thanks,<br>
{{ $payment->account->name }}
</x-mail::message>
