<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Money received against a lease. Reduces the lease's running balance; the
 * linked invoice (the bill open when the payment arrived) updates its status.
 * Recorded only through App\Services\Payments\PaymentService.
 */
class Payment extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'lease_id', 'tenant_id', 'invoice_id', 'amount', 'method', 'reference',
        'payer_phone', 'paid_at', 'status', 'recorded_by', 'meta', 'notes', 'receipt_number', 'balance_after',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'paid_at' => 'datetime',
            'meta' => 'array',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function notificationDeliveries(): MorphMany
    {
        return $this->morphMany(NotificationDelivery::class, 'notifiable');
    }

    public function isConfirmed(): bool
    {
        return $this->status === PaymentStatus::Confirmed;
    }

    /** Confirmed payments get a receipt (number + PDF), sent to the tenant. */
    public function hasReceipt(): bool
    {
        return $this->isConfirmed() && $this->receipt_number !== null;
    }
}
