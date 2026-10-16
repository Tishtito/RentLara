<?php

namespace App\Services\Platform;

use App\Models\BillingRun;
use App\Models\Lease;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Billing runs across every landlord account (reads). Running billing stays BillingRunRequested → BillingService. */
class PlatformBilling
{
    public function __construct(private CurrentAccount $context) {}

    /** @param  array{account_id?: int|null, status?: string|null, from?: string|null, to?: string|null, problems?: bool}  $filters */
    public function runs(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->context->withoutScope(fn () => BillingRun::query()
            ->with(['triggeredBy:id,name', 'account:id,name'])
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['problems'] ?? false, fn ($q) => $q->where(fn ($w) => $w->whereIn('status', ['failed', 'completed_with_errors'])->orWhere('leases_skipped', '>', 0)))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('run_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('run_date', '<=', $d))
            ->latest('id')
            ->paginate($perPage));
    }

    /** One run, with each skipped lease's unit and tenant. */
    public function run(int $runId): array
    {
        return $this->context->withoutScope(function () use ($runId) {
            $run = BillingRun::query()->with(['triggeredBy:id,name', 'account:id,name'])->findOrFail($runId);
            $errors = collect($run->errors ?? []);
            $leases = Lease::query()->with(['unit:id,unit_number,property_id', 'unit.property:id,name', 'tenant:id,first_name,last_name'])
                ->whereKey($errors->pluck('lease_id')->filter())->get()->keyBy('id');

            return [
                'run' => $run,
                'skipped' => $errors->map(function (array $e) use ($leases) {
                    $lease = $leases[$e['lease_id'] ?? 0] ?? null;

                    return [
                        'lease_id' => $e['lease_id'] ?? null,
                        'unit' => $lease?->unit?->unit_number,
                        'property' => $lease?->unit?->property?->name,
                        'tenant' => $lease?->tenant ? trim($lease->tenant->first_name.' '.$lease->tenant->last_name) : null,
                        'reason' => $e['reason'] ?? '',
                    ];
                })->values(),
            ];
        });
    }
}
