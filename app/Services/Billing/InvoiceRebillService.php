<?php

namespace App\Services\Billing;

use App\Enums\InvoiceType;
use App\Enums\LeaseStatus;
use App\Events\InvoiceIssued;
use App\Models\Invoice;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-bill one tenant: void a wrong bill and make it again for that lease only, in one step, with today's
 * rent, prices and readings. The new bill keeps the old one's issue and due dates (it's a correction, not a
 * new month), gets a new number, points at the old bill (replaces_invoice_id) and is sent to the tenant.
 * Same rules as voiding (InvoiceVoidService): only the lease's latest bill, nothing paid on it. If the new
 * bill can't be made (e.g. a reading is missing) nothing changes.
 */
class InvoiceRebillService
{
    public function __construct(
        private InvoiceVoidService $voids,
        private InvoiceBuilder $builder,
        private CreateMoveInInvoice $moveInInvoice,
    ) {}

    /**
     * @return Invoice the new bill
     *
     * @throws ValidationException can't be voided, the lease has ended, or the new bill can't be made
     */
    public function rebill(int $invoiceId, string $reason, ?int $requestedBy): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $reason, $requestedBy) {
            $old = Invoice::query()->with('lease')->findOrFail($invoiceId);

            if ($old->type === InvoiceType::Monthly && ! in_array($old->lease->status->value, LeaseStatus::billable(), true)) {
                throw ValidationException::withMessages(['invoice' => 'This tenant has moved out, so the bill can\'t be made again. Void it instead.']);
            }

            $old = $this->voids->void($old->id, 'Re-billed: '.trim($reason), $requestedBy);
            $lease = Lease::query()->with('unit')->findOrFail($old->lease_id);
            $issueDate = CarbonImmutable::parse($old->issue_date->toDateString());
            $dueDate = CarbonImmutable::parse($old->due_date->toDateString());

            if ($old->type === InvoiceType::MoveIn) {
                return ($this->moveInInvoice)($lease, $issueDate, $dueDate, $old->id); // sends it itself
            }

            try {
                $new = $this->builder->build($lease, $issueDate, null, $dueDate, $old->id);
            } catch (MissingMeterReading $e) {
                throw ValidationException::withMessages(['invoice' => 'Can\'t re-bill: '.$e->getMessage()]);
            }

            InvoiceIssued::dispatch($new); // after commit, like every bill

            return $new;
        });
    }
}
