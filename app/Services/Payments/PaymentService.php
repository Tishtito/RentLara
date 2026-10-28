<?php

namespace App\Services\Payments;

use App\Data\RecordPaymentData;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentRecorded;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\Ledger\LeaseLedgerService;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Money received against a lease. Reduces the lease's running balance; the linked
 * invoice (the bill open when the payment arrived) is refreshed by the
 * RefreshInvoicePaymentStatus listener on PaymentRecorded. A confirmed payment gets a receipt number
 * and a snapshot of the balance after it; QueueReceiptNotifications sends the receipt.
 */
class PaymentService
{
    public function __construct(
        private CurrentAccount $context,
        private ReceiptNumberGenerator $receipts,
        private LeaseLedgerService $ledger,
    ) {}

    public function record(RecordPaymentData $data): Payment
    {
        $payment = $this->context->actingAs($data->accountId, fn () => DB::transaction(function () use ($data) {
            $lease = Lease::query()->findOrFail($data->leaseId);

            $payment = Payment::create([
                'account_id' => $data->accountId,
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'invoice_id' => $data->invoiceId ?? $this->latestInvoiceId($lease),
                'amount' => $data->amount,
                'method' => $data->method,
                'reference' => $data->reference,
                'payer_phone' => $data->payerPhone,
                'paid_at' => $data->paidAt,
                'status' => PaymentStatus::Confirmed,
                'recorded_by' => $data->recordedBy,
                'meta' => $data->meta,
                'notes' => $data->notes,
            ]);

            if ($payment->isConfirmed()) {
                $this->issueReceipt($payment, $lease);
            }

            return $payment;
        }));

        PaymentRecorded::dispatch($payment);

        return $payment;
    }

    public function list(?int $leaseId = null, int $perPage = 20): LengthAwarePaginator
    {
        return Payment::query()
            ->with(['tenant:id,first_name,last_name', 'lease:id,unit_id', 'lease.unit:id,unit_number', 'invoice:id,invoice_number'])
            ->when($leaseId, fn ($q) => $q->where('lease_id', $leaseId))
            ->latest('paid_at')
            ->paginate($perPage);
    }

    /** Receipt number + the lease balance after this payment (counted in, since it's confirmed). */
    private function issueReceipt(Payment $payment, Lease $lease): void
    {
        $payment->forceFill([
            'receipt_number' => $this->receipts->next(Account::query()->findOrFail($payment->account_id), $payment->paid_at),
            'balance_after' => $this->ledger->balance($lease),
        ])->save();
    }

    /** The bill that's open when a payment arrives: the latest non-void one. */
    private function latestInvoiceId(Lease $lease): ?int
    {
        return Invoice::query()
            ->where('lease_id', $lease->id)
            ->where('status', '!=', InvoiceStatus::Void)
            ->orderByDesc('period_start')
            ->value('id');
    }
}
