<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Properties\Visibility;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** The admin bills list: search, filters and totals, limited to the properties the user may see. */
class InvoiceDirectory
{
    /** Status filter values besides single statuses. */
    public const UNPAID = [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue];

    public function __construct(private Visibility $visibility) {}

    /**
     * @param  array{search?: string|null, status?: string|null, property_id?: int|null, month?: string|null}  $filters
     */
    public function list(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($user, $filters)
            ->with(['tenant:id,first_name,last_name,phone,email', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Totals for the same filters. Void bills count but add nothing to the money.
     *
     * @return array{count: int, total_due: string, amount_paid: string, outstanding: string}
     */
    public function summary(User $user, array $filters): array
    {
        $live = $this->query($user, $filters)->where('status', '!=', InvoiceStatus::Void);
        $due = Money::toCents((clone $live)->sum('total_due'));
        $paid = Money::toCents((clone $live)->sum('amount_paid'));

        return [
            'count' => $this->query($user, $filters)->count(),
            'total_due' => Money::fromCents($due),
            'amount_paid' => Money::fromCents($paid),
            'outstanding' => Money::fromCents(max(0, $due - $paid)),
        ];
    }

    private function query(User $user, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        return Invoice::query()
            ->whereHas('unit', fn (Builder $u) => $this->visibility->scopeProperty($u, $user))
            ->when($status === 'unpaid', fn ($q) => $q->whereIn('status', self::UNPAID))
            ->when($status && $status !== 'unpaid', fn ($q) => $q->where('status', $status))
            ->when($filters['property_id'] ?? null, fn ($q, $id) => $q->whereHas('unit', fn ($u) => $u->where('property_id', $id)))
            ->when($filters['month'] ?? null, function ($q, $month) {
                $start = $month.'-01';
                $q->whereBetween('issue_date', [$start, date('Y-m-t', strtotime($start))]);
            })
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('unit', fn ($u) => $u->where('unit_number', 'like', "%{$search}%"))
                ->orWhereHas('tenant', fn ($t) => $t->where('phone', 'like', "%{$search}%")
                    // "Jane Wanjiru": every word matches the first or last name.
                    ->orWhere(function ($names) use ($search) {
                        foreach (preg_split('/\s+/', $search) as $word) {
                            $names->where(fn ($n) => $n->where('first_name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%"));
                        }
                    }))));
    }
}
