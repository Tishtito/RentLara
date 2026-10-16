{{--
    The bill's lines and totals, laid out like the admin bill preview (BillLinesTable + BillTotals):
    Item | Previous reading | Current reading | Units used | Rate | Total. Fixed charges leave the reading
    columns empty. Shared by the bill email and the PDF, so inline styles only (email clients drop <style>).
--}}
@php
    $currency = $invoice->account->currency;
    // 100.00 → "100", 1250.50 → "1,250.5" (like formatQuantity in the frontend).
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
    $money = fn ($v) => number_format((float) $v, 2);
    $th = 'padding:8px 10px; border-bottom:1px solid #bbb; font-size:11px; font-weight:normal; color:#777; text-transform:uppercase; letter-spacing:.04em; vertical-align:bottom;';
    $td = 'padding:10px; border-bottom:1px solid #e6e6e6; white-space:nowrap;';
    $arrears = (float) $invoice->balance_brought_forward;
@endphp
<table style="width:100%; border-collapse:collapse; font-size:13px;">
    <thead>
        <tr>
            <th style="{{ $th }} text-align:left;">Item</th>
            <th style="{{ $th }} text-align:right;">Previous reading</th>
            <th style="{{ $th }} text-align:right;">Current reading</th>
            <th style="{{ $th }} text-align:right;">Units used</th>
            <th style="{{ $th }} text-align:right;">Rate ({{ $currency }})</th>
            <th style="{{ $th }} text-align:right;">Total ({{ $currency }})</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            @php($metered = $item->previous_reading !== null)
            <tr>
                <td style="{{ $td }} white-space:normal;">{{ $item->description }}</td>
                <td style="{{ $td }} text-align:right;">{{ $metered ? $qty($item->previous_reading) : '' }}</td>
                <td style="{{ $td }} text-align:right;">{{ $metered ? $qty($item->current_reading) : '' }}</td>
                <td style="{{ $td }} text-align:right;">{{ $metered ? $qty($item->quantity) : '' }}</td>
                <td style="{{ $td }} text-align:right;">{{ $metered ? $money($item->rate) : '' }}</td>
                <td style="{{ $td }} text-align:right;">{{ $money($item->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="width:60%; margin:18px 0 0 auto; border-collapse:collapse; font-size:13px;">
    <tr>
        <td style="padding:5px 10px; color:#777;">This month's charges</td>
        <td style="padding:5px 10px; text-align:right; white-space:nowrap;">{{ $money($invoice->current_charges) }}</td>
    </tr>
    @if ($arrears != 0)
        <tr>
            <td style="padding:5px 10px; color:{{ $arrears > 0 ? '#b45309' : '#1f6b4a' }};">{{ $arrears > 0 ? 'Arrears brought forward' : 'Credit brought forward' }}</td>
            <td style="padding:5px 10px; text-align:right; white-space:nowrap; color:{{ $arrears > 0 ? '#b45309' : '#1f6b4a' }};">{{ $money($invoice->balance_brought_forward) }}</td>
        </tr>
    @endif
    <tr>
        <td style="padding:10px 10px 6px; border-top:2px solid #222; font-weight:bold;">Total due</td>
        <td style="padding:10px 10px 6px; border-top:2px solid #222; text-align:right; white-space:nowrap; font-weight:bold; font-size:17px;">{{ \App\Support\Money::format($invoice->total_due, $currency) }}</td>
    </tr>
    <tr>
        <td style="padding:5px 10px; color:#777;">Paid so far</td>
        <td style="padding:5px 10px; text-align:right; white-space:nowrap; color:#777;">{{ $money($invoice->amount_paid) }}</td>
    </tr>
</table>
