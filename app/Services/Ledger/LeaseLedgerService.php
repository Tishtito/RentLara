<?php

namespace App\Services\Ledger;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Events\InvoicePaymentStatusChanged;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Support\Money;

/**
 * Arrears are a running balance per lease (confirmed decision #3):
 *   balance = opening_balance + Σ invoice current_charges (not void) − Σ confirmed payments
 * Positive = tenant owes; negative = credit.
 */
class LeaseLedgerService
{
    /** Running balance. Excludes $exceptInvoice so a bill can compute its own arrears line. */
    public function balance(Lease $lease, ?Invoice $exceptInvoice = null): string
    {
        $charges = $lease->invoices()
            ->where('status', '!=', InvoiceStatus::Void)
            ->when($exceptInvoice, fn ($q) => $q->whereKeyNot($exceptInvoice->getKey()))
            ->sum('current_charges');

        $paid = $lease->payments()
            ->where('status', PaymentStatus::Confirmed)
            ->sum('amount');

        return Money::fromCents(
            Money::toCents($lease->opening_balance) + Money::toCents($charges) - Money::toCents($paid)
        );
    }

    /**
     * balance() for many leases at once (lists, dashboard): same formula, two grouped queries.
     *
     * @param  iterable<Lease>  $leases
     * @return array<int, string> lease id → balance
     */
    public function balances(iterable $leases): array
    {
        $leases = collect($leases)->keyBy('id');
        if ($leases->isEmpty()) {
            return [];
        }

        $charges = Invoice::query()
            ->whereIn('lease_id', $leases->keys())
            ->where('status', '!=', InvoiceStatus::Void)
            ->groupBy('lease_id')
            ->selectRaw('lease_id, SUM(current_charges) as total')
            ->pluck('total', 'lease_id');

        $paid = Payment::query()
            ->whereIn('lease_id', $leases->keys())
            ->where('status', PaymentStatus::Confirmed)
            ->groupBy('lease_id')
            ->selectRaw('lease_id, SUM(amount) as total')
            ->pluck('total', 'lease_id');

        return $leases->map(fn (Lease $lease, int $id) => Money::fromCents(
            Money::toCents($lease->opening_balance) + Money::toCents($charges[$id] ?? 0) - Money::toCents($paid[$id] ?? 0)
        ))->all();
    }

    /** Update amount_paid and status from the confirmed payments linked to this bill. */
    public function refreshInvoiceStatus(Invoice $invoice): void
    {
        if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Draft], true)) {
            return;
        }

        $from = $invoice->status;
        $paidCents = Money::toCents(
            $invoice->payments()->where('status', PaymentStatus::Confirmed)->sum('amount')
        );
        $dueCents = Money::toCents($invoice->total_due);

        $invoice->amount_paid = Money::fromCents($paidCents);
        $invoice->status = match (true) {
            $paidCents >= $dueCents => InvoiceStatus::Paid,
            $invoice->due_date->copy()->endOfDay()->isPast() => InvoiceStatus::Overdue,
            $paidCents > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };
        $invoice->save();

        if ($invoice->status !== $from) {
            InvoicePaymentStatusChanged::dispatch($invoice, $from, $invoice->status);
        }
    }

    /** What is still owed on one bill (never negative). */
    public function balanceRemaining(Invoice $invoice): string
    {
        return Money::fromCents(max(0, Money::toCents($invoice->total_due) - Money::toCents($invoice->amount_paid)));
    }
}
