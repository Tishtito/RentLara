<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToAccount;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The monthly bill for one lease.
 *   current_charges         = Σ items (rent, electricity, water, garbage...)
 *   balance_brought_forward = the lease balance before this bill (the "Arrears" line)
 *   total_due               = current_charges + balance_brought_forward
 */
class Invoice extends Model
{
    use BelongsToAccount, SoftDeletes;

    protected $fillable = [
        'account_id', 'lease_id', 'tenant_id', 'unit_id', 'billing_run_id', 'invoice_number',
        'period_start', 'period_end', 'issue_date', 'due_date', 'current_charges',
        'balance_brought_forward', 'total_due', 'amount_paid', 'status', 'sent_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'current_charges' => 'decimal:2',
            'balance_brought_forward' => 'decimal:2',
            'total_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notificationDeliveries(): MorphMany
    {
        return $this->morphMany(NotificationDelivery::class, 'notifiable');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue,
        ]);
    }

    /** Re-add the lines and arrears. Call after items are written. */
    public function recalculateTotals(): void
    {
        $this->current_charges = Money::sum(...$this->items()->pluck('amount')->all());
        $this->total_due = Money::sum($this->current_charges, $this->balance_brought_forward);
        $this->save();
    }

    /** Update amount_paid and status from confirmed payments linked to this bill. */
    public function refreshPaymentStatus(): void
    {
        if ($this->status === InvoiceStatus::Void || $this->status === InvoiceStatus::Draft) {
            return;
        }

        $this->amount_paid = Money::fromCents(Money::toCents(
            $this->payments()->where('status', PaymentStatus::Confirmed)->sum('amount')
        ));

        $paidCents = Money::toCents($this->amount_paid);
        $dueCents = Money::toCents($this->total_due);

        $this->status = match (true) {
            $paidCents >= $dueCents => InvoiceStatus::Paid,
            $this->due_date->copy()->endOfDay()->isPast() => InvoiceStatus::Overdue,
            $paidCents > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };

        $this->save();
    }

    public function balanceRemaining(): string
    {
        return Money::fromCents(max(0, Money::toCents($this->total_due) - Money::toCents($this->amount_paid)));
    }

    /** Next number for an account, e.g. INV-2026-000124. Call inside the billing transaction. */
    public static function nextNumber(Account $account): string
    {
        $prefix = "{$account->invoice_prefix}-".now()->year.'-';

        $last = static::withTrashed()
            ->forAccount($account)
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
