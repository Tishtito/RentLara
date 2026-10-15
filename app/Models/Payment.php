<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Money received against a lease. Reduces the lease's running balance; the
 * linked invoice (the bill open when the payment arrived) updates its status.
 */
class Payment extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'lease_id', 'tenant_id', 'invoice_id', 'amount', 'method', 'reference',
        'payer_phone', 'paid_at', 'status', 'recorded_by', 'meta', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Attach to the latest outstanding bill if none was given.
        static::creating(function (Payment $payment) {
            if (! $payment->invoice_id && $payment->lease_id) {
                $payment->invoice_id = Invoice::query()
                    ->where('lease_id', $payment->lease_id)
                    ->where('status', '!=', InvoiceStatus::Void)
                    ->orderByDesc('period_start')
                    ->value('id');
            }

            if (! $payment->tenant_id && $payment->lease_id) {
                $payment->tenant_id = Lease::query()->whereKey($payment->lease_id)->value('tenant_id');
            }
        });

        // Keep the bill's paid amount and status in step with its payments.
        static::saved(function (Payment $payment) {
            $payment->invoice?->refreshPaymentStatus();

            $originalInvoiceId = $payment->getOriginal('invoice_id');
            if ($payment->wasChanged('invoice_id') && $originalInvoiceId) {
                Invoice::query()->find($originalInvoiceId)?->refreshPaymentStatus();
            }
        });
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
}
