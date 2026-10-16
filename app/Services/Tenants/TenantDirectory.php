<?php

namespace App\Services\Tenants;

use App\Enums\LeaseStatus;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ledger\LeaseLedgerService;
use App\Services\Properties\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Tenant lists and the tenant page for the admin portal (reads only). */
class TenantDirectory
{
    public function __construct(
        private LeaseLedgerService $ledger,
        private Visibility $visibility,
    ) {}

    /**
     * Tenants this login may see (without "properties.view_all": tenants with a lease in the properties
     * they look after), newest first,
     * each with their current lease (unit, property) and its balance.
     */
    public function list(User $user, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $page = $this->visible($user)
            ->when($search, function (Builder $q, string $search) {
                // Every word must match some field ("jane wanj", "7-7", "0712"); works on MySQL and SQLite.
                foreach (preg_split('/\s+/', trim($search)) as $word) {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $word).'%';
                    $q->where(fn (Builder $w) => $w
                        ->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('national_id', 'like', $term)
                        ->orWhereHas('leases.unit', fn (Builder $u) => $u->where('unit_number', 'like', $term)));
                }
            })
            ->with('activeLease.unit.property:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);

        $this->attachBalances($page->getCollection()->pluck('activeLease')->filter());

        return $page;
    }

    /** One tenant with every lease, their latest bills and payments. */
    public function detail(Tenant $tenant): Tenant
    {
        $tenant->load([
            'activeLease.unit.property:id,name',
            'leases' => fn ($q) => $q->with('unit.property:id,name')->orderByDesc('start_date'),
            'invoices' => fn ($q) => $q->with('unit:id,unit_number')->orderByDesc('period_start')->limit(6),
        ]);
        $tenant->setRelation('recentPayments', $tenant->leases->isEmpty()
            ? collect()
            : Payment::query()->where('tenant_id', $tenant->id)->latest('paid_at')->limit(10)->get());

        $this->attachBalances($tenant->leases);

        return $tenant;
    }

    /** @return Builder<Tenant> */
    private function visible(User $user): Builder
    {
        return Tenant::query()->when(
            ! $this->visibility->seesAllProperties($user),
            fn (Builder $q) => $q->whereHas('leases', fn (Builder $l) => $l
                ->whereIn('status', [...LeaseStatus::billable(), LeaseStatus::Ended])
                ->whereHas('unit.property', fn (Builder $p) => $p->where('caretaker_id', $user->id))),
        );
    }

    private function attachBalances(iterable $leases): void
    {
        $balances = $this->ledger->balances($leases);
        foreach ($leases as $lease) {
            $lease->setAttribute('balance', $balances[$lease->id] ?? null);
        }
    }
}
