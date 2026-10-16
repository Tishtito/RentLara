<?php

namespace App\Services\Dashboard;

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentStatus;
use App\Enums\UnitStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingCalendar;
use App\Services\Ledger\LeaseLedgerService;
use App\Services\Meters\ReadingTasks;
use App\Services\Properties\Visibility;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin dashboard: this billing cycle's money, arrears, occupancy, what needs attention before the
 * next billing run, and the bills coming up. Caretakers see their properties only.
 */
class DashboardService
{
    public function __construct(
        private Visibility $visibility,
        private LeaseLedgerService $ledger,
        private ReadingTasks $readingTasks,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $user, int $accountId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(config('app.timezone'))->startOfDay();
        // This billing month: from the latest billing day (e.g. 5 Nov) up to today.
        $billingDay = (int) (Account::query()->whereKey($accountId)->value('billing_day') ?: BillingCalendar::DEFAULT_BILLING_DAY);
        $from = CarbonImmutable::instance(BillingCalendar::billingDateOnOrBefore($today, $billingDay));
        $cycleDays = BillingCalendar::daysBetween($from, $today) + 1;

        $properties = $this->visibility->properties($user)->orderBy('name')->get(['id', 'name']);
        $propertyIds = $properties->modelKeys();
        $inProperties = fn (Builder $q) => $q->whereHas('unit', fn (Builder $u) => $u->whereIn('property_id', $propertyIds));

        // This billing month: bills issued and payments received since the billing day.
        $bills = Invoice::query()->where($inProperties)->where('status', '!=', InvoiceStatus::Void)
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $today->toDateString())
            ->with('unit:id,property_id')->get(['id', 'unit_id', 'current_charges']);
        $payments = Payment::query()->where('status', PaymentStatus::Confirmed)
            ->whereHas('lease', $inProperties)
            ->whereBetween('paid_at', [$from->startOfDay(), $today->endOfDay()])
            ->with('lease.unit:id,property_id')->get(['id', 'lease_id', 'amount']);

        $billed = $bills->sum(fn ($b) => Money::toCents($b->current_charges));
        $collected = $payments->sum(fn ($p) => Money::toCents($p->amount));

        // Arrears: what tenants owe right now across their running leases.
        $leases = Lease::query()->whereIn('status', LeaseStatus::billable())->where($inProperties)
            ->with(['unit:id,unit_number,property_id', 'unit.property:id,name', 'tenant:id,first_name,last_name'])->get();
        $balances = $this->ledger->balances($leases);
        $owing = collect($balances)->map(fn ($b) => Money::toCents($b))->filter(fn ($c) => $c > 0);

        // Occupancy.
        $units = Unit::query()->whereIn('property_id', $propertyIds)->get(['id', 'status']);

        // Needs attention.
        $tasks = $this->readingTasks->due($user, days: 7, today: $today)->keyBy('lease_id');
        $missing = $tasks->filter(fn ($t) => ! $t['done'] && $t['bills_on'] <= $today->addDays(2)->toDateString());
        $overdue = Invoice::query()->where($inProperties)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue])
            ->whereDate('due_date', '<', $today->toDateString())
            ->get(['id', 'total_due', 'amount_paid']);
        $failed = NotificationDelivery::query()->where('status', 'failed')
            ->where('created_at', '>=', $from->startOfDay())
            ->whereHasMorph('notifiable', [Invoice::class], $inProperties)
            ->count();

        // Bills due in the next 7 days.
        $upcoming = $leases->filter(fn (Lease $l) => $l->next_billing_date->lte($today->addDays(7)))
            ->sortBy('next_billing_date')
            ->map(fn (Lease $l) => [
                'lease_id' => $l->id,
                'unit_id' => $l->unit_id,
                'unit_number' => $l->unit->unit_number,
                'property_name' => $l->unit->property->name,
                'tenant_id' => $l->tenant_id,
                'tenant_name' => trim("{$l->tenant->first_name} {$l->tenant->last_name}"),
                'bills_on' => $l->next_billing_date->toDateString(),
                'monthly_rent' => $l->monthly_rent,
                /** ready | missing | no_meters */
                'readings' => ! $tasks->has($l->id) ? 'no_meters' : ($tasks[$l->id]['done'] ? 'ready' : 'missing'),
            ])->values();

        return [
            'today' => $today->toDateString(),
            'cycle' => [
                'from' => $from->toDateString(),
                'to' => $today->toDateString(),
                'days' => $cycleDays,
                'billed' => Money::fromCents($billed),
                'bills_count' => $bills->count(),
                'collected' => Money::fromCents($collected),
                'payments_count' => $payments->count(),
                'collection_rate' => $billed > 0 ? (int) round($collected * 100 / $billed) : null,
            ],
            'arrears' => ['total' => Money::fromCents($owing->sum()), 'leases' => $owing->count()],
            'occupancy' => [
                'units' => $units->count(),
                'occupied' => $units->where('status', UnitStatus::Occupied)->count(),
                'vacant' => $units->where('status', UnitStatus::Vacant)->count(),
                'properties' => count($propertyIds),
            ],
            'attention' => [
                'missing_readings' => [
                    'count' => $missing->count(),
                    'units' => $missing->pluck('unit_number')->values(),
                ],
                'overdue_bills' => [
                    'count' => $overdue->count(),
                    'amount' => Money::fromCents($overdue->sum(fn ($i) => max(0, Money::toCents($i->total_due) - Money::toCents($i->amount_paid)))),
                ],
                'failed_messages' => $failed,
            ],
            'upcoming' => $upcoming,
            'by_property' => $properties->map(fn ($p) => [
                'property_id' => $p->id,
                'name' => $p->name,
                'billed' => Money::fromCents($bills->filter(fn ($b) => $b->unit->property_id === $p->id)->sum(fn ($b) => Money::toCents($b->current_charges))),
                'collected' => Money::fromCents($payments->filter(fn ($x) => $x->lease->unit->property_id === $p->id)->sum(fn ($x) => Money::toCents($x->amount))),
            ])->values(),
        ];
    }
}
