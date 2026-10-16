<?php

namespace App\Services\Payments;

use App\Data\RecordPaymentData;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentRecorded;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Money received against a lease. Reduces the lease's running balance; the linked
 * invoice (the bill open when the payment arrived) is refreshed by the
 * RefreshInvoicePaymentStatus listener on PaymentRecorded.
 */
class PaymentService
{
    public function __construct(private CurrentAccount $context) {}

    public function record(RecordPaymentData $data): Payment
    {
        $payment = $this->context->actingAs($data->accountId, function () use ($data) {
            $lease = Lease::query()->findOrFail($data->leaseId);

            return Payment::create([
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
        });

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
