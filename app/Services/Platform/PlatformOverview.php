<?php

namespace App\Services\Platform;

use App\Services\Audit\AuditLogService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Platform dashboard: how every landlord account is doing, and what needs attention. */
class PlatformOverview
{
    public function __construct(private AuditLogService $audit) {}

    public function summary(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $monthStart = $now->startOfMonth();

        $accounts = DB::table('accounts')->whereNull('deleted_at')->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');
        $units = DB::table('units')->whereNull('deleted_at')->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');
        $bills = DB::table('invoices')->whereNull('deleted_at')->where('status', '!=', 'void')->where('issue_date', '>=', $monthStart->toDateString());

        $billedAll = DB::table('invoices')->whereNull('deleted_at')->where('status', '!=', 'void')->sum('current_charges');
        $paidAll = DB::table('payments')->where('status', 'confirmed')->sum('amount');
        $opening = DB::table('leases')->whereNull('deleted_at')->sum('opening_balance');

        return [
            'accounts' => [
                'active' => (int) ($accounts['active'] ?? 0),
                'suspended' => (int) ($accounts['suspended'] ?? 0),
                'closed' => (int) ($accounts['closed'] ?? 0),
                'new_30d' => DB::table('accounts')->whereNull('deleted_at')->where('created_at', '>=', $now->subDays(30))->count(),
            ],
            'properties' => DB::table('properties')->whereNull('deleted_at')->count(),
            'units' => [
                'total' => (int) $units->sum(),
                'occupied' => (int) ($units['occupied'] ?? 0),
                'vacant' => (int) ($units['vacant'] ?? 0),
            ],
            'tenants' => DB::table('tenants')->whereNull('deleted_at')->count(),
            'month' => [
                'from' => $monthStart->toDateString(),
                'bills_count' => (clone $bills)->count(),
                'billed' => Money::fromCents(Money::toCents((clone $bills)->sum('current_charges'))),
                'collected' => Money::fromCents(Money::toCents(DB::table('payments')->where('status', 'confirmed')->where('paid_at', '>=', $monthStart)->sum('amount'))),
            ],
            'balance_outstanding' => Money::fromCents(Money::toCents($opening) + Money::toCents($billedAll) - Money::toCents($paidAll)),
            'attention' => [
                'failed_deliveries_24h' => DB::table('notification_deliveries')->where('status', 'failed')->where('updated_at', '>=', $now->subDay())->count(),
                'failed_deliveries' => DB::table('notification_deliveries')->where('status', 'failed')->count(),
                'billing_runs_with_problems_7d' => DB::table('billing_runs')->where('created_at', '>=', $now->subDays(7))
                    ->where(fn ($q) => $q->whereIn('status', ['failed', 'completed_with_errors'])->orWhere('leases_skipped', '>', 0))->count(),
                'whatsapp_disabled' => DB::table('whatsapp_connections')->where('status', '!=', 'active')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'recent_activity' => $this->audit->latest(null, 10),
        ];
    }
}
