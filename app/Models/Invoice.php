<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Concerns\BelongsToAccount;
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
        'billing_key', 'voided_at', 'voided_by', 'void_reason', 'type',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'type' => InvoiceType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
            'current_charges' => 'decimal:2',
            'balance_brought_forward' => 'decimal:2',
            'total_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** Who voided the bill (null for live bills). */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
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
}
