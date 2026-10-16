<?php

namespace App\Services\Platform;

use App\Enums\SystemRole;
use App\Models\Account;
use App\Services\Audit\AuditLogService;
use App\Support\Money;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Platform (super admin) view of landlord accounts. Reads across every account (plain table queries, no scope). */
class PlatformAccounts
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * @param  array{search?: string|null, status?: string|null}  $filters
     */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $owners = $this->owners();

        $page = Account::query()
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($search !== '', function ($q) use ($search) {
                $ownerAccounts = $this->ownerQuery()->where(fn ($w) => $w->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%")->orWhere('users.email', 'like', "%{$search}%"))
                    ->pluck('model_has_roles.account_id');
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                    ->orWhereIn('id', $ownerAccounts));
            })
            ->orderBy('name')
            ->paginate($perPage, ['id', 'name', 'slug', 'email', 'phone', 'status', 'created_at']);

        $ids = $page->getCollection()->pluck('id');
        $properties = $this->countBy('properties', $ids);
        $units = $this->countBy('units', $ids);
        $team = DB::table('users')->whereIn('account_id', $ids)->whereNull('deleted_at')->groupBy('account_id')->selectRaw('account_id, COUNT(*) as n')->pluck('n', 'account_id');
        $lastRuns = $this->lastRuns($ids);
        $whatsapp = DB::table('whatsapp_connections')->whereIn('account_id', $ids)->pluck('status', 'account_id');

        $page->setCollection($page->getCollection()->map(fn (Account $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'slug' => $a->slug,
            'email' => $a->email,
            'phone' => $a->phone,
            'status' => $a->status,
            'owner_name' => $owners[$a->id]->name ?? null,
            'owner_phone' => $owners[$a->id]->phone ?? null,
            'properties_count' => (int) ($properties[$a->id] ?? 0),
            'units_count' => (int) ($units[$a->id] ?? 0),
            'team_count' => (int) ($team[$a->id] ?? 0),
            'last_billing_run' => $lastRuns[$a->id] ?? null,
            'whatsapp_status' => $whatsapp[$a->id] ?? null,
            'created_at' => $a->created_at,
        ]));

        return $page;
    }

    /** Everything a platform admin needs about one account. */
    public function detail(int $accountId): array
    {
        $a = Account::query()->findOrFail($accountId);
        $team = DB::table('users')->where('account_id', $a->id)->whereNull('deleted_at')
            ->get(['id', 'name', 'email', 'phone', 'status', 'last_login_at']);
        $ownerIds = $this->ownerQuery()->where('model_has_roles.account_id', $a->id)->pluck('users.id')->all();
        $since = now()->subDays(7);

        $billed = DB::table('invoices')->where('account_id', $a->id)->whereNull('deleted_at')->where('status', '!=', 'void')->sum('current_charges');
        $paid = DB::table('payments')->where('account_id', $a->id)->where('status', 'confirmed')->sum('amount');
        $opening = DB::table('leases')->where('account_id', $a->id)->whereNull('deleted_at')->sum('opening_balance');
        $whatsapp = DB::table('whatsapp_connections')->where('account_id', $a->id)
            ->first(['status', 'display_phone_number', 'verified_name', 'verified_at', 'updated_at']);

        return [
            'id' => $a->id,
            'name' => $a->name,
            'slug' => $a->slug,
            'email' => $a->email,
            'phone' => $a->phone,
            'status' => $a->status,
            'timezone' => $a->timezone,
            'currency' => $a->currency,
            'billing_day' => $a->billing_day,
            'payment_due_days' => $a->payment_due_days,
            'invoice_prefix' => $a->invoice_prefix,
            'created_at' => $a->created_at,
            'counts' => [
                'properties' => (int) $this->countBy('properties', [$a->id])->sum(),
                'units' => (int) $this->countBy('units', [$a->id])->sum(),
                'occupied_units' => DB::table('units')->where('account_id', $a->id)->whereNull('deleted_at')->where('status', 'occupied')->count(),
                'tenants' => DB::table('tenants')->where('account_id', $a->id)->whereNull('deleted_at')->count(),
                'active_leases' => DB::table('leases')->where('account_id', $a->id)->whereNull('deleted_at')->where('status', '!=', 'ended')->count(),
            ],
            /** Everything billed (incl. opening balances) minus everything paid, across all leases. */
            'balance_outstanding' => Money::fromCents(Money::toCents($opening) + Money::toCents($billed) - Money::toCents($paid)),
            'team' => $team->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
                'status' => $u->status, 'last_login_at' => $u->last_login_at, 'is_owner' => in_array($u->id, $ownerIds, true),
            ])->values(),
            'billing_runs' => DB::table('billing_runs')->where('account_id', $a->id)->orderByDesc('id')->limit(5)
                ->get(['id', 'run_date', 'trigger', 'status', 'invoices_created', 'leases_skipped', 'finished_at']),
            'deliveries_7d' => DB::table('notification_deliveries')->where('account_id', $a->id)->where('created_at', '>=', $since)
                ->groupBy('channel', 'status')->selectRaw('channel, status, COUNT(*) as n')->get()
                ->map(fn ($r) => ['channel' => $r->channel, 'status' => $r->status, 'count' => (int) $r->n])->values(),
            'whatsapp' => $whatsapp ? (array) $whatsapp : null,
            'activity' => $this->audit->latest($a->id, 20),
        ];
    }

    private function ownerQuery()
    {
        $ownerRole = DB::table('roles')->where('name', SystemRole::Owner->value)->whereNull('account_id')->value('id');

        return DB::table('model_has_roles')->where('role_id', $ownerRole)->where('model_type', 'user')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->whereNull('users.deleted_at');
    }

    /** First owner per account. */
    private function owners(): Collection
    {
        return $this->ownerQuery()->orderBy('users.id')
            ->get(['model_has_roles.account_id', 'users.name', 'users.phone'])
            ->unique('account_id')->keyBy('account_id');
    }

    private function countBy(string $table, iterable $ids): Collection
    {
        return DB::table($table)->whereIn('account_id', collect($ids))->whereNull('deleted_at')
            ->groupBy('account_id')->selectRaw('account_id, COUNT(*) as n')->pluck('n', 'account_id');
    }

    /** Latest billing run per account. */
    private function lastRuns(Collection $ids): Collection
    {
        $latest = DB::table('billing_runs')->whereIn('account_id', $ids)->groupBy('account_id')->selectRaw('MAX(id) as id');

        return DB::table('billing_runs')->whereIn('id', $latest)
            ->get(['account_id', 'run_date', 'status', 'invoices_created', 'leases_skipped'])
            ->keyBy('account_id')
            ->map(fn ($r) => ['run_date' => $r->run_date, 'status' => $r->status, 'invoices_created' => (int) $r->invoices_created, 'leases_skipped' => (int) $r->leases_skipped]);
    }
}
