<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/** The printable payment receipt, attached to the receipt email / WhatsApp and downloadable. */
class ReceiptPdf
{
    /** Relations the receipt needs. */
    public const WITH = ['tenant', 'account', 'lease.unit.property', 'invoice:id,invoice_number'];

    /** PDF bytes. Expects self::WITH loaded. */
    public function render(Payment $payment): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('pdf.receipt', ['payment' => $payment])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Payment $payment): string
    {
        return "{$payment->receipt_number}.pdf";
    }

    /**
     * The balance after the payment, for the receipt: ["Balance due", "KES 14,500.00"],
     * ["In credit", "KES 500.00"] or ["Balance", "Fully paid"]. Null on payments from before receipts.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function balance(Payment $payment): ?array
    {
        if ($payment->balance_after === null) {
            return null;
        }

        $cents = Money::toCents($payment->balance_after);
        $currency = $payment->account->currency;

        return match (true) {
            $cents > 0 => ['Balance due', Money::format($payment->balance_after, $currency)],
            $cents < 0 => ['In credit', Money::format(Money::fromCents(-$cents), $currency)],
            default => ['Balance', 'Fully paid'],
        };
    }

    /** The balance as one sentence (WhatsApp): "Your balance is now KES 14,500.00". */
    public static function balanceSentence(Payment $payment): string
    {
        [$label, $amount] = self::balance($payment) ?? [null, null];

        return match ($label) {
            'Balance due' => "Your balance is now {$amount}",
            'In credit' => "You are {$amount} in credit",
            'Balance' => 'Your account is fully paid',
            default => 'Thank you',
        };
    }

    /** "House 7-7, Kims Apartments" */
    public static function home(Payment $payment): string
    {
        $unit = $payment->lease?->unit;

        return $unit ? "House {$unit->unit_number}, {$unit->property->name}" : '';
    }
}
