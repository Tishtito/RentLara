<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant occupying a unit. Carries the rent for this occupancy and the billing cycle.
 *
 * Arrears are a running balance per lease (confirmed decision #3):
 *   balance = opening_balance + Σ invoice current_charges (not void) − Σ confirmed payments
 * Positive = tenant owes; negative = credit. Computed by App\Services\Ledger\LeaseLedgerService.
 * Created and advanced through App\Services\Leases\LeaseService.
 */
class Lease extends Model
{
    use BelongsToAccount, HasFactory, SoftDeletes;

    protected $fillable = [
        'account_id', 'unit_id', 'tenant_id', 'start_date', 'end_date', 'monthly_rent',
        'deposit_amount', 'deposit_invoiced_at', 'onboarded_on', 'opening_balance', 'last_billed_on', 'next_billing_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeaseStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'last_billed_on' => 'date',
            'next_billing_date' => 'date',
            'deposit_invoiced_at' => 'datetime',
            'onboarded_on' => 'date',
            'monthly_rent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
        ];
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

    public function isBillable(): bool
    {
        return in_array($this->status?->value, LeaseStatus::billable(), true);
    }
}
