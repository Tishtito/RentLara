<?php

namespace App\Services\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Ledger\LeaseLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * What the logged-in tenant sees in the portal: their home(s), bills and payments. Reads only, and
 * always limited to this tenant record (one landlord, decision #1) on top of the account scope.
 * Draft and void bills are never shown; only confirmed payments are.
 */
class TenantStatement
{
    /** Bill statuses a tenant sees. */
    private const VISIBLE = [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid, InvoiceStatus::Overdue];

    public function __construct(private LeaseLedgerService $ledger) {}

    /**
     * The leases to show on the home screen: every current one (active or under notice), else the
     * most recent (someone who moved out may still owe), each with its balance and latest bill.
     *
     * @return Collection<int, array{lease: Lease, balance: string, latest_invoice: Invoice|null}>
     */
    public function homes(Tenant $tenant): Collection
    {
        $leases = $tenant->leases()
            ->with('unit:id,unit_number,property_id', 'unit.property:id,name')
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get();

        $current = $leases->filter(fn (Lease $lease) => in_array($lease->status->value, LeaseStatus::billable(), true));
        $shown = ($current->isNotEmpty() ? $current : $leases->take(1))->values();

        $balances = $this->ledger->balances($shown);
        $latest = $this->bills($tenant)
            ->whereIn('lease_id', $shown->pluck('id'))
            ->with('items')
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->get()
            ->unique('lease_id')
            ->keyBy('lease_id');

        return $shown->map(fn (Lease $lease) => [
            'lease' => $lease,
            'balance' => $balances[$lease->id],
            'latest_invoice' => $latest[$lease->id] ?? null,
        ]);
    }

    /** @return Collection<int, Payment> newest first */
    public function recentPayments(Tenant $tenant, int $limit = 5): Collection
    {
        return $this->confirmedPayments($tenant)->limit($limit)->get();
    }

    public function invoices(Tenant $tenant, int $perPage = 15): LengthAwarePaginator
    {
        return $this->bills($tenant)
            ->with('unit:id,unit_number,property_id', 'unit.property:id,name')
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @throws ModelNotFoundException not one of this tenant's bills (or a draft / void one) */
    public function invoice(Tenant $tenant, int $id): Invoice
    {
        return $this->bills($tenant)
            ->with(['items', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->findOrFail($id);
    }

    public function payments(Tenant $tenant, int $perPage = 20): LengthAwarePaginator
    {
        return $this->confirmedPayments($tenant)->paginate($perPage);
    }

    /** @return Builder<Invoice> */
    private function bills(Tenant $tenant): Builder
    {
        return Invoice::query()->where('tenant_id', $tenant->id)->whereIn('status', self::VISIBLE);
    }

    /** @return Builder<Payment> */
    private function confirmedPayments(Tenant $tenant): Builder
    {
        return Payment::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', PaymentStatus::Confirmed)
            ->with('invoice:id,invoice_number')
            ->orderByDesc('paid_at')->orderByDesc('id');
    }
}
