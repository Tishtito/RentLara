<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToAccount;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A tenant occupying a unit. Carries the rent for this occupancy and the billing cycle.
 *
 * Arrears are a running balance per lease (confirmed decision #3):
 *   balance = opening_balance + Σ invoice current_charges (not void) − Σ confirmed payments
 * Positive = tenant owes; negative = credit.
 */
class Lease extends Model
{
    use BelongsToAccount, HasFactory, SoftDeletes;

    protected $fillable = [
        'account_id', 'unit_id', 'tenant_id', 'start_date', 'end_date', 'monthly_rent',
        'deposit_amount', 'opening_balance', 'last_billed_on', 'next_billing_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeaseStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'last_billed_on' => 'date',
            'next_billing_date' => 'date',
            'monthly_rent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // First bill falls one cycle after move-in unless set explicitly.
        static::creating(function (Lease $lease) {
            if (! $lease->next_billing_date && $lease->start_date) {
                $lease->next_billing_date = $lease->start_date->copy()->addDays($lease->cycleDays());
            }
        });
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function latestInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->ofMany(
            ['period_start' => 'max'],
            fn ($query) => $query->where('status', '!=', InvoiceStatus::Void),
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Leases the billing job should bill today. */
    public function scopeDueForBilling(Builder $query, CarbonInterface|string|null $today = null): Builder
    {
        $today = Carbon::parse($today ?? now())->toDateString();

        return $query->whereIn('status', LeaseStatus::billable())
            ->whereDate('next_billing_date', '<=', $today);
    }

    public function cycleDays(): int
    {
        return (int) ($this->account?->billing_cycle_days ?: 29);
    }

    /**
     * The period the next bill covers: [day after last bill (or move-in), day before next_billing_date].
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function nextBillingPeriod(): array
    {
        $start = $this->last_billed_on
            ? $this->last_billed_on->copy()->addDay()
            : $this->start_date->copy();

        return [$start, $this->next_billing_date->copy()->subDay()];
    }

    /** Called by the billing job after an invoice is created. */
    public function advanceBillingCycle(CarbonInterface $periodEnd): void
    {
        $this->last_billed_on = $periodEnd->toDateString();
        $this->next_billing_date = $this->next_billing_date->copy()->addDays($this->cycleDays());
        $this->save();
    }

    /** Running balance. Excludes $exceptInvoice so a bill can compute its own arrears line. */
    public function balance(?Invoice $exceptInvoice = null): string
    {
        $charges = $this->invoices()
            ->where('status', '!=', InvoiceStatus::Void)
            ->when($exceptInvoice, fn ($q) => $q->whereKeyNot($exceptInvoice->getKey()))
            ->sum('current_charges');

        $paid = $this->payments()
            ->where('status', PaymentStatus::Confirmed)
            ->sum('amount');

        return Money::fromCents(
            Money::toCents($this->opening_balance) + Money::toCents($charges) - Money::toCents($paid)
        );
    }

    public function isBillable(): bool
    {
        return in_array($this->status?->value, LeaseStatus::billable(), true);
    }
}
